<?php

namespace App\Command;

use App\Photo\PhotoReprocessor;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Brings the photos already stored (restored legacy library, older uploads) up to the current photo pipeline: EXIF read into
 * the database, orientation applied, at most 10 megapixels, WebP. Idempotent: photos that are already WebP within the size
 * caps (and animated GIFs) are skipped, so an interrupted run is simply started again.
 */
#[AsCommand(name: 'app:photos:reprocess', description: 'Re-runs the photo pipeline (EXIF, orientation, 10 MP, WebP) on the stored photos')]
class PhotosReprocessCommand
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PhotoReprocessor $reprocessor,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Only report what would be processed')] bool $dryRun = false,
        #[Option(description: 'Stop after N processed photos')] ?int $limit = null,
    ): int {
        $rows = $this->connection->fetchAllAssociative('SELECT id, filename, exif, taken_at FROM ctso_photo ORDER BY id');
        $counts = [PhotoReprocessor::UPDATED => 0, PhotoReprocessor::UP_TO_DATE => 0, PhotoReprocessor::MISSING => 0, PhotoReprocessor::REJECTED => 0];

        $io->progressStart(\count($rows));
        foreach ($rows as $row) {
            $io->progressAdvance();
            if (null !== $limit && $counts[PhotoReprocessor::UPDATED] >= $limit) {
                break;
            }
            if ($dryRun) {
                $counts[$this->reprocessor->needsProcessing($row['filename']) ? PhotoReprocessor::UPDATED : (is_file($this->path($row)) ? PhotoReprocessor::UP_TO_DATE : PhotoReprocessor::MISSING)]++;
                continue;
            }

            $error = null;
            $status = $this->reprocessor->reprocess($row, error: $error);
            ++$counts[$status];
            if (PhotoReprocessor::REJECTED === $status) {
                $io->warning(sprintf('Photo #%d (%s) left untouched: %s', $row['id'], $row['filename'], $error));
            }
        }
        $io->progressFinish();

        $io->definitionList(
            [$dryRun ? 'Would be processed' : 'Processed' => $counts[PhotoReprocessor::UPDATED]],
            ['Already up to date' => $counts[PhotoReprocessor::UP_TO_DATE]],
            ['File missing' => $counts[PhotoReprocessor::MISSING]],
            ['Rejected' => $counts[PhotoReprocessor::REJECTED]],
        );

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $row */
    private function path(array $row): string
    {
        return $this->reprocessor->pathOf($row['filename']);
    }
}
