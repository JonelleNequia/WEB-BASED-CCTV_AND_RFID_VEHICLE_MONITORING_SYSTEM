<?php

namespace App\Services;

use App\Models\Vehicle;
use App\Models\VehicleRfidTag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            $vehicle = Vehicle::query()->create([
                'rfid_tag_uid' => $this->normalizeTagUid((string) $data['rfid_tag_uid']),
                'plate_number' => $this->normalizePlate((string) $data['plate_number']),
                'vehicle_owner_name' => $data['vehicle_owner_name'] ?: null,
                'category' => $data['category'] ?? 'faculty_staff',
                'vehicle_type' => $data['vehicle_type'],
            ]);

            $this->syncTag($vehicle);

            return $vehicle->fresh(['rfidTags']);
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
            $vehicle->fill([
                'rfid_tag_uid' => $this->normalizeTagUid((string) $data['rfid_tag_uid']),
                'plate_number' => $this->normalizePlate((string) $data['plate_number']),
                'vehicle_owner_name' => $data['vehicle_owner_name'] ?: null,
                'category' => $data['category'] ?? 'faculty_staff',
                'vehicle_type' => $data['vehicle_type'],
            ])->save();

            $this->syncTag($vehicle);

            return $vehicle->fresh(['rfidTags']);
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
            ->with('rfidTags')
            ->withCount('rfidScanLogs')
            ->orderBy('plate_number')
            ->get();
    }

    /**
     * Get tag options for RFID simulation forms.
     *
     * @return Collection<int, VehicleRfidTag>
     */
    public function registeredTags(): Collection
    {
        return VehicleRfidTag::query()
            ->with('vehicle')
            ->orderBy('tag_uid')
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
        return Str::upper(trim((string) preg_replace('/\s+/', '', $tagUid)));
    }

    protected function syncTag(Vehicle $vehicle): void
    {
        VehicleRfidTag::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('tag_uid', '!=', $vehicle->rfid_tag_uid)
            ->delete();

        VehicleRfidTag::query()->updateOrCreate(
            ['tag_uid' => $vehicle->rfid_tag_uid],
            [
                'vehicle_id' => $vehicle->id,
                'status' => 'active',
                'assigned_at' => now(),
            ]
        );
    }
}
