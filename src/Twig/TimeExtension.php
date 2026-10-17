<?php

namespace App\Twig;

use Twig\Attribute\AsTwigFilter;

class TimeExtension
{
    /**
     * Relative French date: "à l'instant", "il y a 5 min", "il y a 3 h", "il y a 2 j", "il y a 3 sem.", "il y a 4 mois", "il y a 2 ans".
     * Pair it with a <time datetime title> element so the exact date stays available. `$now` exists for tests.
     */
    #[AsTwigFilter('ago')]
    public function ago(?\DateTimeInterface $date, ?\DateTimeInterface $now = null): string
    {
        if (null === $date) {
            return '';
        }

        $seconds = ($now ?? new \DateTimeImmutable())->getTimestamp() - $date->getTimestamp();
        if ($seconds < 60) {
            return 'à l\'instant';
        }
        if ($seconds < 3600) {
            return \sprintf('il y a %d min', intdiv($seconds, 60));
        }
        if ($seconds < 86400) {
            return \sprintf('il y a %d h', intdiv($seconds, 3600));
        }

        $days = intdiv($seconds, 86400);
        if ($days < 7) {
            return \sprintf('il y a %d j', $days);
        }
        if ($days < 30) {
            return \sprintf('il y a %d sem.', intdiv($days, 7));
        }
        if ($days < 365) {
            return \sprintf('il y a %d mois', intdiv($days, 30));
        }

        $years = intdiv($days, 365);

        return \sprintf('il y a %d %s', $years, $years > 1 ? 'ans' : 'an');
    }
}
