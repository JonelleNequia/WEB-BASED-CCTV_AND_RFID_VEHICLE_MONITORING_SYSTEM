<?php

namespace App\Http\Controllers;

use App\View\Composers\NavigationComposer;
use Illuminate\Http\JsonResponse;

/**
 * UI Phase 4: the sidebar's three status dots, polled while a page is open.
 */
class SystemHealthController extends Controller
{
    public function __invoke(NavigationComposer $navigation): JsonResponse
    {
        return response()->json(['health' => $navigation->health()])
            ->header('Cache-Control', 'no-store, max-age=0');
    }
}
