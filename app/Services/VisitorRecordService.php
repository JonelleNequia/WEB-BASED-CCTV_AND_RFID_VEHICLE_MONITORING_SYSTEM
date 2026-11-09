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
     * The record of a crossing with no registered tag read, or null. An
     * unknown tag read for the vehicle is kept on it ("Register this tag").
     */
    public function createFromCrossing(VehicleCrossing $crossing, ?string $unknownTag = null): ?VisitorRecord
    {
        if ($crossing->rfid_scan_log_id || ($unknownTag === null && data_get($crossing->detection_metadata_json, 'rfid_status') !== 'no_pass')) {
            return null;
        }

        return app(PlateProcessingLock::class)->run(function () use ($crossing, $unknownTag): VisitorRecord {
            $record = VisitorRecord::query()->firstOrNew(['external_event_key' => $crossing->external_event_key]);
            $this->fillDefaults($record);
            $record->fill([
                'vehicle_crossing_id' => $crossing->id,
                'gate' => $crossing->gate,
                'camera_id' => $crossing->camera_id,
                'direction' => $crossing->direction,
                // Gate direction work, Phase A: how sure the camera was (source: camera).
                'direction_confidence' => \App\Support\DirectionSource::confidence(data_get($crossing->detection_metadata_json, 'direction_confidence')),
                'seen_at' => $crossing->crossed_at,
                'snapshot_path' => $crossing->snapshot_path,
                'vehicle_type' => $record->vehicle_type ?: $crossing->vehicle_type,
                'tag_uid' => $unknownTag ?? $record->tag_uid,
            ]);
            // Delete device work: the camera that saw it.
            $record->forceFill(['camera_device_id' => $crossing->camera_device_id, 'camera_device_label' => $crossing->camera_device_label]);
            app(VehicleTypeEvidenceService::class)->resolve($record, $crossing->vehicle_type, true);
            $record->save();

            if ($record->status === VisitorRecord::STATUS_ACTIVE && ! $record->duplicate_of_id) {
                $this->markDuplicateOfOverlapping($record, $crossing);
            }
            app(MovementReconciliationService::class)->syncPlate($record);

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
        $data = \App\Support\DeprecatedVehicleColor::without($data);
        $media = app(CrossingSnapshotService::class);
        $this->assertPlateOccurrence($data);
        $upload = $media->prepare($plateImage);
        $result = null;
        try {

            $result = app(PlateProcessingLock::class)->run(function () use ($data, $upload): VisitorRecord {
                $this->assertPlateOccurrence($data);
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
                    // OCR can arrive after an RFID-backed crossing, which has no
                    // visitor row yet. Keep its identity and conflict evidence.
                    if ($crossing = VehicleCrossing::query()->where('external_event_key', $data['external_event_key'])->first()) {
                        $record->fill([
                            'vehicle_crossing_id' => $crossing->id, 'gate' => $crossing->gate,
                            'camera_id' => $crossing->camera_id, 'seen_at' => $crossing->crossed_at,
                            'direction' => $crossing->direction, 'snapshot_path' => $crossing->snapshot_path,
                        ]);
                    }
                }

                $oldProfileId = $record->plate_profile_id;
                $plate = PlateNumber::display($data['plate_number'] ?? null);
                $read = ($data['plate_status'] ?? null) === VisitorRecord::PLATE_READ && $plate !== null;

                $record->fill([
                    // Effective type is resolved separately from detector evidence below.
                    'ocr_plate_number' => ($record->plate_status === VisitorRecord::PLATE_CORRECTED || $record->reviewed_at !== null) && $record->ocr_plate_number !== null
                        ? $record->ocr_plate_number : ($read ? $plate : (PlateEvidenceService::candidateFromData($data) ?: $record->ocr_plate_number)),
                    'ocr_details_json' => ($record->plate_status === VisitorRecord::PLATE_CORRECTED || $record->reviewed_at !== null) && app(VehicleTypeEvidenceService::class)->hasOcrDetails($record)
                        ? $record->ocr_details_json : app(VehicleTypeEvidenceService::class)->mergeOcrDetails($record, $data['ocr_details'] ?? null),
                    'plate_image_path' => $upload['path'] && ! \App\Support\OccurrenceMedia::exists($record->plate_image_path)
                        ? $upload['path'] : $record->plate_image_path,
                ]);
                app(PlateEvidenceService::class)->observe($record, $data);

                if ($record->plate_status !== VisitorRecord::PLATE_CORRECTED && $record->reviewed_at === null) {
                    // Current readers recognize characters in a generic plate
                    // region, not actual vs temporary identifiers. Neither a
                    // layout nor a Registry match establishes that distinction.
                    $record->fill([
                        'plate_status' => VisitorRecord::PLATE_UNREADABLE,
                        'plate_number' => null,
                        'plate_key' => null,
                        'plate_confidence' => isset($data['plate_confidence']) ? (float) $data['plate_confidence'] : null,
                        'match_confidence' => null,
                        'match_reason' => null,
                    ]);
                    $record->plate_profile_id = null;
                    $record->vehicle_id = null;
                    $record->suggested_profile_id = null;
                    $this->classifyPlate($record, null, false);
                    if (in_array($record->registration_reason, ['Plate unreadable or invalid. Check the plate.', 'OCR confidence is missing or below the review threshold.'], true)) {
                        $record->registration_reason = data_get($record->ocr_details_json, PlateEvidenceService::KEY.'.reason');
                    }
                }

                app(VehicleTypeEvidenceService::class)->resolve($record, $data['detected_vehicle_type'] ?? null, array_key_exists('detected_vehicle_type', $data));
                $record->save();

                if ($read && $record->status === VisitorRecord::STATUS_ACTIVE && $record->registration_status !== 'review'
                    && $record->plate_status !== VisitorRecord::PLATE_CORRECTED && $record->reviewed_at === null) {
                    $this->markDuplicateOfSamePlate($record);
                }

                $this->refreshProfiles([$oldProfileId, $record->plate_profile_id]);
                app(MovementReconciliationService::class)->syncPlate($record);
                if ($record->crossing) app(CrossingSnapshotService::class)->synchronize($record->crossing->fresh());

                return $record->fresh();
            });

            return $result;
        } finally {
            if (! $result || $result->plate_image_path !== $upload['path']) $media->discard($upload);
        }
    }

    private function assertPlateOccurrence(array $data): void
    {
        $ownership = app(OccurrenceOwnership::class);
        if ($record = VisitorRecord::where('external_event_key', $data['external_event_key'])->first()) {
            $ownership->record($record, $data);
        }
        if ($crossing = VehicleCrossing::where('external_event_key', $data['external_event_key'])->first()) {
            $ownership->crossing($crossing, $data);
        }
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
     * @param  array<string, mixed>  $data  gate, direction, seen_at, plate_number?, vehicle_type?, note?
     */
    public function createManual(array $data, User $user, ?UploadedFile $snapshot = null): VisitorRecord
    {
        return app(PlateProcessingLock::class)->run(function () use ($data, $user, $snapshot): VisitorRecord {
            // Validate before creating a profile or storing an uploaded image, including direct service calls.
            \Illuminate\Support\Facades\Validator::make($data, [
                'plate_number' => ['nullable', 'string', 'max:30', new \App\Rules\PlateIdentifier(20)],
            ])->validate();
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
                'vehicle_type' => \App\Support\VehicleType::validated($data['vehicle_type'] ?? null, required: false),
                'corrected_by' => $user->id,
                'corrected_at' => now(),
            ]);

            $this->classifyPlate($record, $plate, $plate !== null, true);
            $record->save();
            app(MovementReconciliationService::class)->syncPlate($record);

            $this->refreshProfiles([$record->plate_profile_id]);

            return $record;
        });
    }

    /**
     * A guard types the right plate, or marks it unreadable (null).
     */
    public function correctPlate(VisitorRecord $record, ?string $plate, User $user, string $action = 'correct_plate'): VisitorRecord
    {
        return app(PlateProcessingLock::class)->run(function () use ($record, $plate, $user, $action): VisitorRecord {
            $record = VisitorRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $oldProfileId = $record->plate_profile_id;
            $before = $record->plate_number
                ? $record->plate_number.' ('.$record->plate_status.($record->plate_confidence !== null ? ', '.round($record->plate_confidence * 100).'%' : '').')'
                : $record->plate_status.($record->ocr_plate_number ? ' (guess '.$record->ocr_plate_number.')' : '');
            if ($plate !== null) {
                \Illuminate\Support\Facades\Validator::make(['plate' => PlateNumber::normalize($plate)], [
                    'plate' => ['required', 'string', 'max:30', new \App\Rules\PlateIdentifier(20)],
                ])->validate();
            }
            $plate = PlateNumber::display($plate);
            $profile = $plate ? $this->profileFor($plate, $record->seen_at) : null;
            $previousDecision = $record->only(['registration_status', 'registration_category', 'registration_reason', 'vehicle_id', 'status']);
            $this->classifyPlate($record, $plate, $plate !== null, true);
            if ($plate === null) {
                $record->registration_reason = 'No plate visible (checked by staff).';
            }
            if ($record->reviewed_at !== null && $record->plate_number === $plate
                && $record->plate_profile_id === $profile?->id
                && $previousDecision === $record->only(array_keys($previousDecision))) {
                app(MovementReconciliationService::class)->syncPlate($record);
                return $record->fresh();
            }

            // Gate direction work, Phase C: remember the correction for later reads.
            if ($plate) {
                app(PlateMatchService::class)->learn($record->ocr_plate_number ?? $record->plate_number, $plate, $user);
            }

            $record->fill([
                'match_confidence' => $plate ? ($action === 'confirm' ? ($record->match_confidence === PlateMatchService::LOW || $record->match_confidence === null ? PlateMatchService::CONFIRMED : $record->match_confidence) : PlateMatchService::MANUAL) : null,
                'match_reason' => null,
                'suggested_profile_id' => null,
                // What OCR said stays in ocr_plate_number.
                'ocr_plate_number' => $record->ocr_plate_number ?? ($record->source === VisitorRecord::SOURCE_MANUAL ? null : $record->plate_number),
                'plate_status' => $plate ? ($action === 'confirm' && $record->plate_status === VisitorRecord::PLATE_READ ? VisitorRecord::PLATE_READ : VisitorRecord::PLATE_CORRECTED) : VisitorRecord::PLATE_UNREADABLE,
                'plate_number' => $plate,
                'plate_key' => PlateNumber::key($plate),
                'plate_profile_id' => $profile?->id,
                'corrected_by' => $user->id,
                'corrected_at' => now(),
                // Gate direction work, Phase D: a guard looked at the plate.
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ])->save();

            app(PlateEvidenceService::class)->confirm($record, $plate ? 'actual_plate' : 'none', $plate, $user);
            $record->save();

            $this->refreshProfiles([$oldProfileId, $record->plate_profile_id]);
            app(VisitorReviewService::class)->log($record, $plate === null ? 'no_plate' : $action, $before, $record->plate_number, $user);
            app(MovementReconciliationService::class)->syncPlate($record);

            return $record->fresh();
        });
    }

    /** A staff-confirmed temporary identifier is evidence, never a plate/profile. */
    public function confirmTemporary(VisitorRecord $record, string $kind, string $text, User $user): VisitorRecord
    {
        \Illuminate\Support\Facades\Validator::make(['identifier_type' => $kind, 'identifier_text' => $text], [
            'identifier_type' => ['required', \Illuminate\Validation\Rule::in(array_keys(PlateEvidenceService::TEMPORARY))],
            'identifier_text' => ['required', 'string', 'max:200', 'not_regex:/[\p{Cc}\p{Cf}]/u'],
        ])->validate();

        return app(PlateProcessingLock::class)->run(function () use ($record, $kind, $text, $user): VisitorRecord {
            $record = VisitorRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if (PlateEvidenceService::kind($record) === $kind && app(PlateEvidenceService::class)->staffConfirmed($record)
                && data_get($record->ocr_details_json, PlateEvidenceService::KEY.'.text') === $text) {
                return $record;
            }
            $profile = $record->plate_profile_id;
            $before = $record->plate_number ?: $record->ocr_plate_number;
            app(PlateEvidenceService::class)->confirm($record, $kind, $text, $user);
            $record->forceFill(['plate_number' => null, 'plate_key' => null, 'plate_profile_id' => null,
                'vehicle_id' => null, 'suggested_profile_id' => null, 'match_confidence' => null, 'match_reason' => null,
                'plate_status' => VisitorRecord::PLATE_UNREADABLE, 'reviewed_by' => $user->id, 'reviewed_at' => now(),
                'corrected_by' => $user->id, 'corrected_at' => now(), 'registration_status' => 'guest', 'registration_category' => null,
                'registration_reason' => PlateEvidenceService::TEMPORARY[$kind].' confirmed by staff; no actual plate confirmed.',
            ])->save();
            $this->refreshProfiles([$profile]);
            app(VisitorReviewService::class)->log($record, 'temporary_identifier', $before, PlateEvidenceService::TEMPORARY[$kind].': '.$text, $user);
            app(MovementReconciliationService::class)->syncPlate($record);

            return $record->fresh();
        });
    }

    /**
     * A2 (detection): a guard sets the vehicle type; what the camera said is
     * logged (VehicleTypeCorrection) to measure how often it is wrong.
     */
    public function correctType(VisitorRecord $record, string $type, User $user): VisitorRecord
    {
        $type = \App\Support\VehicleType::validated($type);
        return app(PlateProcessingLock::class)->run(function () use ($record, $type, $user): VisitorRecord {
            $record = VisitorRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $before = $record->vehicle_type;
            if ($before === $type && $record->typeCorrections()->latest('id')->first()?->corrected_type === $type) {
                return $record;
            }
            // The camera's own answer (the crossing keeps it after a correction).
            $detected = data_get($record->ocr_details_json, VehicleTypeEvidenceService::KEY.'.raw', $record->crossing?->vehicle_type ?? ($record->source === VisitorRecord::SOURCE_MANUAL ? null : $record->vehicle_type));

            \App\Models\VehicleTypeCorrection::query()->create([
                'visitor_record_id' => $record->id,
                'vehicle_crossing_id' => $record->vehicle_crossing_id,
                'gate' => $record->gate,
                'detected_type' => $detected,
                'corrected_type' => $type,
                'corrected_by' => $user->id,
            ]);
            $record->forceFill(['vehicle_type' => $type]);
            app(VehicleTypeEvidenceService::class)->resolve($record);
            $record->save();
            app(VisitorReviewService::class)->log($record, 'correct_type', $before, $type, $user);
            app(VehicleTypeEvidenceService::class)->syncEvent($record);
            $this->refreshProfiles([$record->plate_profile_id]);

            return $record->fresh();
        });
    }

    public function dismiss(VisitorRecord $record, string $reason): VisitorRecord
    {
        return app(PlateProcessingLock::class)->run(function () use ($record, $reason): VisitorRecord {
            $record = $record->fresh();
            $record->forceFill(['status' => VisitorRecord::STATUS_DISMISSED, 'status_note' => mb_substr($reason, 0, 200)])->save();
            $this->refreshProfiles([$record->plate_profile_id]);
            app(MovementReconciliationService::class)->syncPlate($record);

            return $record;
        });
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

    /**
     * RFID only with a vehicle: an unknown tag read a little after this
     * crossing was recorded.
     */
    public function noteUnknownTag(VehicleCrossing $crossing, string $uid): ?VisitorRecord
    {
        $record = VisitorRecord::query()->where('vehicle_crossing_id', $crossing->id)->first();

        if (! $record) {
            return $this->createFromCrossing($crossing, $uid);
        }

        $record->forceFill(['tag_uid' => $uid])->save();

        return $record;
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
     * Gate direction work, Phase C: which plate profile a camera read belongs
     * to. A read a guard corrected before becomes the right plate; the same
     * plate is tied at once; one character off a known plate is tied when
     * PlateMatchService is sure enough (high / medium), else it waits in
     * Review with the suggestion (low, no profile); a new plate gets its own.
     *
     * @return array{plate: string, profile: ?PlateProfile, confidence: ?string, reason: ?string, suggested: ?int}
     */
    protected function tieToProfile(VisitorRecord $record, string $plate): array
    {
        $matcher = app(PlateMatchService::class);
        $key = (string) PlateNumber::key($plate);
        if ($key === '' || strlen($key) > 20 || mb_strlen(PlateNumber::display($plate)) > 30) {
            return ['plate' => $plate, 'profile' => null, 'confidence' => PlateMatchService::LOW, 'reason' => 'Invalid plate identifier.', 'suggested' => null];
        }
        if (app(VehicleRegistryService::class)->plateMatches($plate, $record->seen_at)->isNotEmpty()) {
            return ['plate' => $plate, 'profile' => $this->profileFor($plate, $record->seen_at), 'confidence' => PlateMatchService::EXACT, 'reason' => null, 'suggested' => null];
        }

        if ($learned = $matcher->learnedPlateKey($key)) {
            $right = (string) PlateNumber::display($learned);

            $suggestion = $this->profileFor($right, $record->seen_at);
            return ['plate' => $plate, 'profile' => null, 'confidence' => PlateMatchService::LOW,
                'reason' => "Read {$plate}; a previous correction suggested {$right}. Confirm the actual plate.", 'suggested' => $suggestion->id];
        }

        if (PlateProfile::query()->where('plate_key', $key)->exists()) {
            return ['plate' => $plate, 'profile' => $this->profileFor($plate, $record->seen_at), 'confidence' => PlateMatchService::EXACT, 'reason' => null, 'suggested' => null];
        }

        $near = $matcher->nearMatch($record, $key);
        if ($near && $near['confidence'] !== PlateMatchService::LOW) {
            return ['plate' => $plate, 'profile' => $this->resolve($near['profile']), 'confidence' => $near['confidence'], 'reason' => $near['reason'], 'suggested' => null];
        }
        if ($near) {
            return ['plate' => $plate, 'profile' => null, 'confidence' => PlateMatchService::LOW, 'reason' => $near['reason'], 'suggested' => $near['profile']->id];
        }

        return ['plate' => $plate, 'profile' => $this->profileFor($plate, $record->seen_at), 'confidence' => null, 'reason' => null, 'suggested' => null];
    }

    /**
     * The profile of a plate (following merges), created on first sight.
     */
    public function profileFor(string $plate, ?Carbon $seenAt = null): PlateProfile
    {
        \Illuminate\Support\Facades\Validator::make(['plate' => PlateNumber::normalize($plate)], [
            'plate' => ['required', 'string', 'max:30', new \App\Rules\PlateIdentifier(20)],
        ])->validate();
        $key = (string) PlateNumber::key($plate);
        $profile = PlateProfile::query()->firstOrCreate(
            ['plate_key' => $key],
            [
                'plate_number' => PlateNumber::display($plate),
                'first_seen_at' => $seenAt,
                'last_seen_at' => $seenAt,
                // Phase 6: a plate already in the Registry (seen with no tag read).
                'vehicle_id' => $this->vehicleIdForPlateKey($key, $seenAt),
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
                // Only the explicit "Register this vehicle" selection moves
                // earlier relationships. Ordinary registration/editing does
                // not relink historical passes or rewrite their decisions.
                if ($plateProfileId !== null) {
                    $moved += VisitorRecord::query()->where('plate_profile_id', $profile->id)->whereNull('vehicle_id')->update(['vehicle_id' => $vehicle->id]);
                }
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

    protected function vehicleIdForPlateKey(string $key, ?Carbon $seenAt = null): ?int
    {
        $registry = app(VehicleRegistryService::class);
        $candidates = $registry->plateCandidates($key, $seenAt);
        $matches = $registry->eligiblePlateMatches($candidates);

        return $candidates->count() === 1 && $matches->count() === 1 && $matches->first()->status === 'active' ? $matches->first()->id : null;
    }

    /** Classify this pass only. Profile aliases/suggestions never prove registration. */
    protected function classifyPlate(VisitorRecord $record, ?string $plate, bool $reliable, bool $manual = false): void
    {
        app(CanonicalMovementEventService::class)->resolve($record);
        $registry = app(VehicleRegistryService::class);
        $candidates = $registry->plateCandidates($plate, $record->seen_at);
        $matches = $registry->eligiblePlateMatches($candidates);
        $valid = $plate !== null && PlateNumber::key($plate) !== null && strlen(PlateNumber::key($plate)) <= 20 && mb_strlen(PlateNumber::display($plate)) <= 30;
        $reason = match (true) {
            ! $valid => 'Plate unreadable or invalid. Check the plate.',
            ! $reliable => 'OCR confidence is missing or below the review threshold.',
            $candidates->count() > 1 => 'Multiple registrations match this plate. Resolve the registry collision before confirming.',
            $matches->count() === 1 && $matches->first()->status !== 'active' => 'The matching registration is inactive. Check its status in the Registry.',
            ! $manual && ($record->match_confidence === PlateMatchService::LOW
                || ($matches->isEmpty() && $record->plateProfile
                    && app(VehicleRegistryService::class)->plateMatches($record->plateProfile->plate_number, $record->seen_at)->isNotEmpty()))
                => 'Possible or previously corrected plate match. Confirm the actual plate.',
            default => null,
        };
        $scan = $record->crossing?->rfidScanLog;
        if ($scan && $scan->verification_status !== 'verified') {
            $reason = 'The linked RFID evidence is flagged. Plate identification cannot bypass that restriction; check Review.';
        }
        if ($scan && $scan->vehicle_id && ($reason !== null || $matches->count() !== 1 || $matches->first()->id !== $scan->vehicle_id)) {
            $reason = 'Plate evidence conflicts with the linked RFID identity. Check the plate; RFID identity is retained.';
            if ($scan->verification_status === 'verified' && $record->status === VisitorRecord::STATUS_DISMISSED
                && (str_starts_with((string) $record->status_note, 'Registered tag read for ')
                    || str_starts_with((string) $record->status_note, 'Identified by the linked RFID event'))) {
                $record->status = VisitorRecord::STATUS_ACTIVE;
            }
        } elseif ($scan && $scan->vehicle_id && $reason === null) {
            // The existing RFID event already counts this crossing.
            $record->status = VisitorRecord::STATUS_DISMISSED;
            $record->status_note = 'Identified by the linked RFID event; no additional movement.';
        }
        $vehicle = $reason === null && $matches->count() === 1 ? $matches->first() : null;
        $record->forceFill([
            'registration_status' => $reason !== null ? 'review' : ($vehicle ? 'registered' : 'guest'),
            'registration_category' => $vehicle ? \App\Support\VehicleCategory::normalize($vehicle->category) : null,
            'registration_reason' => $reason,
            'vehicle_id' => $vehicle?->id,
        ]);
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
