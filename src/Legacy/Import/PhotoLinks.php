<?php

namespace App\Legacy\Import;

use Doctrine\DBAL\Connection;

/**
 * Posts of the old PHP forum embed photos through the old viewer (`image.php?photo=610.jpg`,
 * `image.php?thumb=610.jpg`). The photo files were stored in the new database as `ctso_photo.original_filename`
 * "610.jpg": the links are rewritten to the new photo routes (`/photos/{id}`, `/photos/{id}/thumb`).
 */
final class PhotoLinks
{
    /** @var array<int, int> old photo id => live photo id */
    private array $map = [];

    public function __construct(Connection $live)
    {
        foreach ($live->fetchAllAssociative("SELECT id, original_filename FROM ctso_photo WHERE original_filename REGEXP '^[0-9]+\\\\.jpg$'") as $r) {
            $this->map[(int) $r['original_filename']] = (int) $r['id'];
        }
    }

    public function register(int $oldId, int $liveId): void
    {
        $this->map[$oldId] = $liveId;
    }

    public function rewrite(string $html): string
    {
        if (!str_contains($html, 'image.php')) {
            return $html;
        }

        return preg_replace_callback('~\b(href|src)=(["\'])image\.php\?(photo|thumb)=(\d+)(?:\.jpg)?\2~i', function (array $m): string {
            $id = $this->map[(int) $m[4]] ?? null;
            if (null === $id) {
                return ''; // the photo no longer exists anywhere: the attribute is dropped
            }

            return sprintf('%s=%s/photos/%d%s%s', $m[1], $m[2], $id, 'thumb' === strtolower($m[3]) ? '/thumb' : '', $m[2]);
        }, $html) ?? $html;
    }
}
