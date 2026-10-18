<?php

namespace App\Legacy\Import;

use App\Legacy\LegacyDate;
use App\Legacy\Model\LegacyPhoto;
use App\Legacy\Source\OldPhpSource;
use App\Photo\PhotoPipeline;
use Doctrine\DBAL\Connection;

/**
 * Photos of the old PHP forum (`photos` rows + `photos/{id}.jpg` files). Photos the live site already has
 * (matched by original file name) are left alone; a missing photo is imported only if its file really exists in the
 * old code tree, otherwise it is reported as lost. Imported files go through the photo pipeline like any upload
 * (EXIF read, orientation applied, at most 10 MP, stored as WebP): the stored copy is not byte-identical to the old file.
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
        private readonly PhotoPipeline $pipeline,
    ) {
    }

    public function import(): void
    {
        $r = $this->report;
        $photos = $this->oldPhp->photos();
        $liveNames = array_flip($this->live->fetchFirstColumn('SELECT original_filename FROM ctso_photo'));
        $liveRefs = array_flip($this->live->fetchFirstColumn('SELECT legacy_ref FROM ctso_photo WHERE legacy_ref IS NOT NULL'));

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
            $this->importFile($photo, $file, $describe);
        }
    }

    private function importFile(LegacyPhoto $photo, string $file, string $describe): void
    {
        try {
            $this->pipeline->inspect($file);
        } catch (\InvalidArgumentException $e) {
            $this->report->skipped(self::SOURCE, 'photos', 'not an acceptable image: '.$e->getMessage());

            return;
        }
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

        try {
            $stored = $this->pipeline->ingest($file, $this->options->photosDirectory);
        } catch (\InvalidArgumentException $e) {
            $this->report->skipped(self::SOURCE, 'photos', 'rejected by the photo pipeline: '.$e->getMessage());

            return;
        }

        $this->live->insert('ctso_photo', [
            'filename' => $stored->filename,
            'original_filename' => $photo->legacyFilename(),
            'file_size' => $stored->fileSize,
            'file_type' => $stored->mimeType,
            'file_modified_at' => $modified,
            'width' => $stored->width,
            'height' => $stored->height,
            'title' => '' !== $title ? $title : null,
            'created_at' => $created,
            'updated_at' => $created,
            'author_id' => $authorId,
            'legacy_ref' => $photo->ref(),
        ] + $stored->metadata->toColumns());
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
        $this->report->detail('oldphp photos imported', $describe.($topicId ? " → album topic #$topicId" : ' (no album topic found: photo stored without album)').' as '.$stored->filename);
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
