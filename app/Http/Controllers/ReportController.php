<?php

namespace App\Http\Controllers;

use App\Models\GuestVehicleObservation;
use App\Models\RfidScanLog;
use App\Models\VehicleEvent;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReportController extends Controller
{
    /**
     * Show summarized operational reports.
     */
    public function index(Request $request): View
    {
        $fromDate = $this->parseDate($request->string('date_from')->value()) ?? today();
        $toDate = $this->parseDate($request->string('date_to')->value()) ?? today();

        if ($fromDate->greaterThan($toDate)) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        $from = $fromDate->copy()->startOfDay();
        $to = $toDate->copy()->endOfDay();

        $eventBase = VehicleEvent::query()->whereBetween('event_time', [$from, $to]);

        $dailyBreakdown = VehicleEvent::query()
            ->selectRaw('DATE(event_time) as report_date')
            ->selectRaw("SUM(CASE WHEN event_type = 'ENTRY' THEN 1 ELSE 0 END) as entry_count")
            ->selectRaw("SUM(CASE WHEN event_type = 'EXIT' THEN 1 ELSE 0 END) as exit_count")
            ->whereBetween('event_time', [$from, $to])
            ->groupBy(DB::raw('DATE(event_time)'))
            ->orderBy('report_date')
            ->get();

        return view('reports.index', [
            'filters' => [
                'date_from' => $fromDate->toDateString(),
                'date_to' => $toDate->toDateString(),
            ],
            'summary' => [
                'entries' => (clone $eventBase)->where('event_type', 'ENTRY')->count(),
                'exits' => (clone $eventBase)->where('event_type', 'EXIT')->count(),
                'rfid_scans' => RfidScanLog::query()->whereBetween('scan_time', [$from, $to])->count(),
                'verified_rfid_scans' => RfidScanLog::query()
                    ->whereBetween('scan_time', [$from, $to])
                    ->where('verification_status', 'verified')
                    ->count(),
                'guest_observations' => GuestVehicleObservation::query()->whereBetween('observed_at', [$from, $to])->count(),
                'review_queue' => (clone $eventBase)->where('match_status', 'manual_review')->count(),
                'incomplete_records' => (clone $eventBase)->where('event_status', VehicleEvent::STATUS_PENDING_DETAILS)->count(),
            ],
            'dailyBreakdown' => $dailyBreakdown,
        ]);
    }

    /**
     * Parse a date string safely without throwing.
     */
    protected function parseDate(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
