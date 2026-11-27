<?php

namespace App\Rules;

use Illuminate\Support\Facades\Validator;

/** Required only when creating a registration; legacy edits keep their rules. */
final class RegistrationOwnerName
{
    public static function rules(bool $invalidRawInput = false): array
    {
        return ['bail', 'required', 'string', 'max:100', function ($attribute, $value, $fail) use ($invalidRawInput): void {
            if ($invalidRawInput) {
                $fail('Use letters, spaces, apostrophes, hyphens and periods for the name. Control characters and unsupported symbols are not allowed.');
            }
        }, new PersonalName];
    }

    public static function messages(): array
    {
        return [
            'vehicle_owner_name.required' => 'Enter the vehicle owner’s name.',
            'vehicle_owner_name.string' => 'Enter a valid owner name using letters, spaces, apostrophes, hyphens and periods.',
            'vehicle_owner_name.max' => 'Owner Name must not exceed 100 characters.',
        ];
    }

    public static function validated(mixed $value): string
    {
        // Retain controls for validation rather than laundering them into spaces.
        $value = is_string($value) ? trim(preg_replace('/\p{Zs}+/u', ' ', $value) ?? $value, ' ') : $value;

        return Validator::make(['vehicle_owner_name' => $value], ['vehicle_owner_name' => self::rules()], self::messages())
            ->validate()['vehicle_owner_name'];
    }
}
