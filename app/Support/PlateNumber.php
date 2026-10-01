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

    /**
     * Phase 5 (visitor model): letters and digits only ("ABC-1234", "abc 1234"
     * -> "ABC1234"). OCR, guards and the Registry write plates differently;
     * this is what identifies one plate.
     */
    public static function key(?string $plate): ?string
    {
        $key = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $plate)) ?? '';

        return $key !== '' ? $key : null;
    }

    /**
     * Phase 5: shown form, "ABC 1234" for PH layouts (letters first, also when
     * read in visual order "1234 ABC"); other plates as typed, uppercase.
     */
    public static function display(?string $plate): ?string
    {
        $key = self::key($plate);

        if ($key === null) {
            return null;
        }

        $reordered = self::numberFirstToLetterFirst($key);
        $key = $reordered !== null ? str_replace('-', '', $reordered) : $key;

        if (preg_match('/^([A-Z]{2,3})(\d{3,5})$/', $key, $matches) === 1) {
            return $matches[1].' '.$matches[2];
        }

        return trim(preg_replace('/\s+/', ' ', strtoupper((string) $plate)) ?? $key);
    }

    /**
     * Phase 5: a PH plate layout (3 letters + 3 or 4 digits, 2 letters + 4 or
     * 5 digits). Guards may still save another form (e.g. a conduction sticker).
     */
    public static function isPhilippineFormat(?string $plate): bool
    {
        return preg_match('/^([A-Z]{3}\d{3,4}|[A-Z]{2}\d{4,5})$/', str_replace(' ', '', (string) self::display($plate))) === 1;
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
