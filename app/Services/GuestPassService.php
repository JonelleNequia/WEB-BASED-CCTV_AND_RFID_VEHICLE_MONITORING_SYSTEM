<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\GuestVehicleObservation;
use App\Models\GuestVisit;
use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Models\VehicleEvent;
use App\Support\PlateNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 3: guest pass (temporary RFID) lifecycle.
 *
 * available --issue at Entrance--> issued --scan at Exit--> available
 *
 * Every issue creates a new GuestVisit. A visit that passes valid_until
 * becomes "overstay"; a pass reported lost becomes "lost".
 */
class GuestPassService
{
    public function __construct(
        protected SettingsService $settingsService,
        protected LocalStorageService $localStorageService
    ) {
    }

    /**
     * Issue an available guest pass and record the guest's ENTRY.
     *
     * @param  array<string, mixed>  $data
     */
    public function issue(RfidTag $pass, array $data, ?int $issuedBy = null, ?RfidScanLog $scanLog = null): GuestVisit
    {
        return DB::transaction(function () use ($pass, $data, $issuedBy, $scanLog): GuestVisit {
            $pass = RfidTag::query()->whereKey($pass->id)->lockForUpdate()->firstOrFail();

            if (! $pass->isGuestPass()) {
                throw ValidationException::withMessages([
                    'rfid_tag_id' => 'Only guest passes can be issued to guests.',
                ]);
            }

            if ($pass->status !== RfidTag::STATUS_AVAILABLE) {
                throw ValidationException::withMessages([
                    'rfid_tag_id' => $pass->label.' is '.$pass->status.' and cannot be issued.',
                ]);
            }

            if ($this->requiresId() && blank($data['id_presented'] ?? null)) {
                throw ValidationException::withMessages([
                    'id_presented' => 'Record the ID the guest left at the gate.',
                ]);
            }

            $entryAt = isset($data['entry_at']) ? Carbon::parse($data['entry_at']) : ($scanLog?->scan_time ?? now());
            $validUntil = isset($data['valid_until'])
                ? Carbon::parse($data['valid_until'])
                : $entryAt->copy()->addMinutes($this->validityMinutes());
            $plate = PlateNumber::normalize($data['plate'] ?? null);
            $snapshot = $data['entry_snapshot'] ?? $this->localStorageService->storeLatestCameraSnapshot('entrance');

            $visit = GuestVisit::query()->create([
                'rfid_tag_id' => $pass->id,
                'active_rfid_tag_id' => $pass->id,
                'plate' => $plate,
                'driver_name' => $data['driver_name'] ?? null,
                'vehicle_type' => $data['vehicle_type'] ?? null,
                'color' => $data['color'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'destination' => $data['destination'] ?? null,
                'id_presented' => $data['id_presented'] ?? null,
                'issued_by' => $issuedBy,
                'entry_at' => $entryAt,
                'entry_snapshot' => $snapshot,
                'valid_until' => $validUntil,
                'status' => GuestVisit::STATUS_ACTIVE,
                'notes' => $data['notes'] ?? null,
            ]);

            $pass->forceFill([
                'status' => RfidTag::STATUS_ISSUED,
                'assigned_at' => $entryAt,
            ])->save();

            $event = VehicleEvent::query()->create([
                'event_type' => 'ENTRY',
                'event_status' => VehicleEvent::STATUS_COMPLETED,
                'event_origin' => 'guest_pass',
                'direction' => 'IN',
                'plate_text' => $plate,
                'plate_number' => $plate !== null ? substr($plate, 0, 20) : null,
                'vehicle_type' => $visit->vehicle_type,
                'detected_vehicle_type' => $visit->vehicle_type,
                'vehicle_color' => $visit->color,
                'vehicle_category' => 'guest',
                'camera_id' => Camera::query()->forRole('entrance')->value('id'),
                'rfid_scan_log_id' => $scanLog?->id,
                'guest_visit_id' => $visit->id,
                'roi_name' => 'Entrance RFID Reader',
                'event_time' => $entryAt,
                'vehicle_image_path' => $snapshot,
                'match_status' => 'open',
                'resulting_state' => 'INSIDE',
                'details_completed_at' => now(),
            ]);

            $scanLog?->forceFill([
                'guest_visit_id' => $visit->id,
                'correlated_vehicle_event_id' => $event->id,
                'resolved_event_type' => 'ENTRY',
                'resulting_state' => 'INSIDE',
                'verification_status' => 'guest_pass_entry',
                'outcome' => 'guest_pass_issued',
            ])->save();

            if (! empty($data['guest_observation_id'])) {
                $this->resolveNoPassAlert((int) $data['guest_observation_id'], $pass);
            }

            return $visit->fresh(['rfidTag', 'vehicleEvents']);
        });
    }

    /**
     * Phase 5: the camera flagged this vehicle as "no pass" before the guard
     * issued a guest pass to it, so the alert is resolved, not open.
     */
    protected function resolveNoPassAlert(int $observationId, RfidTag $pass): void
    {
        $observation = GuestVehicleObservation::query()
            ->whereKey($observationId)
            ->where('observation_source', 'cctv')
            ->where('location', 'entrance')
            ->where('status', '!=', GuestVehicleObservation::STATUS_RESOLVED)
            ->first();

        if (! $observation) {
            return;
        }

        $note = 'Resolved: '.$pass->label.' issued.';

        $observation->forceFill([
            'status' => GuestVehicleObservation::STATUS_RESOLVED,
            'notes' => $this->appendNote($observation->notes, $note),
        ])->save();

        if (filled($observation->external_event_key)) {
            VehicleEvent::query()
                ->where('external_event_key', $observation->external_event_key)
                ->where('event_origin', 'guest_cctv')
                ->update([
                    'match_status' => VehicleEvent::MATCH_NO_PASS_RESOLVED,
                    'anomaly_reason' => $note,
                ]);
        }
    }

    /**
     * Close a visit when the pass is scanned at the Exit (or closed manually).
     */
    public function completeExit(
        GuestVisit $visit,
        ?RfidScanLog $scanLog = null,
        ?string $manualReason = null
    ): GuestVisit {
        return DB::transaction(function () use ($visit, $scanLog, $manualReason): GuestVisit {
            $visit = GuestVisit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();

            if (! $visit->isOpen()) {
                throw ValidationException::withMessages([
                    'guest_visit' => 'This guest visit is already closed.',
                ]);
            }

            $exitAt = $scanLog?->scan_time ?? now();
            $snapshot = $this->localStorageService->storeLatestCameraSnapshot('exit');
            $entryEvent = $visit->vehicleEvents()->where('event_type', 'ENTRY')->latest('id')->first();

            $visit->forceFill([
                'active_rfid_tag_id' => null,
                'exit_at' => $exitAt,
                'exit_snapshot' => $snapshot,
                'status' => GuestVisit::STATUS_COMPLETED,
                'notes' => $this->appendNote($visit->notes, $manualReason ? 'Closed manually: '.$manualReason : null),
            ])->save();

            RfidTag::query()->whereKey($visit->rfid_tag_id)->update([
                'status' => RfidTag::STATUS_AVAILABLE,
                'assigned_at' => null,
            ]);

            $event = VehicleEvent::query()->create([
                'event_type' => 'EXIT',
                'event_status' => VehicleEvent::STATUS_COMPLETED,
                'event_origin' => 'guest_pass',
                'direction' => 'OUT',
                'plate_text' => $visit->plate,
                'plate_number' => $visit->plate !== null ? substr($visit->plate, 0, 20) : null,
                'vehicle_type' => $visit->vehicle_type,
                'detected_vehicle_type' => $visit->vehicle_type,
                'vehicle_color' => $visit->color,
                'vehicle_category' => 'guest',
                'camera_id' => Camera::query()->forRole('exit')->value('id'),
                'rfid_scan_log_id' => $scanLog?->id,
                'guest_visit_id' => $visit->id,
                'roi_name' => $manualReason ? 'Manual close' : 'Exit RFID Reader',
                'event_time' => $exitAt,
                'vehicle_image_path' => $snapshot,
                'matched_entry_id' => $entryEvent?->id,
                'match_status' => 'closed',
                'resulting_state' => 'OUTSIDE',
                'anomaly_reason' => $manualReason ? 'Closed manually: '.$manualReason : null,
                'details_completed_at' => now(),
            ]);

            $entryEvent?->forceFill(['match_status' => 'closed'])->save();

            $scanLog?->forceFill([
                'guest_visit_id' => $visit->id,
                'correlated_vehicle_event_id' => $event->id,
                'resolved_event_type' => 'EXIT',
                'resulting_state' => 'OUTSIDE',
            ])->save();

            return $visit->fresh(['rfidTag', 'vehicleEvents']);
        });
    }

    /**
     * Close a visit by hand, e.g. the guest left without passing the reader.
     */
    public function closeManually(GuestVisit $visit, string $reason): GuestVisit
    {
        return $this->completeExit($visit, null, $reason);
    }

    /**
     * Mark the pass as lost. The visit is closed and the pass cannot be issued
     * again until an admin makes it available.
     */
    public function markLost(GuestVisit $visit, ?string $reason = null): GuestVisit
    {
        return DB::transaction(function () use ($visit, $reason): GuestVisit {
            $visit = GuestVisit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();

            $visit->forceFill([
                'active_rfid_tag_id' => null,
                'exit_at' => $visit->exit_at ?? now(),
                'status' => GuestVisit::STATUS_LOST_TAG,
                'notes' => $this->appendNote($visit->notes, 'Pass reported lost'.($reason ? ': '.$reason : '.')),
            ])->save();

            RfidTag::query()->whereKey($visit->rfid_tag_id)->update(['status' => RfidTag::STATUS_LOST]);

            return $visit->fresh('rfidTag');
        });
    }

    /**
     * Move active visits past valid_until (plus the grace period) to overstay.
     */
    public function markOverstays(): int
    {
        return GuestVisit::query()
            ->where('status', GuestVisit::STATUS_ACTIVE)
            ->whereNotNull('valid_until')
            ->where('valid_until', '<', now()->subMinutes($this->overstayGraceMinutes()))
            ->update(['status' => GuestVisit::STATUS_OVERSTAY, 'updated_at' => now()]);
    }

    public function validityMinutes(): int
    {
        return max(1, $this->settingsService->getInt('guest_pass_validity_minutes', 240));
    }

    public function overstayGraceMinutes(): int
    {
        return max(0, $this->settingsService->getInt('guest_pass_overstay_grace_minutes', 0));
    }

    public function requiresId(): bool
    {
        return $this->settingsService->get('guest_pass_require_id', '1') === '1';
    }

    protected function appendNote(?string $notes, ?string $line): ?string
    {
        if ($line === null) {
            return $notes;
        }

        return trim(($notes ? $notes."\n" : '').$line);
    }
}
