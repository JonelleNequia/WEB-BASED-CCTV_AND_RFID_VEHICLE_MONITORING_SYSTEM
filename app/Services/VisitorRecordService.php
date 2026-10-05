<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\Gate;
use App\Models\GuestVehicleObservation;
use App\Models\PlateProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCrossing;
use App\Models\VisitorRecord;
use App\Support\PhilippineTime;
use App\Support\PlateNumber;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5 (visitor model): Unregistered Visitor records and plate profiles.
 *
 * - A no-pass crossing (the detector waited for a tag read and none came)
 *   becomes a record; the detector's plate vote fills the plate later.
 * - A plate read links the record to the plate's profile (visits, first and
 *   last seen, vehicle, note). Unreadable plates have no profile until a
 *   guard types the plate.
 * - Guards correct plates; an admin merges a misread plate into the right
 *   one (records move, later reads of the misread plate follow).
 * - The same vehicle sent twice (new track ID: same gate within 5 s with an
 *   overlapping box, or the same plate and direction within a minute) is
 *   kept as a duplicate, not counted.
 */
class VisitorRecordService
{
    public const DUPLICATE_SECONDS = 5;

    public const DUPLICATE_MIN_IOU = 0.30;

    public const SAME_PLATE_SECONDS = 60;

    /**
     * The record of a crossing with no registered tag read, or null.
     */
    public function createFromCrossing(VehicleCrossing $crossing): ?VisitorRecord
    {
        if ($crossing->rfid_scan_log_id || data_get($crossing->detection_metadata_json, 'rfid_status') !== 'no_pass') {
            return null;
        }

        return DB::transaction(function () use ($crossing): VisitorRecord {
            $record = VisitorRecord::query()->firstOrNew(['external_event_key' => $crossing->external_event_key]);
            $this->fillDefaults($record);
            $record->fill([
                'vehicle_crossing_id' => $crossing->id,
                'gate' => $crossing->gate,
                'camera_id' => $crossing->camera_id,
                'direction' => $crossing->direction,
                'seen_at' => $crossing->crossed_at,
                'snapshot_path' => $crossing->snapshot_path,
                'vehicle_type' => $record->vehicle_type ?: $crossing->vehicle_type,
            ]);
            $record->save();

            if ($record->status === VisitorRecord::STATUS_ACTIVE && ! $record->duplicate_of_id) {
                $this->markDuplicateOfOverlapping($record, $crossing);
            }

            return $record->fresh();
        });
    }

    /**
     * The detector's plate vote for one crossing: "read" with the plate, or
     * "unreadable" with its best guess. A guard's correction is never
     * overwritten.
     *
     * @param  array<string, mixed>  $data
     */
    public function applyPlateReading(array $data, ?UploadedFile $plateImage = null): VisitorRecord
    {
        return DB::transaction(function () use ($data, $plateImage): VisitorRecord {
            $record = VisitorRecord::query()->lockForUpdate()->firstOrNew(['external_event_key' => $data['external_event_key']]);

            if (! $record->exists) {
                $this->fillDefaults($record);
                // The crossing has not arrived (or an older detector): start the record here.
                $gate = Gate::normalizeCode((string) $data['camera_role']);
                $record->fill([
                    'gate' => $gate,
                    'camera_id' => Camera::query()->forRole($gate)->value('id'),
                    'seen_at' => Carbon::parse((string) $data['event_time']),
                ]);
            }

            $oldProfileId = $record->plate_profile_id;
            $plate = PlateNumber::display($data['plate_number'] ?? null);
            $read = ($data['plate_status'] ?? null) === VisitorRecord::PLATE_READ && $plate !== null;

            $record->fill([
                'vehicle_type' => $data['detected_vehicle_type'] ?? $record->vehicle_type,
                'vehicle_color' => $data['vehicle_color'] ?? $record->vehicle_color,
                'ocr_plate_number' => $read ? $plate : PlateNumber::display($data['best_guess'] ?? null),
                'ocr_details_json' => $data['ocr_details'] ?? null,
                'plate_image_path' => $plateImage ? $plateImage->store('visitor_plates', 'public') : $record->plate_image_path,
            ]);

            if ($record->plate_status !== VisitorRecord::PLATE_CORRECTED) {
                $record->fill([
                    'plate_status' => $read ? VisitorRecord::PLATE_READ : VisitorRecord::PLATE_UNREADABLE,
                    'plate_number' => $read ? $plate : null,
                    'plate_key' => $read ? PlateNumber::key($plate) : null,
                    'plate_confidence' => isset($data['plate_confidence']) ? (float) $data['plate_confidence'] : null,
                ]);
                $profile = $read ? $this->profileFor($plate, $record->seen_at) : null;
                $record->plate_profile_id = $profile?->id;
                $record->vehicle_id = $profile?->vehicle_id;
            }

            $record->save();

            if ($read && $record->status === VisitorRecord::STATUS_ACTIVE) {
                $this->markDuplicateOfSamePlate($record);
            }

            $this->refreshProfiles([$oldProfileId, $record->plate_profile_id]);

            return $record->fresh();
        });
    }

