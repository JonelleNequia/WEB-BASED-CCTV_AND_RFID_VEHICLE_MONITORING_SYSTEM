<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PersonalPassword implements ValidationRule
{
    public static function maxBytes(): int
    {
        return config('hashing.driver') === 'bcrypt' ? 72 : 1024;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || mb_strlen($value) < 15 || strlen($value) > self::maxBytes() || str_contains($value, "\0")) {
            $fail('Use at least 15 characters, up to '.self::maxBytes().' bytes. Passwords are kept exactly as entered.');

            return;
        }
        $key = mb_strtolower(trim($value)); // Comparison only; the password itself is never changed.
        $blocked = file(base_path('resources/security/password-blocklist.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $compact = preg_replace('/[^a-z0-9]/', '', $key);
        $blockedCompact = array_map(fn ($word) => preg_replace('/[^a-z0-9]/', '', $word), $blocked);
        if (in_array($compact, $blockedCompact, true) || str_contains($compact, 'philcst')
            || str_contains($compact, 'philippinecollegeofscienceandtechnology')
            || count(array_unique(mb_str_split($key))) < 4) {
            $fail('Choose a less common password unrelated to PHILCST. A longer personal passphrase is welcome.');
        }
    }
}
