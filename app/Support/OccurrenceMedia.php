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

        return $path && ! str_starts_with($path, self::PREFIX)
            && ! str_contains($path, '..') && ! str_starts_with($path, '/') && ! str_contains($path, ':')
            && Storage::disk('public')->exists($path);
    }

    public static function url(?string $path): ?string
    {
        if (! self::exists($path)) {
            return null;
        }

        return ($file = self::privateFile($path))
            ? route('evidence.occurrence', ['file' => $file], absolute: false)
            : Storage::disk('public')->url($path);
    }
}