    /**
     * Phase 8 (visitor model): an older camera guest record (before visitor
     * records) becomes an Unregistered Visitor record with the same rules:
     * plate profile, duplicates (same plate, gate and direction within a
     * minute), dismissed when its alert was closed by a registered tag.
     * Running it again does not create a second record.
     */
    public function importLegacyObservation(GuestVehicleObservation $observation): VisitorRecord
    {
        return DB::transaction(function () use ($observation): VisitorRecord {
            $key = $observation->external_event_key ?: 'legacy-guest-'.$observation->id;
            $existing = VisitorRecord::query()->where('external_event_key', $key)->first();

            if ($existing) {
                // Already a visitor record (the detector sent both since Phase 5).
                $observation->forceFill(['visitor_record_id' => $existing->id])->save();

                return $existing;
            }

            $gate = Gate::normalizeCode((string) $observation->location);
            $direction = strtoupper((string) data_get($observation->detection_metadata_json, 'direction'));
            $plate = PlateNumber::display($observation->plate_number ?: $observation->plate_text);
            $resolved = $observation->status === GuestVehicleObservation::STATUS_RESOLVED;

            $record = new VisitorRecord([
                'external_event_key' => $key,
                'source' => VisitorRecord::SOURCE_LEGACY,
                'gate' => $gate,
                'camera_id' => $observation->camera_id ?? Camera::query()->forRole($gate)->value('id'),
                // No direction stored = not known (older records guessed IN).
                'direction' => in_array($direction, ['IN', 'OUT'], true) ? $direction : 'UNKNOWN',
                'seen_at' => $observation->observed_at ?? $observation->created_at,
                'status' => $resolved ? VisitorRecord::STATUS_DISMISSED : VisitorRecord::STATUS_ACTIVE,
                'status_note' => $resolved ? 'Converted from guest record #'.$observation->id.'; its alert was closed (registered tag read).' : 'Converted from guest record #'.$observation->id.'.',
                'snapshot_path' => $observation->snapshot_path,
                'plate_status' => $plate ? VisitorRecord::PLATE_READ : VisitorRecord::PLATE_UNREADABLE,
                'plate_number' => $plate,
                'plate_key' => PlateNumber::key($plate),
                'ocr_plate_number' => $plate,
                'ocr_details_json' => ['source' => 'guest_vehicle_observation', 'id' => $observation->id],
                'vehicle_type' => $observation->vehicle_type,
                'vehicle_color' => $observation->vehicle_color,
            ]);

            $profile = $plate ? $this->profileFor($plate, $record->seen_at) : null;
            $record->plate_profile_id = $profile?->id;
            $record->vehicle_id = $profile?->vehicle_id;
            $record->save();

            if ($plate && $record->status === VisitorRecord::STATUS_ACTIVE) {
                $this->markDuplicateOfSamePlate($record);
            }

            $observation->forceFill(['visitor_record_id' => $record->id])->save();
            $this->refreshProfiles([$record->plate_profile_id]);

            return $record->fresh();
        });
    }

