<?php

namespace App\Legacy\Import;

use App\Legacy\LegacyDate;
use App\Legacy\Model\LegacyAttachment;
use App\Legacy\Text\TextRepair;
use App\Photo\PhotoPipeline;
use Doctrine\DBAL\Connection;

/**
 * Files attached to phpBB 3 posts: each readable image becomes a photo (through the photo pipeline) attached to the live message
 * that holds the post (`ctso_comment_photo`), and the `[attachment=N]name[/attachment]` marker the previous migration left in the text
 * disappears. Files that are missing or cannot be decoded are reported one by one and their marker becomes "[pièce jointe perdue : name]".
 *
 * The files were damaged by an FTP transfer in text mode: every CR+LF pair lost its CR, so most are a few bytes shorter than phpBB recorded.
 * The one repair that is certain is the PNG signature (89 50 4E 47 0D 0A 1A 0A); other damage is only detected (size differs from the record,
 * decoder warnings) and reported, the image being imported as it decodes.
 */
final class AttachmentImporter
{
    private const SOURCE = 'phpbb3';
    private const IMAGE_TYPES = [\IMAGETYPE_JPEG, \IMAGETYPE_PNG, \IMAGETYPE_GIF, \IMAGETYPE_WEBP];

    public function __construct(
        private readonly Connection $live,
        private readonly UserMapper $users,
        private readonly ImportReport $report,
        private readonly ReimportOptions $options,
        private readonly PhotoPipeline $pipeline,
    ) {
    }

    /**
     * @param list<LegacyAttachment> $attachments
     * @param array<string, int>     $postComments phpBB post ref ("phpbb3:post:12") => live comment id
     */
    public function import(array $attachments, array $postComments): void
    {
        $r = $this->report;
        $existing = array_flip($this->live->fetchFirstColumn('SELECT legacy_ref FROM ctso_photo WHERE legacy_ref LIKE ?', ['phpbb3:attachment:%']));
        /** @var array<int, array<string, bool>> $outcome live comment id => [real file name => imported?] */
        $outcome = [];
        $order = [];
        /** @var array<int, string> $refs live comment id => phpBB post ref */
        $refs = [];

        foreach ($attachments as $a) {
            $r->total(self::SOURCE, 'attachments');
            $describe = sprintf('#%d "%s" (%d bytes, post %d, %s)', $a->id, $a->realFilename, $a->size, $a->postId, $a->createdAt->format('Y-m-d'));
            $commentId = $postComments['phpbb3:post:'.$a->postId] ?? null;
            if (isset($existing[$a->ref()])) {
                $r->present(self::SOURCE, 'attachments');
                if (null !== $commentId) {
                    $outcome[$commentId][$a->realFilename] = true;
                }
                continue;
            }
            if (null === $commentId) {
                $r->skipped(self::SOURCE, 'attachments', 0 === $a->postId ? 'orphan (never attached to a post)' : 'its post is not in the live database');
                $r->detail('phpbb3 attachments not imported', $describe.' — no live message');
                continue;
            }
            if (null === $this->options->phpbb3Dir) {
                $r->skipped(self::SOURCE, 'attachments', 'phpBB 3 directory not mounted (--phpbb3-dir)');
                continue;
            }

            $refs[$commentId] = 'phpbb3:post:'.$a->postId;
            $outcome[$commentId][$a->realFilename] = $outcome[$commentId][$a->realFilename] ?? false;
            $file = $this->options->phpbb3Dir.'/phpBB3/files/'.$a->physicalFilename;
            if (!is_file($file)) {
                $r->skipped(self::SOURCE, 'attachments', 'file does not exist in the phpBB 3 tree (lost)');
                $r->detail('phpbb3 attachments not imported', $describe.' — file missing: '.$file);
                continue;
            }

            [$path, $note] = $this->usableFile($file, $a);
            $failure = $this->refusal($path);
            if (null !== $failure) {
                $r->skipped(self::SOURCE, 'attachments', 'damaged file, not usable');
                $r->detail('phpbb3 attachments not imported', $describe.' — '.$failure.($note ? ' ('.$note.')' : '').' — '.$file);
                $this->cleanup($path, $file);
                continue;
            }

            if ($this->options->dryRun) {
                $r->imported(self::SOURCE, 'attachments');
                $outcome[$commentId][$a->realFilename] = true;
                $r->detail('phpbb3 attachments imported', $describe.($note ? ' — '.$note : ''));
                $this->cleanup($path, $file);
                continue;
            }

            $stored = $this->pipeline->ingest($path, $this->options->photosDirectory);
            $this->cleanup($path, $file);
            $created = LegacyDate::sql($a->createdAt);
            $authorId = $this->users->resolve(self::SOURCE, $a->posterId);
            $this->live->insert('ctso_photo', [
                'filename' => $stored->filename,
                'original_filename' => mb_substr($a->realFilename, 0, 128),
                'file_size' => $stored->fileSize,
                'file_type' => $stored->mimeType,
                'file_modified_at' => $created,
                'width' => $stored->width,
                'height' => $stored->height,
                'title' => '' !== $a->comment ? mb_substr($a->comment, 0, 128) : null,
                'created_at' => $created,
                'updated_at' => $created,
                'author_id' => $authorId,
                'legacy_ref' => $a->ref(),
            ] + $stored->metadata->toColumns());
            $photoId = (int) $this->live->lastInsertId();
            $order[$commentId] = ($order[$commentId] ?? (int) $this->live->fetchOne('SELECT COALESCE(MAX(ord), -1) + 1 FROM ctso_comment_photo WHERE comment_id = ?', [$commentId]));
            $this->live->insert('ctso_comment_photo', ['comment_id' => $commentId, 'photo_id' => $photoId, 'ord' => $order[$commentId]++, 'author_id' => $authorId, 'created_at' => $created]);
            $outcome[$commentId][$a->realFilename] = true;
            $r->imported(self::SOURCE, 'attachments');
            $r->detail('phpbb3 attachments imported', $describe.($note ? ' — '.$note : '').' → comment #'.$commentId.', photo #'.$photoId.' ('.$stored->filename.')');
        }

        if (!$this->options->dryRun) {
            foreach ($outcome as $commentId => $files) {
                $this->rewriteMarkers($commentId, $files);
                // Once its marker is gone the live text no longer matches the phpBB post: remember the link, so that a second run
                // never takes the post for a missing one and inserts it again.
                if (isset($refs[$commentId]) && !$this->live->fetchOne('SELECT 1 FROM ctso_comment WHERE legacy_ref = ?', [$refs[$commentId]])) {
                    $this->live->executeStatement('UPDATE ctso_comment SET legacy_ref = ? WHERE id = ? AND legacy_ref IS NULL', [$refs[$commentId], $commentId]);
                }
            }
        }
    }

