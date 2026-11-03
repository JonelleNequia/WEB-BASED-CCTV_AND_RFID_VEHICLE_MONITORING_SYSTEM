<?php

namespace App\Rules;

use App\Models\Camera;
use App\Models\Gate;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class CameraAtGate implements DataAwareRule, ValidationRule
{
    private array $data = [];

    public function __construct(private string $gateField) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $suppliedGate = $this->data[$this->gateField] ?? null;
        if (! is_string($suppliedGate) || ! is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            return;
        }
        $gate = Gate::resolveCode($suppliedGate);
        if ($gate !== null && Camera::query()->whereKey($value)->exists()
            && ! Camera::query()->whereKey($value)->where('camera_role', $gate)->exists()) {
            $fail('Choose the camera assigned to the selected gate.');
        }
    }
}