    /**
     * Phase 8: a guard records a vehicle with no registered tag by hand
     * (replaces "Add Guest Observation").
     *
     * @param  array<string, mixed>  $data  gate, direction, seen_at, plate_number?, vehicle_type?, vehicle_color?, note?
     */
    public function createManual(array $data, User $user, ?UploadedFile $snapshot = null): VisitorRecord
    {
        return DB::transaction(function () use ($data, $user, $snapshot): VisitorRecord {
            $plate = PlateNumber::display($data['plate_number'] ?? null);
            $seenAt = Carbon::parse((string) $data['seen_at']);
            $gate = Gate::normalizeCode((string) $data['gate']);
            $profile = $plate ? $this->profileFor($plate, $seenAt) : null;

            $record = VisitorRecord::query()->create([
                'external_event_key' => 'manual-'.\Illuminate\Support\Str::uuid(),
                'source' => VisitorRecord::SOURCE_MANUAL,
                'gate' => $gate,
                'camera_id' => Camera::query()->forRole($gate)->value('id'),
                'direction' => $data['direction'],
                'seen_at' => $seenAt,
                'status' => VisitorRecord::STATUS_ACTIVE,
                'status_note' => filled($data['note'] ?? null) ? mb_substr((string) $data['note'], 0, 200) : 'Recorded by hand.',
                'snapshot_path' => $snapshot?->store('visitor_snapshots', 'public'),
                'plate_status' => $plate ? VisitorRecord::PLATE_CORRECTED : VisitorRecord::PLATE_UNREADABLE,
                'plate_number' => $plate,
                'plate_key' => PlateNumber::key($plate),
                'plate_profile_id' => $profile?->id,
                'vehicle_id' => $profile?->vehicle_id,
                'vehicle_type' => $data['vehicle_type'] ?? null,
                'vehicle_color' => $data['vehicle_color'] ?? null,
                'corrected_by' => $user->id,
                'corrected_at' => now(),
            ]);

            $this->refreshProfiles([$record->plate_profile_id]);

            return $record;
        });
    }

    /**
     * A guard types the right plate, or marks it unreadable (null).
     */
    public function correctPlate(VisitorRecord $record, ?string $plate, User $user): VisitorRecord
    {
        return DB::transaction(function () use ($record, $plate, $user): VisitorRecord {
            $oldProfileId = $record->plate_profile_id;
            $plate = PlateNumber::display($plate);
            $profile = $plate ? $this->profileFor($plate, $record->seen_at) : null;

            $record->fill([
                // What OCR said stays in ocr_plate_number.
                'ocr_plate_number' => $record->ocr_plate_number ?? $record->plate_number,
                'plate_status' => $plate ? VisitorRecord::PLATE_CORRECTED : VisitorRecord::PLATE_UNREADABLE,
                'plate_number' => $plate,
                'plate_key' => PlateNumber::key($plate),
                'plate_profile_id' => $profile?->id,
                'vehicle_id' => $profile?->vehicle_id,
                'corrected_by' => $user->id,
                'corrected_at' => now(),
            ])->save();

            $this->refreshProfiles([$oldProfileId, $record->plate_profile_id]);

            return $record->fresh();
        });
    }

    /**
     * A2 (detection): a guard sets the vehicle type; what the camera said is
     * logged (VehicleTypeCorrection) to measure how often it is wrong.
     */
    public function correctType(VisitorRecord $record, string $type, User $user): VisitorRecord
    {
        return DB::transaction(function () use ($record, $type, $user): VisitorRecord {
            // The camera's own answer (the crossing keeps it after a correction).
            $detected = $record->crossing?->vehicle_type ?? ($record->source === VisitorRecord::SOURCE_MANUAL ? null : $record->vehicle_type);

            \App\Models\VehicleTypeCorrection::query()->create([
                'visitor_record_id' => $record->id,
                'vehicle_crossing_id' => $record->vehicle_crossing_id,
                'gate' => $record->gate,
                'detected_type' => $detected,
                'corrected_type' => $type,
                'corrected_by' => $user->id,
            ]);
            $record->forceFill(['vehicle_type' => $type])->save();
            $this->refreshProfiles([$record->plate_profile_id]);

            return $record->fresh();
        });
    }

    public function dismiss(VisitorRecord $record, string $reason): VisitorRecord
    {
        $record->forceFill(['status' => VisitorRecord::STATUS_DISMISSED, 'status_note' => mb_substr($reason, 0, 200)])->save();
        $this->refreshProfiles([$record->plate_profile_id]);

        return $record;
    }

    /**
     * A registered tag read was given this crossing after all (Phase 3).
     */
    public function dismissForCrossing(VehicleCrossing $crossing, string $reason): void
    {
        VisitorRecord::query()
            ->where('vehicle_crossing_id', $crossing->id)
            ->where('status', '!=', VisitorRecord::STATUS_DISMISSED)
            ->get()
            ->each(fn (VisitorRecord $record) => $this->dismiss($record, $reason));
    }

    public function updateNote(PlateProfile $profile, ?string $note, User $user): PlateProfile
    {
        $profile->forceFill(['note' => filled($note) ? trim((string) $note) : null, 'note_updated_by' => $user->id])->save();

        return $profile;
    }

