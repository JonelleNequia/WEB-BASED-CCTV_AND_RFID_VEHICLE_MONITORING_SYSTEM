<?php

namespace App\Services;

use App\Models\GuestVehicleObservation;
use App\Models\Gate;
use App\Support\PlateNumber;
use App\Support\PhilippineTime;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
            $plateNumber = $this->normalizePlate($data['plate_number'] ?? $data['plate_text'] ?? null);

            return GuestVehicleObservation::query()->create([
                'plate_text' => $plateNumber,
                'plate_number' => $plateNumber,
                'vehicle_type' => $data['vehicle_type'] ?? null,
                'vehicle_color' => $data['vehicle_color'] ?? null,
                'location' => $this->normalizeLocation($data['location'] ?? ''),
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
                $plate = '%'.trim((string) $filters['plate_text']).'%';

                $query->where(function ($query) use ($plate): void {
                    $query->where('plate_number', 'like', $plate)
                        ->orWhere('plate_text', 'like', $plate);
                });
            })
            ->when(! empty($filters['location']), function ($query) use ($filters): void {
                $query->where('location', $this->normalizeLocation($filters['location']));
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
            ->orderByDesc('created_at')
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
            ->where(fn ($query) => PhilippineTime::constrainTodayAny($query, ['observed_at', 'created_at']))
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

        return $file->store('guest_snapshots', 'public');
    }

    /**
     * Normalize plate text for consistent search and display.
     */
    protected function normalizePlate(?string $plate): ?string
    {
        return PlateNumber::normalize($plate);
    }

    /**
     * Phase 1: a gate code; the old "entrance"/"exit" mean Gate 1 / Gate 2.
     */
    protected function normalizeLocation(mixed $location): string
    {
        return Gate::normalizeCode((string) $location);
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
