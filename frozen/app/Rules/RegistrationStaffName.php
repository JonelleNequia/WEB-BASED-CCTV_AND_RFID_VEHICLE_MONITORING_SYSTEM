<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;

class RegistrationStaffName implements ValidationRule
{
    public const REQUIRED_MESSAGE = 'Enter the name of the staff member processing this registration.';

    public const FORMAT_MESSAGE = 'Use letters, spaces, apostrophes, hyphens and periods. Digits, emojis, control characters and punctuation-only names are not allowed.';

    public const LENGTH_MESSAGE = 'Registered by must not exceed 100 characters.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Reuse personal-name rules without the legacy-owner exception.
        $personalName = is_string($value) ? str_replace('‘', '’', $value) : $value;
        (new PersonalName)->validate($attribute, $personalName, fn () => $fail(self::FORMAT_MESSAGE));
    }

    public static function normalize(string $value): string
    {
        // Normalize space separators only. Tabs/newlines/controls must reach
        // validation unchanged instead of being converted into valid spaces.
        return trim(preg_replace('/\p{Zs}+/u', ' ', $value) ?? $value, ' ');
    }

    public static function rules(): array
    {
        return ['bail', 'required', 'string', 'max:100', new self];
    }

    public static function messages(): array
    {
        return [
            'registered_by.required' => self::REQUIRED_MESSAGE,
            'registered_by.string' => self::FORMAT_MESSAGE,
            'registered_by.max' => self::LENGTH_MESSAGE,
        ];
    }

    public static function validated(mixed $value): string
    {
        $value = is_string($value) ? self::normalize($value) : $value;

        return Validator::make(['registered_by' => $value], ['registered_by' => self::rules()], self::messages())
            ->validate()['registered_by'];
    }
}
