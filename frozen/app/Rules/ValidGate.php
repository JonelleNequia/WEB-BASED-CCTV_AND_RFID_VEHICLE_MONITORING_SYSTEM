<?php

namespace App\Rules;

use App\Models\Gate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Phase 1: a gate code ("gate-1"), or the old station names "entrance" /
 * "exit" (Gate 1 / Gate 2). Callers store Gate::normalizeCode($value).
 */
class ValidGate implements ValidationRule
{
    public function __construct(private bool $historical = false, private ?string $original = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $this->original !== null
            && ($resolved = Gate::resolveCode($value, historical: true)) !== null
            && $resolved === Gate::resolveCode($this->original, historical: true)) {
            return; // An existing historical record may keep its own gate, never a new archived assignment.
        }
        if (! is_string($value) || Gate::resolveCode($value, historical: $this->historical) === null) {
            $fail('Choose an existing gate.');
        }
    }
}
