<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/** New occurrence evidence is private; legacy paths stay where they are. */
final class OccurrenceMedia
{
    public const PREFIX = 'occurrence-media/';

    public static function privateFile(?string $path): ?string
    {
        return $path && preg_match('~\Aoccurrence-media/([a-f0-9-]{36}\.(?:jpg|png))\z~', $path, $match)
            ? $match[1] : null;
    }

    public static function exists(?string $path): bool
    {
        if ($file = self::privateFile($path)) {
            return Storage::disk('occurrences')->exists($file);
        }

        return self::legacyFile($path) !== null;
    }

    public static function legacyPath(?string $path): bool
    {
        return $path && ! str_starts_with($path, self::PREFIX)
            && preg_match('~\A[A-Za-z0-9_/-]+(?:[ .-][A-Za-z0-9_-]+)*\.(?:jpe?g|png|webp)\z~i', $path)
            && ! str_contains($path, '..') && ! str_starts_with($path, '/')
            && ! in_array('', explode('/', $path), true);
    }

    public static function legacyFile(?string $path): ?string
    {
        if (! self::legacyPath($path)) return null;
        $root = realpath(Storage::disk('public')->path(''));
        $file = realpath(Storage::disk('public')->path($path));
        // Refuse symlink escapes, directories and executable/non-image content.
        if (! $root || ! $file || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file)) return null;

        return $file;
    }

    public static function referenced(string $path): bool
    {
        return \App\Models\VehicleCrossing::where('snapshot_path', $path)->exists()
            || \App\Models\VisitorRecord::where('snapshot_path', $path)->orWhere('plate_image_path', $path)->exists()
            || \App\Models\GuestVehicleObservation::where('snapshot_path', $path)->exists()
            || \App\Models\VehicleEvent::where('vehicle_image_path', $path)->orWhere('plate_image_path', $path)->exists();
    }

    public static function url(?string $path): ?string
    {
        if (! self::exists($path)) {
            return null;
        }

        return ($file = self::privateFile($path))
            ? route('evidence.occurrence', ['file' => $file], absolute: false)
            : route('evidence.historical', ['path' => $path], absolute: false);
    }
}
