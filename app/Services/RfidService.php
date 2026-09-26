<?php

namespace App\Services;

use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Models\Vehicle;
use App\Support\PhilippineTime;
use App\Support\RfidIngestResult;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class RfidService
{
    public function __construct(
        protected SettingsService $settingsService,
        protected VehicleOccupancyService $vehicleOccupancyService,
        protected RfidIngestService $rfidIngestService
    ) {
    }

    /**
     * Create one simulated RFID scan from the RFID Desk.
     *
     * Phase 3: the RFID Desk is the only place that keeps the old toggle
     * (INSIDE -> EXIT, OUTSIDE -> ENTRY). Guest pass rules still apply.
     *
     * @param  array<string, mixed>  $data
     */
    public function simulate(array $data): RfidIngestResult
    {
        if (($this->settingsService->get('rfid_simulation_mode', 'enabled') ?? 'enabled') !== 'enabled') {
            throw ValidationException::withMessages([
                'rfid_simulation_mode' => 'RFID simulation mode is disabled in Settings.',
            ]);
        }

        return $this->rfidIngestService->ingest($data, 'simulated', RfidIngestService::DIRECTION_TOGGLE);
    }

    /**
     * Ingest one RFID scan from a station reader or hardware adapter.
     *
     * Phase 3: kept for existing callers; the rules live in RfidIngestService.
     * The station decides the direction (Entrance = ENTRY, Exit = EXIT).
     *
     * @param  array<string, mixed>  $data
     */
    public function ingest(array $data, string $sourceMode = 'simulated'): RfidScanLog
    {
        return $this->rfidIngestService->ingest($data, $sourceMode)->scanLog;
    }

    /**
     * Get recent RFID scans for the admin page or portal views.
     *
     * @return Collection<int, RfidScanLog>
     */
    public function recentScans(int $limit = 10, ?string $scanLocation = null): Collection
    {
        return RfidScanLog::query()
            ->with(['vehicle', 'vehicleRfidTag', 'correlatedVehicleEvent.camera'])
            ->with('guestVehicleObservation.camera')
            ->when($scanLocation, fn ($query) => $query->where('scan_location', $scanLocation))
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Find a scanner bounce so one physical read does not create two movements.
     */
    public function recentDuplicateScan(
        string $tagUid,
        string $scanLocation,
        int $withinSeconds = 8,
        ?string $sourceMode = null
    ): ?RfidScanLog {
        $uid = RfidTag::normalizeUid($tagUid);

        if ($uid === '') {
            return null;
        }

        return RfidScanLog::query()
            ->with(['vehicle.rfidTag', 'vehicleRfidTag', 'correlatedVehicleEvent', 'guestVehicleObservation'])
            ->where('tag_uid', $uid)
            ->where('scan_location', $scanLocation === 'exit' ? 'exit' : 'entrance')
            ->when($sourceMode, fn ($query) => $query->where('source_mode', $sourceMode))
            ->where('created_at', '>=', now()->subSeconds($withinSeconds))
            ->latest('created_at')
            ->first();
    }

    /**
     * Searchable RFID scan history for the RFID Desk table.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, RfidScanLog>
     */
    public function scanHistory(array $filters = [], int $perPage = 12): LengthAwarePaginator
    {
        return RfidScanLog::query()
            ->with(['vehicle', 'vehicleRfidTag', 'correlatedVehicleEvent.camera', 'guestVehicleObservation.camera'])
            ->when(! empty($filters['history_q']), function ($query) use ($filters): void {
                $term = '%'.trim((string) $filters['history_q']).'%';

                $query->where(function ($query) use ($term): void {
                    $query->where('tag_uid', 'like', $term)
                        ->orWhereHas('vehicle', function ($vehicleQuery) use ($term): void {
                            $vehicleQuery->where('plate_number', 'like', $term)
                                ->orWhere('vehicle_owner_name', 'like', $term)
                                ->orWhere('owner_name', 'like', $term);
                        })
                        ->orWhereHas('vehicleRfidTag', function ($tagQuery) use ($term): void {
                            $tagQuery->where('uid', 'like', $term)
                                ->orWhere('tag_uid', 'like', $term);
                        });
                });
            })
            ->when(! empty($filters['scan_location']), function ($query) use ($filters): void {
                $query->where('scan_location', $filters['scan_location'] === 'exit' ? 'exit' : 'entrance');
            })
            ->when(! empty($filters['verification_status']), function ($query) use ($filters): void {
                // Phase 4: "anomaly" filters every flagged scan.
                if ($filters['verification_status'] === 'anomaly') {
                    $query->where('is_anomaly', true);

                    return;
                }

                $query->where('verification_status', $filters['verification_status']);
            })
            ->orderByDesc('scan_time')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Get recent verified recurring RFID activity for vehicle status widgets.
     *
     * @return Collection<int, RfidScanLog>
     */
    public function recentRegisteredActivity(int $limit = 10, ?string $scanLocation = null): Collection
    {
        return RfidScanLog::query()
            ->with(['vehicle', 'vehicleRfidTag', 'correlatedVehicleEvent.camera'])
            ->with('guestVehicleObservation.camera')
            ->where('verification_status', 'verified')
            ->where(function ($query): void {
                $query->whereNull('vehicle_category')
                    ->orWhere('vehicle_category', '!=', 'guest');
            })
            ->when($scanLocation, fn ($query) => $query->where('scan_location', $scanLocation))
            ->orderByDesc('scan_time')
            ->limit($limit)
            ->get();
    }

    /**
     * Build small dashboard-friendly RFID counts.
     *
     * @return array<string, int>
     */
    public function stats(): array
    {
        $today = PhilippineTime::todayDateString();
        // Phase 1: one shared inside count for Dashboard, Registry and RFID Desk.
        $inside = $this->vehicleOccupancyService->counts();

        return [
            'registered_vehicles' => Vehicle::query()
                ->where('category', '!=', 'guest')
                ->count(),
            'guest_vehicles' => Vehicle::query()
                ->where('category', 'guest')
                ->count(),
            'vehicles_inside' => $inside['total'],
            'registered_inside' => $inside['registered'],
            'guests_inside' => $inside['guests'],
            'entries_today' => (int) Vehicle::query()
                ->where('category', '!=', 'guest')
                ->whereDate('daily_count_date', $today) // Phase 1: column holds 'Y-m-d 00:00:00'
                ->sum('entries_today_count'),
            'exits_today' => (int) Vehicle::query()
                ->where('category', '!=', 'guest')
                ->whereDate('daily_count_date', $today) // Phase 1: column holds 'Y-m-d 00:00:00'
                ->sum('exits_today_count'),
            'registered_tags' => RfidTag::query()->count(),
            'available_tags' => RfidTag::query()
                ->where('status', RfidTag::STATUS_AVAILABLE)
                ->count(),
            'scans_today' => RfidScanLog::query()
                ->where(fn ($query) => PhilippineTime::constrainTodayAny($query, ['scan_time', 'created_at']))
                ->count(),
            'registered_scans_today' => RfidScanLog::query()
                ->where(fn ($query) => PhilippineTime::constrainTodayAny($query, ['scan_time', 'created_at']))
                ->where('verification_status', 'verified')
                ->where(function ($query): void {
                    $query->whereNull('vehicle_category')
                        ->orWhere('vehicle_category', '!=', 'guest');
                })
                ->count(),
            'verified_today' => RfidScanLog::query()
                ->where(fn ($query) => PhilippineTime::constrainTodayAny($query, ['scan_time', 'created_at']))
                ->where('verification_status', 'verified')
                ->count(),
            // Phase 4: "Needs Attention" = flagged anomalies and lost/disabled pass alerts.
            'attention_today' => RfidScanLog::query()
                ->where(fn ($query) => PhilippineTime::constrainTodayAny($query, ['scan_time', 'created_at']))
                ->where('is_anomaly', true)
                ->count(),
            'simulated_today' => RfidScanLog::query()
                ->where(fn ($query) => PhilippineTime::constrainTodayAny($query, ['scan_time', 'created_at']))
                ->where('source_mode', 'simulated')
                ->count(),
        ];
    }
}
