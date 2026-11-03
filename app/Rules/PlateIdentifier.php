<?php

namespace App\Rules;

use App\Support\PlateNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PlateIdentifier implements ValidationRule
{
    public function __construct(private ?int $maximumKeyLength = null, private ?string $legacyValue = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return; // The field's string rule reports malformed types.
        }
        if ($this->legacyValue !== null && strtoupper(trim($value)) === strtoupper(trim($this->legacyValue))) {
            return;
        }
        $key = PlateNumber::key($value);
        if ($key === null) {
            $fail('Enter a plate containing letters or digits, with spaces or hyphens only as separators. Unsupported symbols and punctuation-only plates are not allowed.');
        } elseif ($this->maximumKeyLength !== null && strlen($key) > $this->maximumKeyLength) {
            $fail('The plate may contain at most '.$this->maximumKeyLength.' letters and digits, excluding separators.');
        } elseif ($this->maximumKeyLength !== null && mb_strlen(PlateNumber::display($value) ?? '') > 30) {
            $fail('The formatted plate must not exceed 30 characters.');
        }
    }
}
