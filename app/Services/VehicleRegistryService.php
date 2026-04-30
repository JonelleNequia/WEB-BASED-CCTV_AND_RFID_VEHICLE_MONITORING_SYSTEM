<?php

namespace App\Services;

use App\Models\Vehicle;
use App\Models\RfidTag;
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
                'vehicle_owner_name' => $data['vehicle_owner_name'] ?: null,
                'category' => $data['category'] ?? 'faculty_staff',
                'vehicle_type' => $data['vehicle_type'],
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
                'vehicle_owner_name' => $data['vehicle_owner_name'] ?: null,
                'category' => $data['category'] ?? 'faculty_staff',
                'vehicle_type' => $data['vehicle_type'],
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
     * Get tag options for RFID simulation forms.
     *
     * @return Collection<int, RfidTag>
     */
    public function registeredTags(): Collection
    {
        return RfidTag::query()
            ->with('vehicle')
            ->assigned()
            ->orderBy('uid')
            ->get();
    }

    /**
     * Get tags that can still be assigned to a vehicle.
     *
     * @return Collection<int, RfidTag>
     */
    public function availableTags(): Collection
    {
        return RfidTag::query()
            ->available()
            ->orderBy('uid')
            ->get();
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
        return ['Car', 'Motorcycle', 'Bus'];
    }

    /**
     * Shared vehicle categories for the parking-focused RFID workflow.
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

    /**
     * Get available tags plus this vehicle's current tag for edit screens.
     *
     * @return Collection<int, RfidTag>
     */
    public function assignableTagsFor(?Vehicle $vehicle = null): Collection
    {
        return RfidTag::query()
            ->with('vehicle')
            ->where('status', RfidTag::STATUS_AVAILABLE)
            ->when($vehicle?->rfid_tag_id, function ($query) use ($vehicle): void {
                $query->orWhereKey($vehicle->rfid_tag_id);
            })
            ->orderBy('uid')
            ->get();
    }

    /**
     * Resolve and validate the tag selected from the offline inventory.
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
            $uid = $this->normalizeTagUid((string) ($data['rfid_tag_uid'] ?? $data['tag_uid'] ?? ''));

            $tag = RfidTag::query()
                ->lockForUpdate()
                ->where('uid', $uid)
                ->orWhere('tag_uid', $uid)
                ->first();

            if (! $tag && $uid !== '') {
                $tag = RfidTag::query()->create([
                    'uid' => $uid,
                    'status' => RfidTag::STATUS_AVAILABLE,
                ]);
            }
        }

        if (! $tag) {
            throw ValidationException::withMessages([
                'rfid_tag_id' => 'Select an available RFID tag from the inventory.',
            ]);
        }

        $belongsToCurrentVehicle = $vehicle
            && (int) $tag->vehicle_id === (int) $vehicle->id
            && $tag->status === RfidTag::STATUS_ASSIGNED;

        if ($tag->status !== RfidTag::STATUS_AVAILABLE && ! $belongsToCurrentVehicle) {
            throw ValidationException::withMessages([
                'rfid_tag_id' => 'The selected RFID tag is not available.',
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
}
