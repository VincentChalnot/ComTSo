<?php

namespace App\Photo;

/** Which selection rule produced the featured photo (it drives the hero's kicker label). */
enum FeaturedRule: string
{
    /** Uploaded within the last 7 days. */
    case Week = 'week';
    /** Nothing in the last 7 days: uploaded within the last 30 days. */
    case Month = 'month';
    /** Nothing recent: picked from the whole library. */
    case Library = 'library';

    public function kicker(): string
    {
        return match ($this) {
            self::Week => 'Photo de la semaine',
            self::Month => 'Photo du mois',
            self::Library => 'Photo à redécouvrir',
        };
    }
}
