<?php

namespace App\Command;

use App\Content\ContentSanitizer;
use App\Entity\Comment;
use App\Entity\Forum;
use App\Entity\Message;
use App\Entity\Topic;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the stored HTML through ContentSanitizer again (after the allow-list changed, or after data that did
 * not sanitize). Idempotent: a second run changes nothing.
 *
 * Only the columns that hold HTML are processed. Plain-text columns (chat messages, quotes, photo descriptions,
 * user address/activities) are deliberately left alone: sanitizing them would turn "a & b" into "a &amp; b".
 *
 * The rows are read and written in batches with plain SQL (no entity hydration, `updated_at` untouched).
 */
#[AsCommand(name: 'app:content:sanitize', description: 'Re-sanitizes the stored HTML of comments, topics, messages, forums and signatures')]
class ContentSanitizeCommand
{
    /** @var list<array{class: class-string, field: string}> */
    private const COLUMNS = [
        ['class' => Comment::class, 'field' => 'content'],
        ['class' => Topic::class, 'field' => 'content'],
        ['class' => Message::class, 'field' => 'content'],
        ['class' => User::class, 'field' => 'signature'],
        ['class' => Forum::class, 'field' => 'content'],
    ];

    private const CONTEXT = 60;

    private readonly Connection $connection;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContentSanitizer $sanitizer,
    ) {
        $this->connection = $em->getConnection();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Only count the rows that would change and show a few differences, write nothing')] bool $dryRun = false,
        #[Option(description: 'Rows read per batch')] int $batchSize = 500,
        #[Option(description: 'Differences displayed per column in a dry run')] int $samples = 3,
    ): int {
        if ($batchSize < 1) {
            $io->error('--batch-size must be at least 1.');

            return Command::INVALID;
        }

        $io->title($dryRun ? 'Content sanitizing (dry run, nothing is written)' : 'Content sanitizing');

        $rows = [];
        $totalChanged = 0;
        foreach (self::COLUMNS as ['class' => $class, 'field' => $field]) {
            $result = $this->process($io, $class, $field, $dryRun, $batchSize, $samples);
            $totalChanged += $result['changed'];
            $rows[] = [$result['label'], $result['scanned'], $result['changed'], $result['emptied']];
        }

        $io->table(['Column', 'Rows scanned', $dryRun ? 'Would change' : 'Changed', 'Left empty'], $rows);
        if ($dryRun) {
            $io->note(sprintf('%d row(s) would change. Run again without --dry-run to apply.', $totalChanged));
        } else {
            $io->success(sprintf('%d row(s) updated.', $totalChanged));
        }

        return Command::SUCCESS;
    }

    /**
     * @param class-string $class
     *
     * @return array{label: string, scanned: int, changed: int, emptied: int}
     */
    private function process(SymfonyStyle $io, string $class, string $field, bool $dryRun, int $batchSize, int $samples): array
    {
        $metadata = $this->em->getClassMetadata($class);
        $table = $this->connection->quoteSingleIdentifier($metadata->getTableName());
        $column = $this->connection->quoteSingleIdentifier($metadata->getColumnName($field));
        $idColumn = $this->connection->quoteSingleIdentifier($metadata->getSingleIdentifierColumnName());
        $label = $metadata->getTableName().'.'.$metadata->getColumnName($field);

        $scanned = $changed = $emptied = $shown = 0;
        $lastId = ''; // ids may be strings (forum slugs) as well as integers: page on the raw value
        $query = sprintf(
            "SELECT %1\$s AS id, %2\$s AS html FROM %3\$s WHERE %1\$s > ? AND %2\$s IS NOT NULL AND %2\$s <> '' ORDER BY %1\$s LIMIT %4\$d",
            $idColumn,
            $column,
            $table,
            $batchSize,
        );

        while ([] !== $batch = $this->connection->fetchAllAssociative($query, [$lastId])) {
            $updates = [];
            foreach ($batch as $row) {
                ++$scanned;
                $lastId = $row['id'];
                $clean = $this->sanitizer->sanitize($row['html']);
                if ($clean === $row['html']) {
                    continue;
                }

                ++$changed;
                if ('' === $clean) {
                    ++$emptied;
                }
                $updates[$lastId] = $clean;
                if ($dryRun && $shown < $samples) {
                    ++$shown;
                    $this->showDifference($io, $label, $lastId, $row['html'], $clean);
                }
            }

            if (!$dryRun && $updates) {
                $this->connection->transactional(function () use ($table, $column, $idColumn, $updates): void {
                    foreach ($updates as $id => $clean) {
                        $this->connection->executeStatement(sprintf('UPDATE %s SET %s = ? WHERE %s = ?', $table, $column, $idColumn), [$clean, $id]);
                    }
                });
            }
        }

        return ['label' => $label, 'scanned' => $scanned, 'changed' => $changed, 'emptied' => $emptied];
    }

    private function showDifference(SymfonyStyle $io, string $label, int|string $id, string $before, string $after): void
    {
        $limit = min(\strlen($before), \strlen($after));
        $offset = 0;
        while ($offset < $limit && $before[$offset] === $after[$offset]) {
            ++$offset;
        }
        $start = max(0, $offset - self::CONTEXT);
        $excerpt = static fn (string $html): string => ($start > 0 ? '…' : '').str_replace(["\r", "\n"], ' ', mb_strcut($html, $start, 2 * self::CONTEXT + 40, 'UTF-8'));

        $io->writeln(sprintf('<info>%s #%s</info>', $label, $id));
        $io->writeln('  <fg=red>- '.OutputFormatter::escape($excerpt($before)).'</>');
        $io->writeln('  <fg=green>+ '.OutputFormatter::escape($excerpt($after)).'</>');
    }
}
