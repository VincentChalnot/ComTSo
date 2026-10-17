<?php

namespace App\Legacy;

/** Legacy unix timestamps are converted to Europe/Paris wall-clock time, the convention of the whole database. */
final class LegacyDate
{
    public static function fromUnix(int $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('Europe/Paris'));
    }

    /** "Y-m-d H:i:s" wall-clock digits, as stored in the DATETIME columns. */
    public static function sql(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    /** Parses a stored DATETIME (Paris wall clock) back to a date; its timestamp is the *Paris* instant. */
    public static function fromSql(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('Europe/Paris'));
    }
}
