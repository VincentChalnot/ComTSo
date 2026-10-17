<?php

namespace App\Pagination;

/** Minimal page-number arithmetic shared by every paginated list. */
final class Pager
{
    public readonly int $pageCount;
    public readonly int $page;

    public function __construct(
        public readonly int $total,
        public readonly int $perPage,
        int $page,
    ) {
        $this->pageCount = max(1, (int) ceil($total / max(1, $perPage)));
        $this->page = min(max(1, $page), $this->pageCount);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pageCount;
    }

    /**
     * Page numbers to display: first, last, and a window around the current page. `null` stands for a gap.
     *
     * @return list<int|null>
     */
    public function getWindow(int $around = 2): array
    {
        $pages = [1, $this->pageCount];
        for ($i = $this->page - $around; $i <= $this->page + $around; ++$i) {
            if ($i >= 1 && $i <= $this->pageCount) {
                $pages[] = $i;
            }
        }
        $pages = array_values(array_unique($pages));
        sort($pages);

        $window = [];
        $previous = null;
        foreach ($pages as $p) {
            if (null !== $previous && $p - $previous > 1) {
                $window[] = $p - $previous === 2 ? $previous + 1 : null;
            }
            $window[] = $p;
            $previous = $p;
        }

        return $window;
    }

    /** Page on which the item at the given zero-based position falls. */
    public static function pageOfPosition(int $position, int $perPage): int
    {
        return intdiv($position, max(1, $perPage)) + 1;
    }
}
