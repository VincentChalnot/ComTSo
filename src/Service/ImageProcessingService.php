<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class ImageProcessingService
{
    public function __construct(
        private string $photosDirectory,
        private string $publicDir
    ) {
    }

    /**
     * Extract EXIF data from an image file
     */
    public function extractExif(string $filepath): ?array
    {
        if (!function_exists('exif_read_data')) {
            return null;
        }

        $exif = @exif_read_data($filepath, null, true);
        if (!$exif) {
            return null;
        }

        // Clean and normalize EXIF data
        $cleanExif = [];

        if (isset($exif['IFD0'])) {
            $cleanExif['Make'] = $exif['IFD0']['Make'] ?? null;
            $cleanExif['Model'] = $exif['IFD0']['Model'] ?? null;
            $cleanExif['DateTime'] = $exif['IFD0']['DateTime'] ?? null;
        }

        if (isset($exif['EXIF'])) {
            $cleanExif['ExposureTime'] = $this->formatExposureTime($exif['EXIF']['ExposureTime'] ?? null);
            $cleanExif['FNumber'] = $this->formatFNumber($exif['EXIF']['FNumber'] ?? null);
            $cleanExif['ISOSpeedRatings'] = $exif['EXIF']['ISOSpeedRatings'] ?? null;
            $cleanExif['FocalLength'] = $this->formatFocalLength($exif['EXIF']['FocalLength'] ?? null);
            $cleanExif['DateTimeOriginal'] = $exif['EXIF']['DateTimeOriginal'] ?? null;
        }

        if (isset($exif['GPS'])) {
            $cleanExif['GPS'] = $this->extractGPSCoordinates($exif['GPS']);
        }

        return array_filter($cleanExif);
    }

    /**
     * Extract the date when photo was taken from EXIF
     */
    public function extractTakenDate(array $exif): ?\DateTimeInterface
    {
        $dateString = $exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null;

        if (!$dateString) {
            return null;
        }

        try {
            return new \DateTime($dateString);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Generate thumbnail using LiipImagine or GD
     */
    public function generateThumbnail(string $filepath, int $width = 200, int $height = 200): ?string
    {
        // This would typically use LiipImagineBundle's filter system
        // For now, return the original path - LiipImagine will handle it via Twig filter
        return $filepath;
    }

    /**
     * Validate that uploaded file is an image
     */
    public function validateImage(UploadedFile $file): bool
    {
        $mimeType = $file->getMimeType();
        $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];

        return in_array($mimeType, $allowedMimes);
    }

    /**
     * Get image dimensions
     */
    public function getImageDimensions(string $filepath): ?array
    {
        $imageInfo = @getimagesize($filepath);
        if (!$imageInfo) {
            return null;
        }

        return [
            'width' => $imageInfo[0],
            'height' => $imageInfo[1],
            'type' => $imageInfo[2],
            'mime' => $imageInfo['mime']
        ];
    }

    /**
     * Format exposure time to readable fraction
     */
    private function formatExposureTime(?string $exposureTime): ?string
    {
        if (!$exposureTime) {
            return null;
        }

        if (str_contains($exposureTime, '/')) {
            $parts = explode('/', $exposureTime);
            if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                $value = (float)$parts[0] / (float)$parts[1];
                if ($value < 1) {
                    return "1/" . round(1 / $value);
                }
                return round($value, 1) . "s";
            }
        }

        return $exposureTime;
    }

    /**
     * Format F-number to readable aperture value
     */
    private function formatFNumber(?string $fNumber): ?string
    {
        if (!$fNumber) {
            return null;
        }

        if (str_contains($fNumber, '/')) {
            $parts = explode('/', $fNumber);
            if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                return round((float)$parts[0] / (float)$parts[1], 1);
            }
        }

        return $fNumber;
    }

    /**
     * Format focal length to mm
     */
    private function formatFocalLength(?string $focalLength): ?string
    {
        if (!$focalLength) {
            return null;
        }

        if (str_contains($focalLength, '/')) {
            $parts = explode('/', $focalLength);
            if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                return round((float)$parts[0] / (float)$parts[1]);
            }
        }

        return $focalLength;
    }

    /**
     * Extract GPS coordinates from EXIF GPS data
     */
    private function extractGPSCoordinates(array $gps): ?array
    {
        if (!isset($gps['GPSLatitude']) || !isset($gps['GPSLongitude'])) {
            return null;
        }

        $lat = $this->convertGPSCoordinate($gps['GPSLatitude'], $gps['GPSLatitudeRef']);
        $lon = $this->convertGPSCoordinate($gps['GPSLongitude'], $gps['GPSLongitudeRef']);

        if ($lat === null || $lon === null) {
            return null;
        }

        return [
            'latitude' => $lat,
            'longitude' => $lon
        ];
    }

    /**
     * Convert GPS coordinate to decimal degrees
     */
    private function convertGPSCoordinate(array $coordinate, string $ref): ?float
    {
        if (count($coordinate) !== 3) {
            return null;
        }

        $degrees = $this->gpsToDecimal($coordinate[0]);
        $minutes = $this->gpsToDecimal($coordinate[1]);
        $seconds = $this->gpsToDecimal($coordinate[2]);

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

        if ($ref === 'S' || $ref === 'W') {
            $decimal *= -1;
        }

        return $decimal;
    }

    /**
     * Convert GPS fraction to decimal
     */
    private function gpsToDecimal(string $value): float
    {
        if (str_contains($value, '/')) {
            $parts = explode('/', $value);
            if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1]) && $parts[1] != 0) {
                return (float)$parts[0] / (float)$parts[1];
            }
        }

        return (float)$value;
    }
}
