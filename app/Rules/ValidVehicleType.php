<?php

namespace App\Rules;

use App\Support\VehicleType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidVehicleType implements ValidationRule
{
    public function __construct(private ?string $legacy = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $this->legacy !== null && $value === $this->legacy) {
            return;
        }
        if (VehicleType::canonical($value) === null) {
            $fail('Choose a supported vehicle type. An unchanged existing type may be kept.');
        }
    }
}
