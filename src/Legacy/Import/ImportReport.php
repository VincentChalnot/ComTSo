<?php

namespace App\Legacy\Import;

/** Counters per source table (total / present / imported / skipped-with-reason) and review lists. */
final class ImportReport
{
    /** @var array<string, array<string, array{total: int, present: int, imported: int, skipped: array<string, int>}>> */
    private array $rows = [];
    /** @var list<string> */
    private array $suspicious = [];
    /** @var list<string> */
    private array $notes = [];
    /** @var array<string, list<string>> */
    private array $details = [];

    public function total(string $source, string $table, int $n = 1): void
    {
        $row = &$this->row($source, $table);
        $row['total'] += $n;
    }

    public function present(string $source, string $table): void
    {
        $row = &$this->row($source, $table);
        ++$row['present'];
    }

    public function imported(string $source, string $table): void
    {
        $row = &$this->row($source, $table);
        ++$row['imported'];
    }

    public function skipped(string $source, string $table, string $reason): void
    {
        $row = &$this->row($source, $table);
        $row['skipped'][$reason] = ($row['skipped'][$reason] ?? 0) + 1;
    }

    public function suspicious(string $line): void
    {
        $this->suspicious[] = $line;
    }

    public function note(string $line): void
    {
        $this->notes[] = $line;
    }

    /** Per-item lines shown with --verbose, grouped by "source table: action". */
    public function detail(string $group, string $line): void
    {
        $this->details[$group][] = $line;
    }

    /** @return array<string, array<string, array{total: int, present: int, imported: int, skipped: array<string, int>}>> */
    public function rows(): array
    {
        return $this->rows;
    }

    /** @return list<string> */
    public function suspiciousLines(): array
    {
        return $this->suspicious;
    }

    /** @return list<string> */
    public function notes(): array
    {
        return $this->notes;
    }

    /** @return array<string, list<string>> */
    public function details(): array
    {
        return $this->details;
    }

    /** @return array{total: int, present: int, imported: int, skipped: array<string, int>} */
    private function &row(string $source, string $table): array
    {
        $this->rows[$source][$table] ??= ['total' => 0, 'present' => 0, 'imported' => 0, 'skipped' => []];

        return $this->rows[$source][$table];
    }
}
