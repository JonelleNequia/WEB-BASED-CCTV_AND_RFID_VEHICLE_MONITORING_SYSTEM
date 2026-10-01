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
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || Gate::resolveCode($value) === null) {
            $fail('Choose an existing gate.');
        }
    }
}