    /**
     * Merge a misread plate into the right one: its records move, its note is
     * kept, and later reads of the misread plate go to the right profile.
     */
    public function merge(PlateProfile $source, PlateProfile $target, User $user): PlateProfile
    {
        $target = $this->resolve($target);

        if ($source->merged_into_id !== null) {
            throw ValidationException::withMessages(['target' => "{$source->plate_number} was already merged into another plate."]);
        }

        if ($source->is($target)) {
            throw ValidationException::withMessages(['target' => 'Choose a different plate to merge into.']);
        }

        return DB::transaction(function () use ($source, $target, $user): PlateProfile {
            VisitorRecord::query()->where('plate_profile_id', $source->id)->update(['plate_profile_id' => $target->id, 'vehicle_id' => $target->vehicle_id]);
            // Plates merged into the source earlier now point to the target.
            PlateProfile::query()->where('merged_into_id', $source->id)->update(['merged_into_id' => $target->id]);

            $notes = array_filter([$target->note, $source->note ? "{$source->plate_number}: {$source->note}" : null]);
            $target->forceFill([
                'note' => $notes !== [] ? implode("\n", $notes) : null,
                'vehicle_type' => $target->vehicle_type ?: $source->vehicle_type,
                'vehicle_color' => $target->vehicle_color ?: $source->vehicle_color,
            ])->save();

            $source->forceFill([
                'merged_into_id' => $target->id,
                'merged_by' => $user->id,
                'merged_at' => now(),
                'visit_count' => 0,
            ])->save();

            $this->refreshProfiles([$target->id]);

            return $target->fresh();
        });
    }

    /**
     * The profile of a plate (following merges), created on first sight.
     */
    public function profileFor(string $plate, ?Carbon $seenAt = null): PlateProfile
    {
        $key = (string) PlateNumber::key($plate);
        $profile = PlateProfile::query()->firstOrCreate(
            ['plate_key' => $key],
            [
                'plate_number' => PlateNumber::display($plate),
                'first_seen_at' => $seenAt,
                'last_seen_at' => $seenAt,
                // Phase 6: a plate already in the Registry (seen with no tag read).
                'vehicle_id' => $this->vehicleIdForPlateKey($key),
            ]
        );

        return $this->resolve($profile);
    }

    /**
     * Phase 6 (visitor model): "Register this vehicle". The plate's profile
     * (the one picked in the ranking, and any profile with the vehicle's
     * plate) and its records now belong to the Registry vehicle. Returns how
     * many visits moved.
     */
    public function transferHistoryToVehicle(Vehicle $vehicle, ?int $plateProfileId = null): int
    {
        return DB::transaction(function () use ($vehicle, $plateProfileId): int {
            $profiles = PlateProfile::query()
                ->where(fn ($query) => $query->where('plate_key', PlateNumber::key($vehicle->plate_number))
                    ->when($plateProfileId, fn ($inner) => $inner->orWhere('id', $plateProfileId)))
                ->get()
                ->map(fn (PlateProfile $profile): PlateProfile => $this->resolve($profile))
                ->unique('id')
                ->filter(fn (PlateProfile $profile): bool => $profile->vehicle_id === null || (int) $profile->vehicle_id === (int) $vehicle->id);

            $moved = 0;

            foreach ($profiles as $profile) {
                $profile->forceFill(['vehicle_id' => $vehicle->id, 'registered_at' => $profile->registered_at ?? now()])->save();
                PlateProfile::query()->where('merged_into_id', $profile->id)->update(['vehicle_id' => $vehicle->id]);
                $moved += VisitorRecord::query()->where('plate_profile_id', $profile->id)->whereNull('vehicle_id')->update(['vehicle_id' => $vehicle->id]);
            }

            return $moved;
        });
    }

    /**
     * Phase 6: Visitor Ranking. Plates not in the Registry, most entries (IN)
     * first, then most sightings.
     *
     * @return Collection<int, PlateProfile>
     */
    public function unregisteredRanking(int $limit = 5): Collection
    {
        return PlateProfile::query()
            ->unregistered()
            ->where('visit_count', '>', 0)
            ->withCount([
                'records as entries_count' => fn ($query) => $query->active()->where('direction', 'IN'),
                'records as entries_today_count' => fn ($query) => $query->active()->where('direction', 'IN')
                    ->where(fn ($inner) => PhilippineTime::constrainTodayAny($inner, ['seen_at'])),
            ])
            ->orderByDesc('entries_count')
            ->orderByDesc('visit_count')
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get();
    }

