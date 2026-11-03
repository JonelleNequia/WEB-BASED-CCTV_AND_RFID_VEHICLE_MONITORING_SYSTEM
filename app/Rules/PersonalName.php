<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PersonalName implements ValidationRule
{
    public bool $implicit = true;

    public function __construct(private ?string $legacyValue = null, private bool $blankInput = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->blankInput) {
            $fail('Enter a name containing letters, or clear the optional field completely. Spaces alone are not a name.');

            return;
        }
        // Null means an optional name was omitted or intentionally cleared.
        if ($value === null) {
            return;
        }
        if (is_string($value) && $this->legacyValue !== null && self::normalize($value) === self::normalize($this->legacyValue)) {
            return;
        }
        // Some apostrophe-shaped Unicode characters are classified as
        // modifier letters; they cannot make a punctuation-only name valid.
        $letters = is_string($value) ? str_replace(["'", '’', 'ʻ', 'ʼ'], '', $value) : '';
        if (! is_string($value) || preg_match('/\p{L}/u', $letters) !== 1
            || preg_match("/\A[\p{L}\p{M}\p{Zs} .'\x{2019}\x{2010}\x{2011}-]+\z/u", $value) !== 1) {
            $fail('Use letters, spaces, apostrophes, hyphens and periods for the name. Digits, emojis and punctuation-only names are not allowed.');
        }
    }

    public static function normalize(string $value): string
    {
        return trim(preg_replace('/[\s\p{Z}]+/u', ' ', $value) ?? $value);
    }
}
