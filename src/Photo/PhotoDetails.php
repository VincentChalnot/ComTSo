<?php

namespace App\Photo;

use App\Entity\Photo;

/**
 * The "Détails" of the photo page: French labels and human values built from the stored columns and the curated EXIF
 * map (also tolerates the older, thinner EXIF maps of the old forum). Only rows that exist are returned.
 * GPS is only included when the caller says the viewer may see it (owner, administrators).
 */
final class PhotoDetails
{
    private const WHITE_BALANCE = [0 => 'Automatique', 1 => 'Manuelle'];
    private const METERING = [1 => 'Moyenne', 2 => 'Pondérée centrale', 3 => 'Spot', 4 => 'Multi-spot', 5 => 'Matricielle', 6 => 'Partielle'];
    private const PROGRAM = [1 => 'Manuel', 2 => 'Programme normal', 3 => 'Priorité ouverture', 4 => 'Priorité vitesse', 5 => 'Créatif', 6 => 'Action', 7 => 'Portrait', 8 => 'Paysage'];

    /** @return list<array{label: string, value: string, url?: string}> */
    public function rows(Photo $photo, bool $withGps = false): array
    {
        $exif = $photo->getExif() ?? [];
        $rows = [];
        $add = static function (string $label, ?string $value, ?string $url = null) use (&$rows): void {
            if (null !== $value && '' !== $value) {
                $rows[] = ['label' => $label, 'value' => $value] + (null !== $url ? ['url' => $url] : []);
            }
        };

        $taken = $photo->getTakenAt();
        $add('Date de prise', $taken?->format('d/m/Y à H:i'));
        $add('Taille', null !== $photo->getFileSize() ? self::bytes($photo->getFileSize()) : null);
        if ($photo->getWidth() && $photo->getHeight()) {
            $dimensions = $photo->getWidth().' × '.$photo->getHeight().' px';
            $originalWidth = (int) ($exif['OriginalWidth'] ?? 0);
            $originalHeight = (int) ($exif['OriginalHeight'] ?? 0);
            if ($originalWidth && $originalHeight && ($originalWidth * $originalHeight) > ($photo->getWidth() * $photo->getHeight())) {
                $dimensions .= sprintf(' (original : %d × %d)', $originalWidth, $originalHeight);
            }
            $add('Dimensions', $dimensions);
        }

        $make = self::string($exif['Make'] ?? null);
        $model = self::string($exif['Model'] ?? null);
        $add('Appareil', $make && $model && !str_starts_with(mb_strtolower($model), mb_strtolower($make)) ? $make.' '.$model : ($model ?? $make));
        $add('Objectif', self::string($exif['LensModel'] ?? null) ?? self::string($exif['LensMake'] ?? null));

        $time = ExifReader::rational($exif['ExposureTime'] ?? null);
        $add('Exposition', null !== $time && $time > 0 ? (self::string($exif['ExposureTimeFraction'] ?? null) ?? ExifReader::fraction($time)).' s' : null);
        $aperture = ExifReader::rational($exif['FNumber'] ?? null);
        $add('Ouverture', null !== $aperture && $aperture > 0 ? 'f/'.self::number($aperture) : null);
        $iso = ExifReader::rational($exif['ISOSpeedRatings'] ?? null);
        $add('ISO', null !== $iso && $iso > 0 ? (string) (int) $iso : null);
        $focal = ExifReader::rational($exif['FocalLength'] ?? null);
        if (null !== $focal && $focal > 0) {
            $equivalent = ExifReader::rational($exif['FocalLengthIn35mmFilm'] ?? null);
            $add('Focale', self::number($focal).' mm'.($equivalent ? sprintf(' (équivalent 35 mm : %d mm)', $equivalent) : ''));
        }
        $bias = ExifReader::rational($exif['ExposureBiasValue'] ?? null);
        $add('Compensation d\'exposition', null !== $bias && 0.0 !== $bias ? sprintf('%+s IL', self::number($bias)) : null);
        $add('Programme d\'exposition', self::PROGRAM[(int) ($exif['ExposureProgram'] ?? 0)] ?? null);
        $add('Mesure', self::METERING[(int) ($exif['MeteringMode'] ?? 0)] ?? null);
        if (isset($exif['Flash']) && is_numeric($exif['Flash'])) {
            $add('Flash', 1 === ((int) $exif['Flash'] & 1) ? 'Déclenché' : 'Non déclenché');
        }
        $add('Balance des blancs', isset($exif['WhiteBalance']) ? (self::WHITE_BALANCE[(int) $exif['WhiteBalance']] ?? null) : null);
        $add('Logiciel', self::string($exif['Software'] ?? null));
        $add('Auteur', self::string($exif['Artist'] ?? null));
        $add('Copyright', self::string($exif['Copyright'] ?? null));
        $add('Description', self::string($exif['ImageDescription'] ?? null));

        if ($withGps && $photo->hasGps()) {
            $lat = $photo->getGpsLatitude();
            $lng = $photo->getGpsLongitude();
            $add(
                'Position GPS',
                sprintf('%s, %s', number_format($lat, 5, '.', ''), number_format($lng, 5, '.', '')),
                sprintf('https://www.openstreetmap.org/?mlat=%s&mlon=%s#map=16/%s/%s', number_format($lat, 6, '.', ''), number_format($lng, 6, '.', ''), number_format($lat, 5, '.', ''), number_format($lng, 5, '.', '')),
            );
            $add('Altitude', null !== $photo->getGpsAltitude() ? self::number($photo->getGpsAltitude()).' m' : null);
        }

        return $rows;
    }

    /** 266 Ko, 2,3 Mo (French decimal comma). */
    public static function bytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return str_replace('.', ',', self::number($bytes / 1024 / 1024)).' Mo';
        }

        return max(1, (int) round($bytes / 1024)).' Ko';
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    private static function string(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