    protected function vehicleIdForPlateKey(string $key): ?int
    {
        $id = Vehicle::query()
            ->whereRaw("replace(replace(upper(plate_number), ' ', ''), '-', '') = ?", [$key])
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Visits, first/last seen and vehicle from the profile's counted records.
     *
     * @param  array<int, int|null>  $profileIds
     */
    public function refreshProfiles(array $profileIds): void
    {
        foreach (array_unique(array_filter($profileIds)) as $id) {
            $profile = PlateProfile::query()->find($id);

            if (! $profile || $profile->merged_into_id) {
                continue;
            }

            $records = VisitorRecord::query()->active()->where('plate_profile_id', $profile->id);
            $latest = (clone $records)->latest('seen_at')->first();

            $profile->forceFill([
                'visit_count' => (clone $records)->count(),
                'first_seen_at' => (clone $records)->min('seen_at') ?? $profile->first_seen_at,
                'last_seen_at' => (clone $records)->max('seen_at') ?? $profile->last_seen_at,
                'vehicle_type' => $latest?->vehicle_type ?: $profile->vehicle_type,
                'vehicle_color' => $latest?->vehicle_color ?: $profile->vehicle_color,
            ])->save();
        }
    }

    /** A new record is counted and waits for its plate (the DB defaults, set on the model). */
    protected function fillDefaults(VisitorRecord $record): void
    {
        if (! $record->exists) {
            $record->status ??= VisitorRecord::STATUS_ACTIVE;
            $record->plate_status ??= VisitorRecord::PLATE_PENDING;
        }
    }

    protected function resolve(PlateProfile $profile): PlateProfile
    {
        $seen = [];

        while ($profile->merged_into_id && ! in_array($profile->id, $seen, true)) {
            $seen[] = $profile->id;
            $profile = PlateProfile::query()->findOrFail($profile->merged_into_id);
        }

        return $profile;
    }

    /**
     * New track ID for the same vehicle: same gate, within 5 s, overlapping box.
     */
    protected function markDuplicateOfOverlapping(VisitorRecord $record, VehicleCrossing $crossing): void
    {
        $box = data_get($crossing->detection_metadata_json, 'bbox_xyxy');

        if (! is_array($box) || count($box) !== 4) {
            return;
        }

        $earlier = VisitorRecord::query()
            ->active()
            ->with('crossing')
            ->where('gate', $record->gate)
            ->where('id', '!=', $record->id)
            ->whereBetween('seen_at', [$record->seen_at->copy()->subSeconds(self::DUPLICATE_SECONDS), $record->seen_at])
            ->get()
            ->first(fn (VisitorRecord $other): bool => $this->iou($box, data_get($other->crossing?->detection_metadata_json, 'bbox_xyxy')) >= self::DUPLICATE_MIN_IOU);

        if ($earlier) {
            $record->forceFill([
                'status' => VisitorRecord::STATUS_DUPLICATE,
                'duplicate_of_id' => $earlier->id,
                'status_note' => 'Same vehicle as record #'.$earlier->id.' (new track ID).',
            ])->save();
        }
    }

    /**
     * The same plate, gate and direction again within a minute.
     */
    protected function markDuplicateOfSamePlate(VisitorRecord $record): void
    {
        $earlier = VisitorRecord::query()
            ->active()
            ->where('id', '!=', $record->id)
            ->where('gate', $record->gate)
            ->where('direction', $record->direction)
            ->where('plate_key', $record->plate_key)
            ->whereBetween('seen_at', [$record->seen_at->copy()->subSeconds(self::SAME_PLATE_SECONDS), $record->seen_at])
            ->oldest('seen_at')
            ->first();

        if ($earlier) {
            $record->forceFill([
                'status' => VisitorRecord::STATUS_DUPLICATE,
                'duplicate_of_id' => $earlier->id,
                'status_note' => 'Same plate as record #'.$earlier->id.' less than a minute earlier.',
            ])->save();
        }
    }

    /**
     * @param  array<int, float|int>  $a
     */
    protected function iou(array $a, mixed $b): float
    {
        if (! is_array($b) || count($b) !== 4) {
            return 0.0;
        }

        $width = max(0, min($a[2], $b[2]) - max($a[0], $b[0]));
        $height = max(0, min($a[3], $b[3]) - max($a[1], $b[1]));
        $intersection = $width * $height;
        $union = ($a[2] - $a[0]) * ($a[3] - $a[1]) + ($b[2] - $b[0]) * ($b[3] - $b[1]) - $intersection;

        return $union > 0 ? $intersection / $union : 0.0;
    }
}
