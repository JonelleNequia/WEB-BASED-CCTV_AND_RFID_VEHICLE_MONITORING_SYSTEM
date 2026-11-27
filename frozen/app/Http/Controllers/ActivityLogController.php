<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesTab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * UI Phase 2: Activity Logs page (All Events | RFID Scans | Alerts).
 * Replaces Event Logs, the RFID Desk history and Guest Monitoring.
 */
class ActivityLogController extends Controller
{
    use ResolvesTab;

    public const TABS = [
        'events' => 'All Events',
        'scans' => 'RFID Scans',
        'alerts' => 'Alerts',
    ];

    public function index(Request $request): View|JsonResponse
    {
        return match ($this->resolveTab($request, self::TABS)) {
            'scans' => app()->call([app(RfidScanController::class), 'history']),
            'alerts' => app()->call([app(AlertController::class), 'index']),
            default => app()->call([app(VehicleEventController::class), 'index']),
        };
    }
}
