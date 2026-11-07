<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Preserve sticker text while retaining the inventory's positive numeric-number contract. */
class RfidTagNumber implements ValidationRule
{
    public const MAX_LENGTH = 20;

    public const MAX_NUMBER = 999999;

    public const MESSAGE = 'Enter a tag number from 1 to 999999 using digits only. Leading zeros are kept, up to 20 digits in total.';

    public static function normalize(mixed $value): ?string
    {
        return is_string($value) || is_int($value) ? trim((string) $value) : null;
    }

    public static function valid(mixed $value): bool
    {
        $number = self::normalize($value);
        if ($number === null || strlen($number) > self::MAX_LENGTH || ! preg_match('/\A[0-9]+\z/', $number)) {
            return false;
        }
        $significant = ltrim($number, '0');

        return $significant !== '' && strlen($significant) <= strlen((string) self::MAX_NUMBER);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::valid($value)) {
            $fail(self::MESSAGE);
        }
    }
}
