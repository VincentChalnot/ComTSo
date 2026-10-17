<?php

namespace App\Legacy\Import;

use App\Legacy\LegacyDate;
use App\Legacy\Model\LegacyPhoto;
use App\Legacy\Source\OldPhpSource;
use Doctrine\DBAL\Connection;

/**
 * Photos of the old PHP forum (`photos` rows + `photos/{id}.jpg` files). Photos the live site already has
 * (matched by original file name, or by identical file content) are left alone; a missing photo is imported only
 * if its file really exists in the old code tree, otherwise it is reported as lost.
 */
final class PhotoImporter
{
    private const SOURCE = 'oldphp';

    private int $fakeId = -1000000;

    public function __construct(
        private readonly Connection $live,
        private readonly OldPhpSource $oldPhp,
        private readonly UserMapper $users,
        private readonly ImportReport $report,
        private readonly ReimportOptions $options,
        private readonly PhotoLinks $photoLinks,
    ) {
    }

    public function import(): void
    {
        $r = $this->report;
        $photos = $this->oldPhp->photos();
        $liveNames = array_flip($this->live->fetchFirstColumn('SELECT original_filename FROM ctso_photo'));
        $liveRefs = array_flip($this->live->fetchFirstColumn('SELECT legacy_ref FROM ctso_photo WHERE legacy_ref IS NOT NULL'));
        $hashes = null;

        foreach ($photos as $photo) {
            $r->total(self::SOURCE, 'photos');
            if (isset($liveNames[$photo->legacyFilename()]) || isset($liveRefs[$photo->ref()])) {
                $r->present(self::SOURCE, 'photos');
                continue;
            }

            $describe = sprintf('photo #%d "%s" (album %d, user %d, %s)', $photo->id, $photo->title, $photo->albumId, $photo->authorId, $photo->createdAt->format('Y-m-d'));
            if (null === $this->options->oldPhpDir) {
                $r->skipped(self::SOURCE, 'photos', 'old forum photo directory not mounted (--oldphp-dir)');
                $r->detail('oldphp photos not imported', $describe);
                continue;
            }
            $file = $this->options->oldPhpDir.'/photos/'.$photo->legacyFilename();
            if (!is_file($file)) {
                $r->skipped(self::SOURCE, 'photos', 'file does not exist in the old code tree (lost)');
                $r->detail('oldphp photos not imported', $describe.' — file missing');
                continue;
            }
            $hashes ??= $this->liveFileHashes();
            if (isset($hashes[md5_file($file)])) {
                $r->present(self::SOURCE, 'photos');
                $r->detail('oldphp photos already stored under another name', $describe);
                continue;
            }

            $this->importFile($photo, $file, $describe);
        }
    }

    /** @return array<string, true> md5 of every original already stored */
    private function liveFileHashes(): array
    {
        $hashes = [];
        foreach (glob($this->options->photosDirectory.'/*') ?: [] as $path) {
            if (is_file($path)) {
                $hashes[md5_file($path)] = true;
            }
        }

        return $hashes;
    }

    private function importFile(LegacyPhoto $photo, string $file, string $describe): void
    {
        $size = @getimagesize($file);
        if (false === $size) {
            $this->report->skipped(self::SOURCE, 'photos', 'not a readable image');

            return;
        }
        $extension = 'image/png' === $size['mime'] ? 'png' : ('image/gif' === $size['mime'] ? 'gif' : 'jpeg');
        $authorId = $this->users->resolve(self::SOURCE, $photo->authorId);
        $title = mb_substr($photo->title, 0, 128);
        $created = LegacyDate::sql($photo->createdAt);
        $modified = LegacyDate::sql(LegacyDate::fromUnix((int) filemtime($file)));

        $topicId = $this->albumTopic($photo);
        if ($this->options->dryRun) {
            $this->report->imported(self::SOURCE, 'photos');
            $this->photoLinks->register($photo->id, --$this->fakeId);
            $this->report->detail('oldphp photos imported', $describe.($topicId ? " → album topic #$topicId" : ' (no album topic found)'));

            return;
        }

        do {
            $filename = uniqid().'.'.$extension;
        } while (is_file($this->options->photosDirectory.'/'.$filename));
        if (!is_dir($this->options->photosDirectory)) {
            mkdir($this->options->photosDirectory, 0775, true);
        }
        copy($file, $this->options->photosDirectory.'/'.$filename);

        $this->live->insert('ctso_photo', [
            'filename' => $filename,
            'original_filename' => $photo->legacyFilename(),
            'file_size' => filesize($file),
            'file_type' => $size['mime'],
            'file_modified_at' => $modified,
            'taken_at' => $modified,
            'width' => $size[0],
            'height' => $size[1],
            'title' => '' !== $title ? $title : null,
            'created_at' => $created,
            'updated_at' => $created,
            'author_id' => $authorId,
            'legacy_ref' => $photo->ref(),
        ]);
        $photoId = (int) $this->live->lastInsertId();
        $this->photoLinks->register($photo->id, $photoId);
        if (null !== $topicId) {
            $order = (int) $this->live->fetchOne('SELECT COALESCE(MAX(ord), -1) + 1 FROM ctso_photo_topic WHERE topic_id = ?', [$topicId]);
            $this->live->insert('ctso_photo_topic', [
                'ord' => $order, 'created_at' => $created, 'updated_at' => $created,
                'topic_id' => $topicId, 'photo_id' => $photoId, 'author_id' => $authorId,
            ]);
        }
        $this->report->imported(self::SOURCE, 'photos');
        $this->report->detail('oldphp photos imported', $describe.($topicId ? " → album topic #$topicId" : ' (no album topic found: photo stored without album)').' as '.$filename);
    }

    /** Live album topic of a photo: the topic its album siblings are attached to. */
    private function albumTopic(LegacyPhoto $photo): ?int
    {
        $names = array_map(static fn (int $id): string => $id.'.jpg', $this->oldPhp->albumSiblings($photo->albumId, $photo->id));
        if (!$names) {
            return null;
        }
        $in = implode(',', array_fill(0, \count($names), '?'));
        $topic = $this->live->fetchOne(
            "SELECT pt.topic_id FROM ctso_photo p JOIN ctso_photo_topic pt ON pt.photo_id = p.id WHERE p.original_filename IN ($in) GROUP BY pt.topic_id ORDER BY COUNT(*) DESC LIMIT 1",
            $names,
        );

        return false === $topic ? null : (int) $topic;
    }
}
