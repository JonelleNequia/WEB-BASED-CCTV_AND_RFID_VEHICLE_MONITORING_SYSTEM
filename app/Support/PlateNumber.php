<?php

namespace App\Support;

final class PlateNumber
{
    /**
     * Normalize plate values without changing existing manual letter-first text.
     */
    public static function normalize(?string $plate): ?string
    {
        $rawPlate = trim((string) $plate);

        if ($rawPlate === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', $rawPlate) ?? $rawPlate;
        $normalized = strtoupper(trim($normalized));
        $compact = preg_replace('/[^A-Z0-9]/', '', $normalized) ?? '';
        $reordered = self::numberFirstToLetterFirst($compact);

        return $reordered ?: $normalized;
    }

    protected static function numberFirstToLetterFirst(string $compactPlate): ?string
    {
        $layouts = [
            [3, 4],
            [3, 3],
            [2, 5],
            [2, 4],
        ];

        foreach ($layouts as [$letterCount, $digitCount]) {
            if (preg_match('/^(\d{'.$digitCount.'})([A-Z]{'.$letterCount.'})$/', $compactPlate, $matches) !== 1) {
                continue;
            }

            return $matches[2].'-'.$matches[1];
        }

        return null;
    }
}
