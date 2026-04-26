<?php

namespace App\Services;

use App\Models\Vehicle;
use App\Models\VehicleRfidTag;
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
            $vehicle = Vehicle::query()->updateOrCreate(
                ['plate_number' => $this->normalizePlate((string) $data['plate_number'])],
                [
                    'owner_name' => $data['owner_name'] ?: null,
                    'category' => $data['category'] ?? 'faculty_staff',
                    'vehicle_type' => $data['vehicle_type'],
                    'vehicle_color' => $data['vehicle_color'],
                    'status' => $data['status'] ?? 'active',
                    'notes' => $data['notes'] ?: null,
                ]
            );

            $category = $data['category'] ?? $vehicle->category;

            if ($category === 'guest' && filled($data['tag_uid'] ?? null)) {
                throw ValidationException::withMessages([
                    'tag_uid' => 'Guest vehicles should use CCTV/manual guest observation without RFID tag assignment.',
                ]);
            }

            if ($category === 'guest') {
                VehicleRfidTag::query()
                    ->where('vehicle_id', $vehicle->id)
                    ->delete();
            }

            if (filled($data['tag_uid'] ?? null) && in_array($category, Vehicle::RFID_RECURRING_CATEGORIES, true)) {
                VehicleRfidTag::query()->updateOrCreate(
                    ['tag_uid' => $this->normalizeTagUid((string) $data['tag_uid'])],
                    [
                        'vehicle_id' => $vehicle->id,
                        'tag_label' => $data['tag_label'] ?: null,
                        'status' => $data['tag_status'] ?? 'active',
                        'assigned_at' => now(),
                    ]
                );
            }

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
        return ['Car', 'Motorcycle', 'Van', 'Truck', 'Bus'];
    }

    /**
     * Shared vehicle categories for the parking-focused RFID workflow.
     *
     * @return list<string>
     */
    public function vehicleCategories(): array
    {
        return ['parent', 'student', 'faculty_staff', 'guard', 'guest'];
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
}
