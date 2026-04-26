<?php

namespace App\Services;

use App\Models\GuestVehicleObservation;
use App\Models\Camera;
use App\Models\RfidScanLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GuestObservationService
{
    public function __construct(
        protected LocalStorageService $localStorageService
    ) {
    }

    /**
     * Store one guest vehicle observation.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?int $userId = null): GuestVehicleObservation
    {
        return DB::transaction(function () use ($data, $userId): GuestVehicleObservation {
            $snapshotPath = $this->storeSnapshot($data['snapshot_image'] ?? null);

            return GuestVehicleObservation::query()->create([
                'plate_text' => $this->normalizePlate($data['plate_text'] ?? null),
                'vehicle_type' => $data['vehicle_type'] ?? null,
                'vehicle_color' => $data['vehicle_color'] ?? null,
                'location' => $data['location'] ?? 'parking',
                'observation_source' => $data['observation_source'] ?? 'manual',
                'observed_at' => isset($data['observed_at'])
                    ? Carbon::parse((string) $data['observed_at'])
                    : now(),
                'camera_id' => $data['camera_id'] ?? null,
                'snapshot_path' => $snapshotPath,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * Create a CCTV-supported guest review record from an unrecognized RFID scan.
     */
    public function createFromUnrecognizedRfidScan(RfidScanLog $scanLog): GuestVehicleObservation
    {
        return DB::transaction(function () use ($scanLog): GuestVehicleObservation {
            $camera = Camera::query()->forRole($scanLog->scan_location)->first();
            $snapshotPath = $this->localStorageService->storeLatestCameraSnapshot($scanLog->scan_location);

            return GuestVehicleObservation::query()->create([
                'plate_text' => null,
                'vehicle_type' => 'Unregistered',
                'vehicle_color' => null,
                'location' => $scanLog->scan_location,
                'observation_source' => 'cctv',
                'observed_at' => $scanLog->scan_time,
                'camera_id' => $camera?->id,
                'snapshot_path' => $snapshotPath,
                'notes' => 'Unrecognized RFID tag '.$scanLog->tag_uid.' scanned at '.$scanLog->scanLocationLabel.'. Guard review required.',
                'created_by' => null,
            ]);
        });
    }

    /**
     * Paginated guest observations for admin pages.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginated(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $dateFrom = $this->parseDate($filters['date_from'] ?? null);
        $dateTo = $this->parseDate($filters['date_to'] ?? null);

        return GuestVehicleObservation::query()
            ->with('camera')
            ->when(! empty($filters['plate_text']), function ($query) use ($filters): void {
                $query->where('plate_text', 'like', '%'.trim((string) $filters['plate_text']).'%');
            })
            ->when(! empty($filters['location']), function ($query) use ($filters): void {
                $query->where('location', $filters['location']);
            })
            ->when(! empty($filters['observation_source']), function ($query) use ($filters): void {
                $query->where('observation_source', $filters['observation_source']);
            })
            ->when($dateFrom !== null, function ($query) use ($dateFrom): void {
                $query->where('observed_at', '>=', $dateFrom->startOfDay());
            })
            ->when($dateTo !== null, function ($query) use ($dateTo): void {
                $query->where('observed_at', '<=', $dateTo->endOfDay());
            })
            ->orderByDesc('observed_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Recent guest observations for dashboard widgets.
     *
     * @return \Illuminate\Support\Collection<int, GuestVehicleObservation>
     */
    public function recent(int $limit = 6)
    {
        return GuestVehicleObservation::query()
            ->with('camera')
            ->orderByDesc('observed_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Count today's guest observations.
     */
    public function countToday(): int
    {
        return GuestVehicleObservation::query()
            ->whereDate('observed_at', today())
            ->count();
    }

    /**
     * Save a guest snapshot on local storage when uploaded.
     */
    protected function storeSnapshot(?UploadedFile $file): ?string
    {
        if (! $file) {
            return null;
        }

        $this->localStorageService->ensureBaseDirectories();

        return $this->localStorageService->storeEventImage($file, 'vehicle');
    }

    /**
     * Normalize plate text for consistent search and display.
     */
    protected function normalizePlate(?string $plate): ?string
    {
        if (blank($plate)) {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', (string) $plate) ?? (string) $plate;

        return Str::upper(trim($normalized));
    }

    /**
     * Parse one date filter safely without throwing.
     */
    protected function parseDate(mixed $value): ?Carbon
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
