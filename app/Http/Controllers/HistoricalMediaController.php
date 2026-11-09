<?php

namespace App\Http\Controllers;

use App\Support\OccurrenceMedia;

/** Compatibility URLs use exact stored references, never plate-based ownership. */
class HistoricalMediaController extends Controller
{
    public function __invoke(string $path)
    {
        abort_unless(OccurrenceMedia::legacyPath($path) && OccurrenceMedia::referenced($path), 404);
        $file = OccurrenceMedia::legacyFile($path);
        abort_unless($file, 404);

        return response()->file($file, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ])->setPrivate();
    }
}