    /**
     * Where the file can be read as an image: itself, or a repaired temporary copy. Second value: remark for the report.
     *
     * @return array{string, string|null}
     */
    private function usableFile(string $file, LegacyAttachment $a): array
    {
        $size = (int) filesize($file);
        $lost = $a->size - $size;
        $note = $lost > 0 ? sprintf('%d byte(s) shorter than phpBB recorded (CR lost in transfer)', $lost) : null;

        $head = (string) file_get_contents($file, false, null, 0, 7);
        if (str_starts_with($head, "\x89PNG\x0A\x1A\x0A")) {
            $bytes = "\x89PNG\x0D\x0A\x1A\x0A".substr((string) file_get_contents($file), 7);
            $temp = tempnam(sys_get_temp_dir(), 'att');
            file_put_contents($temp, $bytes);

            return [$temp, trim(($note ? $note.'; ' : '').'PNG signature restored')];
        }

        return [$file, $note];
    }

    /** French/English reason why the file cannot be imported, null when the pipeline can decode it. */
    private function refusal(string $path): ?string
    {
        try {
            [, , $type] = $this->pipeline->inspect($path);
        } catch (\InvalidArgumentException) {
            return 'not a readable image (broken header)';
        }
        if (!\in_array($type, self::IMAGE_TYPES, true)) {
            return 'not an image';
        }
        set_error_handler(static fn (): bool => true);
        try {
            $image = match ($type) {
                \IMAGETYPE_JPEG => imagecreatefromjpeg($path),
                \IMAGETYPE_PNG => imagecreatefrompng($path),
                \IMAGETYPE_GIF => imagecreatefromgif($path),
                default => imagecreatefromwebp($path),
            };
        } finally {
            restore_error_handler();
        }

        return false === $image ? 'the image cannot be decoded (corrupted data)' : null;
    }

    private function cleanup(string $path, string $original): void
    {
        if ($path !== $original) {
            @unlink($path);
        }
    }

    /**
     * Imported attachments lose their marker (the photo is shown under the message); lost ones keep a visible note.
     *
     * @param array<string, bool> $files real file name => imported
     */
    private function rewriteMarkers(int $commentId, array $files): void
    {
        $content = $this->live->fetchOne('SELECT content FROM ctso_comment WHERE id = ?', [$commentId]);
        if (!\is_string($content)) {
            return;
        }
        $rewrite = static function (array $m) use ($files): string {
            $name = TextRepair::decodeEntities(html_entity_decode($m[1], \ENT_QUOTES | \ENT_HTML5));
            if (!\array_key_exists($name, $files)) {
                return $m[0];
            }

            return $files[$name] ? '' : '<em>[pièce jointe perdue : '.htmlspecialchars($name, \ENT_QUOTES | \ENT_SUBSTITUTE).']</em>';
        };
        $new = preg_replace_callback('~\[attachment=\d+\]([^\[]*?)\[/attachment\]~u', $rewrite, $content) ?? $content;
        $new = preg_replace_callback('~<em>\[pièce jointe : ([^\]]*)\]</em>~u', $rewrite, $new) ?? $new;
        if ($new !== $content) {
            $this->live->update('ctso_comment', ['content' => $new], ['id' => $commentId]);
        }
    }
}
