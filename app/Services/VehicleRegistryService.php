<?php

namespace App\Services;

use App\Models\RfidTag;
use App\Models\Vehicle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VehicleRegistryService
{
    /**
     * Register or update one vehicle together with an optional RFID tag.
     *
     * @param  array<string, mixed>  $data
     */
    public function register(array $data): Vehicle
    {
        return DB::transaction(function () use ($data): Vehicle {
            $tag = $this->resolveAssignableTag($data);

            $vehicle = Vehicle::query()->create([
                'rfid_tag_id' => $tag->id,
                'rfid_tag_uid' => $tag->uid,
                'plate_number' => $this->normalizePlate((string) $data['plate_number']),
                'vehicle_owner_name' => $this->normalizeOwnerName($data['vehicle_owner_name'] ?? null),
                'category' => $this->normalizeCategory((string) ($data['category'] ?? 'faculty_staff')),
                'vehicle_type' => $this->normalizeVehicleType((string) $data['vehicle_type']),
            ]);

            $this->assignTagToVehicle($tag, $vehicle);

            return $vehicle->fresh(['rfidTag', 'rfidTags']);
        });
    }

    /**
     * Update one existing registered vehicle and optionally assign or update a tag.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Vehicle $vehicle, array $data): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $data): Vehicle {
            $tag = $this->resolveAssignableTag($data, $vehicle);

            $vehicle->fill([
                'rfid_tag_id' => $tag->id,
                'rfid_tag_uid' => $tag->uid,
                'plate_number' => $this->normalizePlate((string) $data['plate_number']),
                'vehicle_owner_name' => $this->normalizeOwnerName($data['vehicle_owner_name'] ?? null),
                'category' => $this->normalizeCategory((string) ($data['category'] ?? 'faculty_staff')),
                'vehicle_type' => $this->normalizeVehicleType((string) $data['vehicle_type']),
            ])->save();

            $this->assignTagToVehicle($tag, $vehicle);

            return $vehicle->fresh(['rfidTag', 'rfidTags']);
        });
    }

    /**
     * Get registered vehicles for the admin page.
     *
     * @return Collection<int, Vehicle>
     */
    public function registeredVehicles(): Collection
    {
        return Vehicle::query()
            ->with(['rfidTag', 'rfidTags'])
            ->withCount([
                'rfidScanLogs',
                'vehicleEvents as total_entries_count' => fn ($query) => $query->where('event_type', 'ENTRY'),
                'vehicleEvents as total_exits_count' => fn ($query) => $query->where('event_type', 'EXIT'),
            ])
            ->orderBy('plate_number')
            ->get();
    }

    /**
     * Get every RFID tag registered in the local inventory pool.
     *
     * @return Collection<int, RfidTag>
     */
    public function rfidTagInventory(): Collection
    {
        $query = RfidTag::query()
            ->with('vehicle')
            ->withCount('scanLogs')
            ->orderByRaw(
                "CASE status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END",
                [RfidTag::STATUS_AVAILABLE, RfidTag::STATUS_ASSIGNED]
            );

        return $this->orderTagsByNumberThenUid($query)->get();
    }

    /**
     * Get tag options for RFID simulation forms.
     *
     * @return Collection<int, RfidTag>
     */
    public function registeredTags(?string $search = null): Collection
    {
        $query = RfidTag::query()
            ->with('vehicle')
            ->assigned()
            ->when(filled($search), function ($query) use ($search): void {
                $term = '%'.trim((string) $search).'%';

                $query->where(function ($query) use ($term): void {
                    $query->where('uid', 'like', $term)
                        ->orWhere('tag_uid', 'like', $term)
                        ->orWhereHas('vehicle', function ($vehicleQuery) use ($term): void {
                            $vehicleQuery->where('plate_number', 'like', $term)
                                ->orWhere('vehicle_owner_name', 'like', $term)
                                ->orWhere('owner_name', 'like', $term);
                        });
                });
            });

        return $this->orderTagsByNumberThenUid($query)->get();
    }

    /**
     * Get tags that can still be assigned to a vehicle.
     *
     * @return Collection<int, RfidTag>
     */
    public function availableTags(): Collection
    {
        $query = RfidTag::query()
            ->with('vehicle')
            ->available();

        return $this->orderTagsByNumberThenUid($query)->get();
    }

    /**
     * Resolve one registered vehicle from a plate number when possible.
     */
    public function resolveVehicleByPlate(?string $plateNumber): ?Vehicle
    {
        if (blank($plateNumber)) {
            return null;
        }

        return Vehicle::query()
            ->where('plate_number', $this->normalizePlate((string) $plateNumber))
            ->first();
    }

    /**
     * Shared vehicle types for registry and event forms.
     *
     * @return list<string>
     */
    public function vehicleTypes(): array
    {
        return ['Car', 'Motorcycle', 'Truck', 'Bus'];
    }

    /**
     * Shared vehicle categories for the vehicle-focused RFID workflow.
     *
     * @return list<string>
     */
    public function vehicleCategories(): array
    {
        return ['parent', 'student', 'faculty_staff', 'guard'];
    }

    /**
     * Shared vehicle colors for registry and event forms.
     *
     * @return list<string>
     */
    public function vehicleColors(): array
    {
        return ['White', 'Black', 'Silver', 'Gray', 'Blue', 'Red', 'Green', 'Yellow'];
    }

    /**
     * Normalize plate numbers for storage and later matching.
     */
    public function normalizePlate(string $plateNumber): string
    {
        $normalized = preg_replace('/\s+/', ' ', $plateNumber) ?? $plateNumber;

        return Str::upper(trim($normalized));
    }

    /**
     * Normalize raw RFID UIDs for storage and lookup.
     */
    public function normalizeTagUid(string $tagUid): string
    {
        return RfidTag::normalizeUid($tagUid);
    }

    public function normalizeOwnerName(mixed $ownerName): ?string
    {
        if (blank($ownerName)) {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', (string) $ownerName) ?? (string) $ownerName;

        return trim($normalized);
    }

    public function normalizeCategory(string $category): string
    {
        $category = trim($category);

        return $category !== '' ? $category : 'faculty_staff';
    }

    public function normalizeVehicleType(string $vehicleType): string
    {
        $vehicleType = trim($vehicleType);

        return $vehicleType !== '' ? Str::title($vehicleType) : 'Car';
    }

    /**
     * Get available tags plus this vehicle's current tag for edit screens.
     *
     * @return Collection<int, RfidTag>
     */
    public function assignableTagsFor(?Vehicle $vehicle = null): Collection
    {
        $query = RfidTag::query()
            ->with('vehicle')
            ->where(function ($query) use ($vehicle): void {
                $query->where('status', RfidTag::STATUS_AVAILABLE);

                if ($vehicle?->rfid_tag_id) {
                    $query->orWhere('id', $vehicle->rfid_tag_id);
                }

                if ($vehicle?->id) {
                    $query->orWhere(function ($query) use ($vehicle): void {
                        $query->where('vehicle_id', $vehicle->id)
                            ->where('status', RfidTag::STATUS_ASSIGNED);
                    });
                }
            });

        return $this->orderTagsByNumberThenUid($query)->get();
    }

    /**
     * Register one RFID UID in the inventory before vehicle assignment.
     *
     * @param  array<string, mixed>  $data
     */
    public function registerRfidTag(array $data): RfidTag
    {
        return DB::transaction(function () use ($data): RfidTag {
            $uid = $this->normalizeTagUid((string) ($data['uid'] ?? $data['rfid_uid'] ?? $data['tag_uid'] ?? ''));
            $tagNumber = $this->normalizeTagNumber($data['tag_number'] ?? null);

            if ($uid === '') {
                throw ValidationException::withMessages([
                    'uid' => 'Scan an RFID UID first.',
                ]);
            }

            if ($tagNumber === null) {
                throw ValidationException::withMessages([
                    'tag_number' => 'Enter the RFID tag number before registering the scanned UID.',
                ]);
            }

            $existing = RfidTag::query()
                ->where('uid', $uid)
                ->orWhere('tag_uid', $uid)
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'uid' => 'This RFID tag is already registered in the inventory.',
                ]);
            }

            $existingNumber = RfidTag::query()
                ->where('tag_number', $tagNumber)
                ->first();

            if ($existingNumber) {
                throw ValidationException::withMessages([
                    'tag_number' => 'This RFID tag number is already used in the inventory.',
                ]);
            }

            return RfidTag::query()->create([
                'tag_number' => $tagNumber,
                'uid' => $uid,
                'status' => RfidTag::STATUS_AVAILABLE,
            ]);
        });
    }

    /**
     * Resolve and validate an inventory tag selected for vehicle assignment.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveAssignableTag(array $data, ?Vehicle $vehicle = null): RfidTag
    {
        if (! empty($data['rfid_tag_id'])) {
            $tag = RfidTag::query()
                ->lockForUpdate()
                ->find((int) $data['rfid_tag_id']);
        } else {
            $uid = $this->normalizeTagUid((string) ($data['rfid_uid'] ?? $data['rfid_tag_uid'] ?? $data['tag_uid'] ?? ''));

            $tag = RfidTag::query()
                ->lockForUpdate()
                ->where('uid', $uid)
                ->orWhere('tag_uid', $uid)
                ->first();

            if (! $tag && $uid !== '') {
                throw ValidationException::withMessages([
                    'rfid_uid' => 'Register this RFID tag in the inventory before assigning it to a vehicle.',
                ]);
            }
        }

        if (! $tag) {
            throw ValidationException::withMessages([
                'rfid_tag_id' => 'Choose an available RFID tag from the inventory.',
            ]);
        }

        $belongsToCurrentVehicle = $vehicle
            && (int) $tag->vehicle_id === (int) $vehicle->id
            && $tag->status === RfidTag::STATUS_ASSIGNED;

        if ($tag->status !== RfidTag::STATUS_AVAILABLE && ! $belongsToCurrentVehicle) {
            throw ValidationException::withMessages([
                'rfid_uid' => 'The selected RFID tag is already assigned to another vehicle.',
            ]);
        }

        return $tag;
    }

    protected function assignTagToVehicle(RfidTag $tag, Vehicle $vehicle): void
    {
        RfidTag::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('id', '!=', $tag->id)
            ->where('status', RfidTag::STATUS_ASSIGNED)
            ->update([
                'vehicle_id' => null,
                'status' => RfidTag::STATUS_AVAILABLE,
                'assigned_at' => null,
            ]);

        $tag->forceFill([
            'vehicle_id' => $vehicle->id,
            'status' => RfidTag::STATUS_ASSIGNED,
            'assigned_at' => $tag->assigned_at ?: now(),
        ])->save();

        if ((int) $vehicle->rfid_tag_id !== (int) $tag->id || $vehicle->rfid_tag_uid !== $tag->uid) {
            $vehicle->forceFill([
                'rfid_tag_id' => $tag->id,
                'rfid_tag_uid' => $tag->uid,
            ])->save();
        }
    }

    protected function normalizeTagNumber(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $number = (int) $value;

        return $number > 0 ? $number : null;
    }

    protected function orderTagsByNumberThenUid($query)
    {
        return $query
            ->orderByRaw('CASE WHEN tag_number IS NULL THEN 1 ELSE 0 END')
            ->orderBy('tag_number')
            ->orderBy('uid');
    }
}
