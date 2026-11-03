<?php

namespace App\Support;

final class PlateNumber
{
    /**
     * Normalize plate values without changing existing manual letter-first text.
     */
    public static function normalize(?string $plate): ?string
    {
        $rawPlate = trim(preg_replace('/\p{Zs}+/u', ' ', (string) $plate) ?? (string) $plate, ' ');

        if ($rawPlate === '') {
            return null;
        }

        $normalized = strtoupper($rawPlate);
        $compact = preg_match('/\A[A-Z0-9 -]+\z/', $normalized) === 1 ? str_replace([' ', '-'], '', $normalized) : '';
        $reordered = self::numberFirstToLetterFirst($compact);

        return $reordered ?: $normalized;
    }

    /**
     * Phase 5 (visitor model): letters and digits only ("ABC-1234", "abc 1234"
     * -> "ABC1234"). Known number-first layouts use the same key. Unsupported
     * characters return null rather than being stripped into another identity.
     */
    public static function key(?string $plate): ?string
    {
        $normalized = self::normalize($plate);
        if ($normalized === null || preg_match('/\A[A-Z0-9 -]+\z/', $normalized) !== 1) {
            return null;
        }
        $key = str_replace([' ', '-'], '', $normalized);

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
            return self::normalize($plate);
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

    /**
     * Portable SQL search using the same separators, including old visual order.
     * $columns contains only application-defined column names, never user input.
     */
    public static function search($query, array $columns, string $text): void
    {
        $key = self::key($text);
        if ($key === null) {
            $query->whereRaw('1 = 0');

            return;
        }
        $keys = [$key];
        if (preg_match('/\A([A-Z]{3})([0-9]{3,4})\z|\A([A-Z]{2})([0-9]{4,5})\z/', $key, $parts) === 1) {
            $keys[] = ! empty($parts[1]) ? $parts[2].$parts[1] : $parts[4].$parts[3];
        }
        $query->where(function ($inner) use ($keys, $columns): void {
            foreach ($columns as $column) {
                $expression = "upper(coalesce($column, ''))";
                foreach ([0x20, 0xA0, 0x1680, ...range(0x2000, 0x200A), 0x202F, 0x205F, 0x3000] as $code) {
                    $expression = "replace($expression, '".mb_chr($code)."', '')";
                }
                $expression = "replace($expression, '-', '')";
                foreach ($keys as $key) {
                    $inner->orWhereRaw("$expression like ?", ['%'.$key.'%']);
                }
            }
        });
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
