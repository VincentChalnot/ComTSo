<?php

namespace App\Legacy\Import;

use App\Legacy\Text\TextRepair;
use Doctrine\DBAL\Connection;

/**
 * The Symfony 2 migration of the old PHP forum left some text UTF-8-encoded twice ("Â " = a no-break space, "Noël" as "NoÃ«l",
 * Chinese quotes as "èª°å±¬"). {@see TextRepair::fixMojibake()} reverses it sequence by sequence; this applies it to every
 * text column of the live tables after an import, rewriting only the rows that change (plain SQL: `updated_at` is left alone).
 * Idempotent: repaired text no longer matches.
 */
final class MojibakeRepairer
{
    /** table => text columns */
    private const COLUMNS = [
        'ctso_comment' => ['content'],
        'ctso_topic' => ['title', 'content'],
        'ctso_forum' => ['title', 'content'],
        'ctso_message' => ['content'],
        'ctso_chat_message' => ['content'],
        'ctso_quote' => ['content', 'original_author'],
        'ctso_photo' => ['title', 'content'],
        'fos_user' => ['name', 'surname', 'address', 'activities', 'signature'],
    ];

    /** Letters that start a double-encoded sequence: a cheap SQL filter before the PHP pass. */
    private const PREFILTER = 'Ã|Â|Å|[à-ï]';

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array<string, list<int|string>> "table.column" => ids of the repaired rows */
    public function repair(bool $dryRun = false): array
    {
        $repaired = [];
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $rows = $this->connection->fetchAllAssociative(sprintf(
                    'SELECT id, %1$s AS text FROM %2$s WHERE %1$s IS NOT NULL AND %1$s REGEXP %3$s',
                    $column,
                    $table,
                    $this->connection->quote(self::PREFILTER),
                ));
                foreach ($rows as $row) {
                    $fixed = self::repairText((string) $row['text']);
                    if ($fixed === $row['text']) {
                        continue;
                    }
                    $repaired["$table.$column"][] = $row['id'];
                    if (!$dryRun) {
                        $this->connection->update($table, [$column => $fixed], ['id' => $row['id']]);
                    }
                }
            }
        }

        return $repaired;
    }

    public static function repairText(string $text): string
    {
        $fixed = TextRepair::fixMojibake($text);

        // A double-encoded byte order mark ("ï»¿") ends up as an invisible U+FEFF in the middle of a text.
        return $fixed === $text ? $text : str_replace("\u{FEFF}", '', $fixed);
    }
}
