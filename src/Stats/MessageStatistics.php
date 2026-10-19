<?php

namespace App\Stats;

use Doctrine\DBAL\Connection;

/**
 * Messages written per member and per year (forum messages and chat), computed by ONE aggregate query: nothing is hydrated,
 * and only the pseudo and the enabled flag of a member are read (never an address).
 *
 * @phpstan-type Member array{id: int|null, username: string|null, enabled: bool, comments: array<int, int>, chat: array<int, int>, commentTotal: int, chatTotal: int}
 */
class MessageStatistics
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function report(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT m.author_id, u.username, u.enabled, m.kind, m.year, m.total
             FROM (
                 SELECT author_id, \'comments\' AS kind, YEAR(created_at) AS year, COUNT(*) AS total FROM ctso_comment GROUP BY author_id, YEAR(created_at)
                 UNION ALL
                 SELECT author_id, \'chat\' AS kind, YEAR(created_at) AS year, COUNT(*) AS total FROM ctso_chat_message GROUP BY author_id, YEAR(created_at)
             ) m
             LEFT JOIN fos_user u ON u.id = m.author_id',
        );

        $members = [];
        $years = [];
        $yearTotals = [];
        $totals = ['comments' => 0, 'chat' => 0];
        foreach ($rows as $row) {
            $key = $row['author_id'] ?? 'former';
            $year = (int) $row['year'];
            $kind = $row['kind'];
            $n = (int) $row['total'];
            $members[$key] ??= [
                'id' => null === $row['author_id'] ? null : (int) $row['author_id'],
                'username' => $row['username'],
                'enabled' => (bool) $row['enabled'],
                'comments' => [], 'chat' => [], 'commentTotal' => 0, 'chatTotal' => 0,
            ];
            $members[$key][$kind][$year] = $n;
            $members[$key][('comments' === $kind ? 'commentTotal' : 'chatTotal')] += $n;
            $years[$year] = true;
            $yearTotals[$year][$kind] = ($yearTotals[$year][$kind] ?? 0) + $n;
            $totals[$kind] += $n;
        }

        // Every year between the first and the last, so gaps are visible.
        $range = [] === $years ? [] : range(min(array_keys($years)), max(array_keys($years)));
        $yearTotals = array_map(static fn (int $y): array => ['year' => $y, 'comments' => $yearTotals[$y]['comments'] ?? 0, 'chat' => $yearTotals[$y]['chat'] ?? 0], $range);

        usort($members, static fn (array $a, array $b): int => [$b['commentTotal'] + $b['chatTotal'], (string) $a['username']] <=> [$a['commentTotal'] + $a['chatTotal'], (string) $b['username']]);

        return ['years' => $range, 'yearTotals' => $yearTotals, 'members' => $members, 'totals' => $totals];
    }
}
