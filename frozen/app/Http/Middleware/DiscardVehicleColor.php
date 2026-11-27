<?php

namespace App\Http\Middleware;

use App\Support\DeprecatedVehicleColor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DiscardVehicleColor
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe()) {
            // Compatibility input is ignored before validation and receive-log
            // storage. Existing database attributes and backups are untouched.
            $input = $request->isJson() ? $request->json() : $request->request;
            $input->replace(DeprecatedVehicleColor::without($input->all()));
            $request->query->replace(DeprecatedVehicleColor::without($request->query->all()));
            foreach (['vehicle_color', 'detected_vehicle_color'] as $field) {
                $request->files->remove($field);
            }
        }

        return $next($request);
    }
}
