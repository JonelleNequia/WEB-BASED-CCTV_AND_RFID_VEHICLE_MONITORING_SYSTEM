<?php

namespace App\Services;

use App\Models\RfidTag;
use App\Models\SystemSetting;
use App\Models\Vehicle;
use App\Rules\RegistrationStaffName;
use App\Support\DisplayTime;
use App\Support\PlateNumber;
use App\Support\VehicleCategory;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class VehicleRegistryService
{
    public const STAFF_NAME_SETTING = 'registration_staff_name';

    public function rememberedStaffName(): string
    {
        return (string) (SystemSetting::query()->where('setting_key', self::STAFF_NAME_SETTING)->value('setting_value') ?? '');
    }

    /**
     * Register one vehicle with staff attribution and an optional real RFID tag.
     *
     * @param  array<string, mixed>  $data
     */
    public function register(array $data): Vehicle
    {
        $data['registered_by'] = RegistrationStaffName::validated($data['registered_by'] ?? null);

        return app(PlateProcessingLock::class)->run(function () use ($data): Vehicle {
            $this->assertUniquePlate((string) $data['plate_number']);
            // UI Phase 3: the Add Vehicle drawer registers a brand-new scanned tag on the spot.
            if (! empty($data['auto_register_tag'])) {
                $this->ensureInventoryTag($data);
            }

            $tag = $this->resolveAssignableTag($data);

            $vehicle = Vehicle::query()->create([
                'rfid_tag_id' => $tag?->id,
                'rfid_tag_uid' => $tag?->uid,
                'plate_number' => $this->normalizePlate((string) $data['plate_number']),
                'vehicle_owner_name' => $this->normalizeOwnerName($data['vehicle_owner_name'] ?? null),
                'registered_by' => $data['registered_by'],
                'category' => $this->normalizeCategory((string) ($data['category'] ?? 'faculty_staff')),
                'vehicle_type' => $this->normalizeVehicleType($data['vehicle_type']),
            ]);

            if ($tag) {
                $this->assignTagToVehicle($tag, $vehicle);
            }

            // Phase 6 (visitor model): "Register this vehicle" (or the same
            // plate seen before as an unregistered visitor): its history moves.
            $moved = app(VisitorRecordService::class)->transferHistoryToVehicle($vehicle, isset($data['plate_profile_id']) ? (int) $data['plate_profile_id'] : null);

            // Use the existing settings table directly: this is part of the
            // registration transaction, with no camera/runtime settings writes.
            SystemSetting::query()->updateOrCreate(
                ['setting_key' => self::STAFF_NAME_SETTING],
                ['setting_value' => $data['registered_by']]
            );

            return tap($vehicle->fresh(['rfidTag', 'rfidTags']), fn (Vehicle $fresh) => $fresh->transferred_visits = $moved);
        });
    }

    /**
     * Update one existing registered vehicle and optionally assign or update a tag.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Vehicle $vehicle, array $data): Vehicle
    {
        return app(PlateProcessingLock::class)->run(function () use ($vehicle, $data): Vehicle {
            $vehicle = Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if (PlateNumber::normalize($data['plate_number']) !== PlateNumber::normalize($vehicle->plate_number)) {
                $this->assertUniquePlate((string) $data['plate_number'], $vehicle->id);
            }
            $supplied = filled($data['rfid_tag_id'] ?? null) || filled($data['rfid_uid'] ?? $data['rfid_tag_uid'] ?? null);
            if ((int) ($data['rfid_tag_id'] ?? 0) === (int) $vehicle->rfid_tag_id && $vehicle->rfid_tag_id
                && ! filled($data['rfid_uid'] ?? null)) {
                $supplied = false; // Preserve an unchanged historical current tag, including lost.
            }
            $tag = $supplied ? $this->resolveAssignableTag($data, $vehicle) : null;

            $vehicle->fill([
                'rfid_tag_id' => $tag?->id ?? $vehicle->rfid_tag_id,
                'rfid_tag_uid' => $tag?->uid ?? $vehicle->rfid_tag_uid,
                'plate_number' => $this->normalizePlate((string) $data['plate_number']),
                'vehicle_owner_name' => $this->normalizeOwnerName($data['vehicle_owner_name'] ?? null),
                'category' => $this->normalizeCategory((string) ($data['category'] ?? 'faculty_staff')),
                'vehicle_type' => \App\Support\VehicleType::validated($data['vehicle_type'], $vehicle->vehicle_type),
            ])->save();

            if ($tag) {
                $this->assignTagToVehicle($tag, $vehicle);
            }

            // Phase 6: a corrected plate brings that plate's earlier visits with it.
            app(VisitorRecordService::class)->transferHistoryToVehicle($vehicle);

            return $vehicle->fresh(['rfidTag', 'rfidTags']);
        });
    }

    /**
     * Get registered vehicles for the admin page.
     *
     * @return Collection<int, Vehicle>
     */
    public function registeredVehicles(array $filters = []): Collection
    {
        $search = trim((string) ($filters['q'] ?? ''));

        return Vehicle::query()
            ->with(['rfidTag', 'rfidTags'])
            // UI Phase 3: Registry › Vehicles toolbar (search + filters).
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.$search.'%';
                $query->where(function ($query) use ($term, $search): void {
                    $query->where('plate_number', 'like', $term)
                        ->orWhere(fn ($plates) => PlateNumber::search($plates, ['plate_number'], $search))
                        ->orWhere('vehicle_owner_name', 'like', $term)
                        ->orWhere('owner_name', 'like', $term)
                        ->orWhere('rfid_tag_uid', 'like', $term)
                        ->orWhereHas('rfidTags', fn ($tagQuery) => $tagQuery->where('uid', 'like', $term)->orWhere('tag_number', 'like', $term));
                });
            })
            ->when(filled($filters['category'] ?? null), fn ($query) => $query->where('category', $filters['category']))
            ->when(filled($filters['state'] ?? null), fn ($query) => $query->where('current_state', strtoupper((string) $filters['state'])))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->withCount([
                'rfidScanLogs',
                'vehicleEvents as total_entries_count' => fn ($query) => $query->where('event_type', 'ENTRY')->where('event_status', \App\Models\VehicleEvent::STATUS_COMPLETED),
                'vehicleEvents as total_exits_count' => fn ($query) => $query->where('event_type', 'EXIT')->where('event_status', \App\Models\VehicleEvent::STATUS_COMPLETED),
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
            ->vehicleTags()
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
            ->vehicleTags()
            ->available();

        return $this->orderTagsByNumberThenUid($query)->get();
    }

    /**
     * Resolve one registered vehicle from a plate number when possible.
     */
    public function resolveVehicleByPlate(?string $plateNumber): ?Vehicle
    {
        $matches = $this->plateMatches($plateNumber);

        return $matches->count() === 1 && $matches->first()->status === 'active' ? $matches->first() : null;
    }

    /** Exact keys only; historical collisions are returned, never picked. */
    public function plateMatches(?string $plate, ?\Carbon\CarbonInterface $at = null): Collection
    {
        $key = PlateNumber::key($plate);
        if ($key === null) {
            return collect();
        }

        return Vehicle::query()->when($at, fn ($query) => $query->where('created_at', '<=', $at))->get()
            ->filter(fn (Vehicle $vehicle) => in_array(VehicleCategory::normalize($vehicle->category), VehicleCategory::REGISTRY, true)
                && PlateNumber::key($vehicle->plate_number) === $key)->values();
    }

    public function assertUniquePlate(string $plate, ?int $except = null): void
    {
        \Illuminate\Support\Facades\Validator::make(['plate_number' => PlateNumber::normalize($plate)], [
            'plate_number' => ['required', 'string', 'max:50', new \App\Rules\PlateIdentifier],
        ])->validate();
        $key = PlateNumber::key($plate);
        if (Vehicle::query()->when($except, fn ($query) => $query->whereKeyNot($except))->get()
            ->contains(fn (Vehicle $vehicle) => PlateNumber::key($vehicle->plate_number) === $key)) {
            throw ValidationException::withMessages(['plate_number' => 'This plate matches an existing registration after normalization. Review the existing registration; records will not be merged.']);
        }
    }

    /**
     * Shared vehicle types for registry and event forms.
     *
     * @return list<string>
     */
    public function vehicleTypes(): array
    {
        return \App\Support\VehicleType::TYPES;
    }

    /**
     * Shared vehicle categories for the vehicle-focused RFID workflow.
     *
     * @return list<string>
     */
    public function vehicleCategories(): array
    {
        // Phase 4 (visitor model): Unregistered Visitor is set by the system.
        return VehicleCategory::REGISTRY;
    }

    /**
     * Normalize plate numbers for storage and later matching.
     */
    public function normalizePlate(string $plateNumber): string
    {
        return (string) PlateNumber::normalize($plateNumber);
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

        return $category !== '' ? VehicleCategory::normalize($category) : VehicleCategory::FACULTY_STAFF;
    }

    public function normalizeVehicleType(mixed $vehicleType): string
    {
        return \App\Support\VehicleType::validated($vehicleType);
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
            ->vehicleTags()
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

        return $this->orderTagsByNumberThenUid($query)->get()->filter(fn (RfidTag $tag) =>
            ($vehicle && (int) $tag->id === (int) $vehicle->rfid_tag_id) || ! RfidTag::isUnavailableUid((string) $tag->uid))->values();
    }

    /**
     * Register one RFID UID in the inventory before vehicle assignment.
     *
     * @param  array<string, mixed>  $data
     */
    public function registerRfidTag(array $data): RfidTag
    {
        return app(PlateProcessingLock::class)->run(function () use ($data): RfidTag {
            $uid = $this->normalizeTagUid((string) ($data['uid'] ?? $data['rfid_uid'] ?? $data['tag_uid'] ?? ''));
            $tagNumber = $this->normalizeTagNumber($data['tag_number'] ?? null);

            if ($uid === '') {
                throw ValidationException::withMessages([
                    'uid' => 'Scan an RFID UID first.',
                ]);
            }
            if (RfidTag::isUnavailableUid($uid)) {
                throw ValidationException::withMessages(['uid' => 'This UID is permanently Lost / Unavailable. Use a different tag.']);
            }

            // UI Phase 3: bulk scanning and Add Vehicle number new tags automatically.
            if ($tagNumber === null && ! empty($data['auto_number'])) {
                $tagNumber = $this->nextTagNumber();
            }

            if ($tagNumber === null) {
                throw ValidationException::withMessages([
                    'tag_number' => 'Enter the RFID tag number before registering the scanned UID.',
                ]);
            }

            $existing = RfidTag::matchingUid($uid)->isNotEmpty();

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
                'tag_type' => RfidTag::TYPE_VEHICLE,
            ]);
        });
    }

    /**
     * Resolve and validate an inventory tag selected for vehicle assignment.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveAssignableTag(array $data, ?Vehicle $vehicle = null): ?RfidTag
    {
        if (! empty($data['rfid_tag_id'])) {
            $tag = RfidTag::query()
                ->lockForUpdate()
                ->find((int) $data['rfid_tag_id']);
        } else {
            $uid = $this->normalizeTagUid((string) ($data['rfid_uid'] ?? $data['rfid_tag_uid'] ?? $data['tag_uid'] ?? ''));
            if ($uid === '') {
                return null;
            }

            $tag = RfidTag::findUid($uid);

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
        if (RfidTag::isUnavailableUid((string) $tag->uid)) {
            throw ValidationException::withMessages(['rfid_uid' => 'This tag is permanently Lost / Unavailable. Use a different tag.']);
        }
        if ($tag->vehicle_id && (! $vehicle || (int) $tag->vehicle_id !== (int) $vehicle->id)) {
            throw ValidationException::withMessages(['rfid_uid' => 'This tag retains an assignment to another vehicle.']);
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

    /**
     * UI Phase 3: next free inventory number for auto-registered tags.
     */
    public function nextTagNumber(): int
    {
        return ((int) RfidTag::query()->max('tag_number')) + 1;
    }

    /**
     * UI Phase 3: what happens if this UID is used in the Add Vehicle /
     * Replace Tag drawers.
     *
     * @return array{state: string, ok: bool, message: string, tag: array<string, mixed>|null}
     */
    public function lookupTagForVehicle(string $uid, ?Vehicle $vehicle = null): array
    {
        $uid = $this->normalizeTagUid($uid);

        if ($uid === '') {
            return ['state' => 'empty', 'ok' => false, 'message' => 'Scan a tag first.', 'tag' => null];
        }

        if (RfidTag::isUnavailableUid($uid)) {
            return ['state' => 'lost', 'ok' => false, 'message' => 'Lost / Unavailable permanently. Use a different RFID tag.', 'tag' => null];
        }
        $tag = RfidTag::findUid($uid);
        if (! $tag && RfidTag::matchingUid($uid)->isNotEmpty()) {
            return ['state' => 'conflict', 'ok' => false, 'message' => 'Multiple legacy tags share this canonical UID. Review inventory; no tag was selected.', 'tag' => null];
        }

        if (! $tag) {
            return [
                'state' => 'new',
                'ok' => true,
                'message' => 'New tag. It will be added to the inventory as #'.$this->nextTagNumber().' and assigned.',
                'tag' => ['uid' => $uid],
            ];
        }

        $summary = ['id' => $tag->id, 'uid' => $tag->uid, 'tag_number' => $tag->tag_number, 'label' => $tag->label];

        [$state, $ok, $message] = match (true) {
            $vehicle && (int) $tag->vehicle_id === (int) $vehicle->id && $tag->status === RfidTag::STATUS_ASSIGNED
                => ['current', false, 'This is already the current tag of '.$vehicle->plate_number.'.'],
            $tag->status === RfidTag::STATUS_ASSIGNED => ['assigned', false, $tag->label.' is already assigned to '.($tag->vehicle?->plate_number ?? 'another vehicle').'. Use Replace Tag on that vehicle first.'],
            $tag->status === RfidTag::STATUS_DISABLED
                => [$tag->status, false, $tag->label.' is marked '.strtoupper($tag->status).'. Enable it in Registry › RFID Tags before reusing it.'],
            default => ['available', true, $tag->label.' is available and will be assigned.'],
        };

        return ['state' => $state, 'ok' => $ok, 'message' => $message, 'tag' => $summary];
    }

    /**
     * Assign or replace a tag. Lost UIDs remain permanently unavailable;
     * the existing disabled-tag policy and previous relationships are retained.
     *
     * @param  array<string, mixed>  $data  rfid_tag_id | rfid_uid, old_tag_status
     */
    public function replaceTag(Vehicle $vehicle, array $data): Vehicle
    {
        $expected = (int) ($data['expected_tag_id'] ?? $vehicle->rfid_tag_id);
        return app(PlateProcessingLock::class)->run(function () use ($vehicle, $data, $expected): Vehicle {
            $vehicle = Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if ((int) $vehicle->rfid_tag_id !== $expected) {
                throw ValidationException::withMessages(['rfid_uid' => 'RFID assignment changed while this form was open. Reload the vehicle before assigning or replacing its tag.']);
            }
            $oldTag = $vehicle->rfidTag;
            $oldStatus = in_array($data['old_tag_status'] ?? null, [RfidTag::STATUS_LOST, RfidTag::STATUS_DISABLED], true)
                ? $data['old_tag_status']
                : RfidTag::STATUS_LOST;

            $this->ensureInventoryTag($data);
            $newTag = $this->resolveAssignableTag($data, $vehicle);
            if (! $newTag) {
                throw ValidationException::withMessages(['rfid_uid' => 'Choose or scan an available RFID tag.']);
            }

            if ($oldTag && (int) $oldTag->id === (int) $newTag->id) {
                throw ValidationException::withMessages([
                    'rfid_uid' => 'Scan or choose a different tag than the current one.',
                ]);
            }

            // Keep vehicle_id on the old tag so its scans show whose tag it was.
            if ($oldTag && ! RfidTag::isUnavailableUid((string) $oldTag->uid)) {
                $oldTag->forceFill(['status' => $oldStatus])->save();
            }

            $newTag->forceFill([
                'vehicle_id' => $vehicle->id,
                'status' => RfidTag::STATUS_ASSIGNED,
                'assigned_at' => now(),
            ])->save();

            $vehicle->forceFill([
                'rfid_tag_id' => $newTag->id,
                'rfid_tag_uid' => $newTag->uid,
            ])->save();

            return $vehicle->fresh(['rfidTag', 'rfidTags']);
        });
    }

    /**
     * UI Phase 3: Deactivate / Activate. An inactive vehicle keeps its tag;
     * scans of it are flagged (inactive_vehicle).
     */
    public function setVehicleStatus(Vehicle $vehicle, string $status): Vehicle
    {
        if (! in_array($status, ['active', 'inactive'], true)) {
            throw ValidationException::withMessages(['status' => 'Unknown vehicle status.']);
        }

        return app(PlateProcessingLock::class)->run(function () use ($vehicle, $status): Vehicle {
            $vehicle = Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            $vehicle->forceFill(['status' => $status])->save();

            return $vehicle->fresh();
        });
    }

    /**
     * Mark lost permanently, disable, or re-enable a non-lost disabled tag.
     */
    public function setTagStatus(RfidTag $tag, string $status): RfidTag
    {
        return app(PlateProcessingLock::class)->run(function () use ($tag, $status): RfidTag {
            $tag = RfidTag::query()->with('vehicle')->whereKey($tag->id)->lockForUpdate()->firstOrFail();
            if (RfidTag::isUnavailableUid((string) $tag->uid) && $status !== RfidTag::STATUS_LOST) {
                throw ValidationException::withMessages(['status' => 'Lost / Unavailable tags cannot be reactivated.']);
            }

            if (! in_array($status, [RfidTag::STATUS_LOST, RfidTag::STATUS_DISABLED, RfidTag::STATUS_AVAILABLE], true)) {
                throw ValidationException::withMessages(['status' => 'Unknown tag status.']);
            }

            if ($status === RfidTag::STATUS_AVAILABLE) {
                // Enabling the vehicle's current tag puts it back as assigned; otherwise it returns to the pool.
                $isCurrentVehicleTag = $tag->vehicle && (int) $tag->vehicle->rfid_tag_id === (int) $tag->id;

                $tag->forceFill($isCurrentVehicleTag
                    ? ['status' => RfidTag::STATUS_ASSIGNED]
                    : ['status' => RfidTag::STATUS_AVAILABLE, 'vehicle_id' => null, 'assigned_at' => null])->save();

                return $tag->fresh();
            }

            $tag->forceFill(['status' => $status])->save();

            return $tag->fresh();
        });
    }

    /**
     * UI Phase 3: vehicle side panel (details + last 10 movements).
     *
     * @return array<string, mixed>
     */
    public function vehicleDetails(Vehicle $vehicle): array
    {
        $vehicle->loadMissing(['rfidTag', 'rfidTags']);

        $movements = $vehicle->vehicleEvents()
            ->latest('event_time')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn ($event): array => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'time' => DisplayTime::datetime($event->event_time),
                'source' => $event->source_display_label,
                'note' => $event->anomaly_reason,
                'url' => route('vehicle-events.show', $event),
            ])
            ->values();

        return [
            'id' => $vehicle->id,
            'plate_number' => $vehicle->plate_number,
            'vehicle_owner_name' => $vehicle->vehicle_owner_name,
            'registered_by' => $vehicle->registered_by,
            'category' => $vehicle->category,
            'category_label' => VehicleCategory::label($vehicle->category),
            'vehicle_type' => $vehicle->vehicle_type,
            'status' => $vehicle->status,
            'current_state' => strtolower((string) ($vehicle->current_state ?: 'outside')),
            'last_seen' => DisplayTime::datetime($vehicle->last_seen_at, 'Never'),
            'entries_today' => (int) $vehicle->entries_today_count,
            'exits_today' => (int) $vehicle->exits_today_count,
            'tag' => $vehicle->rfidTag ? [
                'id' => $vehicle->rfidTag->id,
                'uid' => $vehicle->rfidTag->uid,
                'tag_number' => $vehicle->rfidTag->tag_number,
                'status' => $vehicle->rfidTag->status,
                'status_label' => $vehicle->rfidTag->status_label,
            ] : null,
            'previous_tags' => $vehicle->rfidTags
                ->reject(fn (RfidTag $tag): bool => (int) $tag->id === (int) $vehicle->rfid_tag_id)
                ->map(fn (RfidTag $tag): array => ['uid' => $tag->uid, 'tag_number' => $tag->tag_number, 'status' => $tag->status, 'status_label' => $tag->status_label])
                ->values(),
            'movements' => $movements,
            // Phase 6 (visitor model): camera visits with no tag read (before
            // registration, moved by "Register this vehicle", or a missed read).
            'camera_visits' => $vehicle->visitorRecords()->active()->count(),
            'plate_profile_url' => ($profileId = \App\Models\PlateProfile::query()->where('vehicle_id', $vehicle->id)->current()->value('id'))
                ? route('visitors.profiles.show', $profileId)
                : null,
            'urls' => [
                'update' => route('vehicle-registry.update', $vehicle),
                'replace_tag' => route('registry.vehicles.replace-tag', $vehicle),
                'status' => route('registry.vehicles.status', $vehicle),
            ],
        ];
    }

    /**
     * UI Phase 3: register an unknown scanned UID so it can be assigned.
     *
     * @param  array<string, mixed>  $data
     */
    protected function ensureInventoryTag(array $data): void
    {
        if (! empty($data['rfid_tag_id'])) {
            return;
        }

        $uid = $this->normalizeTagUid((string) ($data['rfid_uid'] ?? $data['rfid_tag_uid'] ?? $data['tag_uid'] ?? ''));

        if ($uid === '' || RfidTag::query()->where('uid', $uid)->orWhere('tag_uid', $uid)->exists()) {
            return;
        }

        $this->registerRfidTag(['uid' => $uid, 'tag_type' => RfidTag::TYPE_VEHICLE, 'auto_number' => true]);
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
