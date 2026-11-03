<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CaptureNameInput
{
    public function handle(Request $request, Closure $next): Response
    {
        // Keep only field names, not submitted personal data. TrimStrings and
        // ConvertEmptyStringsToNull would otherwise hide whitespace-only names.
        $blank = [];
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            foreach (['vehicle_owner_name', 'owner_name', 'name'] as $field) {
                $value = $request->input($field);
                if (is_string($value) && $value !== '' && preg_match('/\A[\s\p{Z}]+\z/u', $value) === 1) {
                    $blank[] = $field;
                }
            }
        }
        $request->attributes->set('blank_name_fields', $blank);

        return $next($request);
    }
}
