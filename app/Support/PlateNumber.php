<?php

namespace App\Support;

final class PlateNumber
{
    /**
     * Normalize case and ordinary spaces without changing alphanumeric order.
     */
    public static function normalize(?string $plate): ?string
    {
        $rawPlate = trim(preg_replace('/\p{Zs}+/u', ' ', (string) $plate) ?? (string) $plate, ' ');

        if ($rawPlate === '') {
            return null;
        }

        return strtoupper($rawPlate);
    }

    /**
     * Phase 5 (visitor model): letters and digits only ("ABC-1234", "abc 1234"
     * -> "ABC1234"). Character order is always literal. Unsupported
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
     * Group familiar letter-first layouts for display; preserve every other
     * literal layout as typed, uppercase.
     */
    public static function display(?string $plate): ?string
    {
        $key = self::key($plate);

        if ($key === null) {
            return self::normalize($plate);
        }

        if (preg_match('/^([A-Z]{2,3})(\d{3,5})$/', $key, $matches) === 1) {
            return $matches[1].' '.$matches[2];
        }

        return trim(preg_replace('/\s+/', ' ', strtoupper((string) $plate)) ?? $key);
    }

    /**
     * Recognize supported PH group lengths in either literal order. Guards may
     * still save another identifier (e.g. a conduction sticker).
     */
    public static function isPhilippineFormat(?string $plate): bool
    {
        return preg_match('/^([A-Z]{3}\d{3,4}|[A-Z]{2}\d{4,5}|\d{3,4}[A-Z]{3}|\d{4,5}[A-Z]{2})$/', (string) self::key($plate)) === 1;
    }

    /**
     * Portable SQL search using safe separators without changing character order.
     * $columns contains only application-defined column names, never user input.
     */
    public static function search($query, array $columns, string $text): void
    {
        $key = self::key($text);
        if ($key === null) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->where(function ($inner) use ($key, $columns): void {
            foreach ($columns as $column) {
                $expression = "upper(coalesce($column, ''))";
                foreach ([0x20, 0xA0, 0x1680, ...range(0x2000, 0x200A), 0x202F, 0x205F, 0x3000] as $code) {
                    $expression = "replace($expression, '".mb_chr($code)."', '')";
                }
                $expression = "replace($expression, '-', '')";
                $inner->orWhereRaw("$expression like ?", ['%'.$key.'%']);
            }
        });
    }
}
