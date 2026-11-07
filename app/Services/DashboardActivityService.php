<?php

namespace App\Services;

use App\Models\Gate;
use App\Models\GuestVehicleObservation;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Support\DisplayTime;
use App\Support\VehicleCategory;
use App\Support\VisitorRecordLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Read-only occurrence presentation; evidence links, never plate/time similarity, establish identity. */
class DashboardActivityService
{
    public function recent(int $limit = 8): Collection
    {
        $events = VehicleEvent::query()->withoutHiddenGuestCopies(visitorCopies: true)
            ->with(['camera', 'vehicle', 'rfidScanLog'])
            ->where('event_status', '!=', VehicleEvent::STATUS_PENDING_DETAILS)
            ->latest('created_at')->latest('id')->limit($limit)->get()
            ->map(function (VehicleEvent $event): array {
                $direction = $this->direction(VehicleEvent::directionForEventType($event->event_type));
                $registered = $event->vehicle_id !== null
                    && in_array(VehicleCategory::normalize($event->vehicle_category ?: $event->vehicle?->category), VehicleCategory::REGISTRY, true)
                    && ! in_array($event->event_origin, ['guest_cctv', 'guest_manual'], true);
                $ordinary = in_array($event->display_status, ['open', 'closed', VehicleEvent::STATUS_COMPLETED], true);
                $gate = $event->rfidScanLog?->scan_location ?? $event->camera?->camera_role;

                return $this->row('vehicle_event', $event->id, $event->plate_text ?: $event->vehicle?->plate_number ?: 'No plate',
                    $direction, $event->event_origin_label.' · '.Gate::labelFor($gate).' · '.$event->display_vehicle_type,
                    $event->event_time, $event->created_at,
                    $ordinary ? ($registered ? 'Registered' : 'Unregistered') : $event->display_status_label,
                    $event->status_badge_class);
            });

        $visitors = VisitorRecord::query()->active()->withoutMovementEvent()
            ->whereDoesntHave('crossing.rfidScanLog.correlatedVehicleEvent', fn ($query) => $query->where('event_status', VehicleEvent::STATUS_COMPLETED))
            ->with(['vehicle', 'plateProfile', 'crossing'])
            ->latest('created_at')->latest('id')->limit($limit)->get()
            ->map(function (VisitorRecord $record): array {
                $log = VisitorRecordLog::fields($record);

                return $this->row('visitor_record', $record->id, $record->plateLabel(), $this->direction($record->direction),
                    $log['log_type_label'].' · '.$log['station_label'].' · '.$log['vehicle_type'], $record->seen_at, $record->created_at,
                    $log['status_label'] === 'Direction unknown' ? $log['log_type_label'] : $log['status_label'], $log['status_badge_class']);
            });

        $legacy = $this->legacyQuery()->latest('created_at')->latest('id')->limit($limit)->get()
            ->map(fn (GuestVehicleObservation $observation): array => $this->row('guest_observation', $observation->id,
                $observation->plate_number ?: $observation->plate_text ?: 'No plate',
                $this->direction(VehicleEvent::directionForEventType(VehicleEvent::eventTypeForDirection(data_get($observation->detection_metadata_json, 'direction')))),
                'Unregistered Guest · '.Gate::labelFor($observation->location), $observation->observed_at, $observation->created_at,
                'Unregistered', 'secondary'));

        return $events->concat($visitors)->concat($legacy)
            ->sortBy([['sort_time', 'desc'], ['key', 'desc']])->take($limit)->values();
    }

    public function legacyQuery(): Builder
    {
        return GuestVehicleObservation::query()->notConverted()
            ->where(fn ($query) => $query->whereNull('external_event_key')
                ->orWhereNotIn('external_event_key', VisitorRecord::query()->select('external_event_key')))
            ->where(fn ($query) => $query->whereNull('external_event_key')
                ->orWhereNotIn('external_event_key', VehicleEvent::query()->withoutHiddenGuestCopies(visitorCopies: true)
                    ->where('event_status', '!=', VehicleEvent::STATUS_PENDING_DETAILS)
                    ->whereNotNull('external_event_key')->select('external_event_key')));
    }

    private function direction(?string $direction): string
    {
        return in_array($direction, ['IN', 'OUT'], true) ? $direction : 'Direction unknown';
    }

    private function row(string $type, int $id, string $plate, string $direction, string $summary, $at, $createdAt, string $badge, string $badgeClass): array
    {
        return ['key' => $type.':'.$id, 'record_type' => $type, 'id' => $id,
            'title' => $direction.' • '.$plate, 'direction_label' => $direction, 'summary' => $summary,
            'display_time' => DisplayTime::datetime($at, 'No time'), 'badge_label' => $badge, 'badge_class' => $badgeClass,
            'sort_time' => (float) ($createdAt?->format('U.u') ?? $at?->format('U.u') ?? 0)];
    }
}
