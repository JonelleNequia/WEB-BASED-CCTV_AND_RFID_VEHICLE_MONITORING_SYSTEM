<?php

namespace App\Http\Controllers;

use App\Models\RfidScanLog;
use App\Services\AlertSummaryService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Activity Logs › Alerts: what needs a look. Anomalies (direction does not
 * fit, lost or disabled tag, inactive vehicle...) and unknown tags.
 * Unregistered visitors are normal traffic (Visitors page), not alerts.
 */
class AlertController extends Controller
{
    public function index(Request $request, AlertSummaryService $alertSummaryService): View
    {
        $filters = $request->only(['type', 'q']);

        $alerts = RfidScanLog::query()
            ->with(['vehicle', 'vehicleRfidTag'])
            ->where('is_anomaly', true)
            ->when(($filters['type'] ?? '') === 'unknown_tag', fn ($query) => $query->where('verification_status', 'unknown_tag'))
            ->when(($filters['type'] ?? '') === 'anomaly', fn ($query) => $query->where('verification_status', '!=', 'unknown_tag'))
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters): void {
                $term = '%'.trim((string) $filters['q']).'%';
                $query->where(fn ($inner) => $inner->where('tag_uid', 'like', $term)
                    ->orWhereHas('vehicle', fn ($vehicle) => $vehicle->where('plate_number', 'like', $term)));
            })
            ->latest('scan_time')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('logs.index', [
            'tab' => 'alerts',
            'alerts' => $alerts,
            'filters' => $filters,
            'alertCounts' => $alertSummaryService->counts(),
        ]);
    }
}
