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
        $invalidOwner = [];
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            foreach (['vehicle_owner_name', 'owner_name', 'name'] as $field) {
                $value = $request->input($field);
                if (in_array($field, ['vehicle_owner_name', 'owner_name'], true) && is_string($value)
                    && preg_match('/\p{C}/u', $value) === 1) {
                    $invalidOwner[] = $field;
                }
                if (is_string($value) && $value !== '' && preg_match('/\A[\s\p{Z}]+\z/u', $value) === 1) {
                    $blank[] = $field;
                }
            }
        }
        $request->attributes->set('blank_name_fields', $blank);
        $request->attributes->set('invalid_owner_name_fields', $invalidOwner);

        return $next($request);
    }
}
