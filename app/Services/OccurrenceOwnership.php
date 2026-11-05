<?php

namespace App\Services;

use App\Exceptions\OccurrenceConflict;
use App\Models\Camera;
use App\Models\Gate;
use App\Models\VehicleCrossing;
use App\Models\VisitorRecord;
use Illuminate\Support\Carbon;

/** Compare immutable occurrence context, not upload time or plate similarity. */
class OccurrenceOwnership
{
    public function crossing(VehicleCrossing $crossing, array $data): void
    {
        $this->context($crossing->external_event_key, $crossing->gate, $crossing->camera_id, $crossing->crossed_at, $data);
        if (isset($data['direction']) && in_array($crossing->direction, ['IN', 'OUT'], true)
            && in_array($data['direction'], ['IN', 'OUT'], true) && $data['direction'] !== $crossing->direction) {
            throw new OccurrenceConflict('Incoming known direction contradicts the recorded occurrence.');
        }
    }

    public function record(VisitorRecord $record, array $data): void
    {
        $this->context($record->external_event_key, $record->gate, $record->camera_id, $record->seen_at, $data);
    }

    public function guest(\App\Models\GuestVehicleObservation $record, array $data): void
    {
        $this->context($record->external_event_key, $record->location, $record->camera_id, $record->observed_at, $data);
    }

    private function context(string $key, ?string $gate, ?int $camera, mixed $at, array $data): void
    {
        $incomingGate = Gate::resolveCode($data['camera_role'], historical: true);
        $incomingCamera = $data['camera_id'] ?? Camera::forRole($incomingGate)->value('id');
        // SQL occurrence columns store whole seconds. Never substitute receipt time.
        if ($key !== $data['external_event_key'] || Gate::resolveCode($gate, historical: true) !== $incomingGate
            || ($camera && (int) $camera !== (int) $incomingCamera)
            || ! $at || Carbon::parse($data['event_time'])->getTimestamp() !== $at->getTimestamp()) {
            throw new OccurrenceConflict('Incoming occurrence key, gate, camera or time disagrees with recorded evidence.');
        }
    }
}
