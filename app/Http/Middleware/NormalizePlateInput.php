<?php

namespace App\Http\Middleware;

use App\Support\PlateNumber;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizePlateInput
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe()) {
            foreach (['plate_number', 'plate_text', 'plate'] as $field) {
                if (is_string($request->input($field))) {
                    $request->merge([$field => PlateNumber::normalize($request->input($field))]);
                }
            }
        }

        return $next($request);
    }
}
