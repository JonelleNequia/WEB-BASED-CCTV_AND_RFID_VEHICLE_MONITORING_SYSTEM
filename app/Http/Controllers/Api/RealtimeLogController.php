<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Camera;
use App\Models\GuestVehicleObservation;
use App\Models\Gate;
use App\Models\VehicleEvent;
use App\Support\DisplayTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class RealtimeLogController extends Controller
{
    public function stationLogs(Request $request): JsonResponse
    {
        $limit = $this->limit($request, 14);

        return response()->json([
            'logs' => $this->stationLogRows($limit),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function guestLogs(Request $request): JsonResponse
    {
        $limit = $this->limit($request, 10);
        $logs = GuestVehicleObservation::query()
            ->with('camera')
            ->orderByDesc('created_at')
            ->orderByDesc('observed_at')
            ->limit($limit)
            ->get()
            ->map(fn (GuestVehicleObservation $observation): array => $this->guestObservationPayload($observation))
            ->values();

        $latest = $logs->first();

        return response()->json([
            'logs' => $logs,
            'latest_capture' => $latest,
            'total' => GuestVehicleObservation::query()->count(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function eventLogs(Request $request): JsonResponse
    {
        $limit = $this->limit($request, 10);

        return response()->json([
            'logs' => $this->eventLogRows($limit),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    protected function limit(Request $request, int $default): int
    {
        return min(50, max(1, (int) $request->integer('limit', $default)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function stationLogRows(int $limit): array
    {
        $eventLogs = VehicleEvent::query()
            ->withoutHiddenGuestCopies()
            ->with(['camera', 'vehicle', 'rfidScanLog', 'guestVisit.rfidTag'])
            ->where('event_status', '!=', VehicleEvent::STATUS_PENDING_DETAILS)
            ->latest('created_at')
            ->latest('event_time')
            ->limit($limit * 5)
            ->get()
            ->map(fn (VehicleEvent $event): array => $this->stationVehicleEventPayload($event));

        $guestLogs = $this->unmirroredGuestObservationsQuery()
            ->with('camera')
            ->latest('created_at')
            ->latest('observed_at')
            ->limit($limit * 3)
            ->get()
            ->map(fn (GuestVehicleObservation $observation): array => $this->stationGuestObservationPayload($observation));

        return $eventLogs
            ->concat($guestLogs)
            ->sortByDesc('sort_time')
            ->take($limit)
            ->map(function (array $log): array {
                unset($log['sort_time']);

                return $log;
            })
            ->values()
            ->all();
    }

    /**
     * UI Phase 2: recent logs for one gate on the Gate Monitor. Phase 1: the
     * gate's IN and OUT events (its camera or its reader), plus its captures.
     *
     * @return list<array<string, mixed>>
     */
    public function gateLogRows(string $location, int $limit = 8): array
    {
        $location = Gate::normalizeCode($location);
        $cameraId = Camera::query()->forRole($location)->value('id');

        $eventLogs = VehicleEvent::query()
            ->withoutHiddenGuestCopies()
            ->with(['camera', 'vehicle', 'rfidScanLog', 'guestVisit.rfidTag'])
            ->where('event_status', '!=', VehicleEvent::STATUS_PENDING_DETAILS)
            ->where(fn ($query) => $query->where('camera_id', $cameraId)
                ->orWhereHas('rfidScanLog', fn ($scan) => $scan->where('scan_location', $location)))
            ->latest('created_at')
            ->latest('event_time')
            ->limit($limit)
            ->get()
            ->map(fn (VehicleEvent $event): array => $this->stationVehicleEventPayload($event));

        $guestLogs = $this->unmirroredGuestObservationsQuery()
            ->with('camera')
            ->where('location', $location)
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (GuestVehicleObservation $observation): array => $this->stationGuestObservationPayload($observation));

        return $eventLogs
            ->concat($guestLogs)
            ->sortByDesc('sort_time')
            ->take($limit)
            ->map(function (array $log): array {
                unset($log['sort_time']);

                return $log;
            })
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function eventLogRows(int $limit): Collection
    {
        $eventLogs = VehicleEvent::query()
            ->withoutHiddenGuestCopies()
            ->with(['camera', 'vehicle', 'rfidScanLog'])
            ->where('event_status', '!=', VehicleEvent::STATUS_PENDING_DETAILS)
            ->latest('created_at')
            ->latest('event_time')
            ->limit($limit * 5)
            ->get()
            ->map(fn (VehicleEvent $event): array => $this->vehicleEventLogPayload($event));

        $guestLogs = $this->unmirroredGuestObservationsQuery()
            ->with('camera')
            ->latest('created_at')
            ->latest('observed_at')
            ->limit($limit * 3)
            ->get()
            ->map(fn (GuestVehicleObservation $observation): array => $this->guestEventLogPayload($observation));

        return $eventLogs
            ->concat($guestLogs)
            ->sortByDesc('sort_time')
            ->take($limit)
            ->values();
    }

    protected function unmirroredGuestObservationsQuery()
    {
        return GuestVehicleObservation::query()
            ->where(function ($query): void {
                $query->whereNull('external_event_key')
                    ->orWhereNotExists(function ($subquery): void {
                        $subquery->selectRaw('1')
                            ->from('vehicle_events')
                            ->whereColumn('vehicle_events.external_event_key', 'guest_vehicle_observations.external_event_key')
                            ->whereIn('vehicle_events.event_origin', ['guest_cctv', 'guest_manual']);
                    });
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function guestObservationPayload(GuestVehicleObservation $observation): array
    {
        return [
            'id' => $observation->id,
            'plate_number' => $observation->plate_number ?: $observation->plate_text,
            'vehicle_type' => $observation->vehicle_type,
            'vehicle_color' => $observation->vehicle_color,
            'location' => $observation->location,
            'location_label' => Gate::labelFor($observation->location),
            'observed_at' => $observation->observed_at?->format('Y-m-d\TH:i'),
            'display_time' => DisplayTime::datetime($observation->observed_at),
            'status' => $observation->status,
            'status_label' => 'Unregistered Visitor',
            'status_badge_class' => 'badge-secondary',
            'notes' => $observation->notes,
            'snapshot_url' => $observation->snapshot_url,
            'camera_name' => $observation->camera?->camera_name ?: 'N/A',
            'update_url' => route('guest-observations.update', $observation),
            'verify_url' => route('guest-observations.verify', $observation),
            'can_verify' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function stationVehicleEventPayload(VehicleEvent $event): array
    {
        $vehicle = $event->vehicle;
        $scanLog = $event->rfidScanLog;
        $entriesToday = $event->daily_entries_count
            ?? $vehicle?->entries_today_count
            ?? 0;
        $exitsToday = $event->daily_exits_count
            ?? $vehicle?->exits_today_count
            ?? 0;

        return [
            'id' => $event->id,
            'record_type' => 'vehicle_event',
            'event_type' => $event->event_type,
            'plate_number' => $event->plate_text ?: $vehicle?->plate_number ?: 'No plate',
            'owner_name' => $vehicle?->vehicle_owner_name ?: $vehicle?->owner_name ?: 'N/A',
            'vehicle_type' => $event->display_vehicle_type,
            'camera_role' => $event->camera?->camera_role,
            'scan_location' => $scanLog?->scan_location,
            'verification_label' => $scanLog?->verificationLabel
                ?? ($event->vehicle_id ? 'Registered' : 'Unregistered Visitor'),
            'resulting_state' => $event->resulting_state ?: 'N/A',
            'entries_today_count' => (int) $entriesToday,
            'exits_today_count' => (int) $exitsToday,
            'event_time' => $event->event_time?->toIso8601String(),
            'display_time' => DisplayTime::datetimeSeconds($event->event_time),
            'status' => $event->display_status_label,
            'sort_time' => $this->sortTimestamp($event->created_at, $event->event_time),
            // Phase 4: guest pass events show the pass number.
            ...VehicleEvent::guestPassLogFields($event),
            // Phase 5: detector no-pass alerts.
            ...VehicleEvent::noPassLogFields($event),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function stationGuestObservationPayload(GuestVehicleObservation $observation): array
    {
        return [
            'id' => 'guest-'.$observation->id,
            'record_type' => 'guest_observation',
            'event_type' => 'UNREGISTERED',
            'plate_number' => $observation->plate_number ?: $observation->plate_text ?: 'No plate',
            'owner_name' => 'N/A',
            'vehicle_type' => $observation->vehicle_type ?: 'Vehicle',
            'camera_role' => $observation->camera?->camera_role,
            'scan_location' => $observation->location,
            'verification_label' => 'Unregistered Visitor',
            'resulting_state' => 'Not tracked',
            'entries_today_count' => 0,
            'exits_today_count' => 0,
            'event_time' => $observation->observed_at?->toIso8601String(),
            'display_time' => DisplayTime::datetimeSeconds($observation->observed_at),
            'status' => 'Unregistered Visitor',
            'snapshot_url' => $observation->snapshot_url,
            'sort_time' => $this->sortTimestamp($observation->created_at, $observation->observed_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function vehicleEventLogPayload(VehicleEvent $event): array
    {
        $vehicle = $event->vehicle;
        $time = $event->event_time;

        return [
            'record_type' => 'vehicle_event',
            'record_type_label' => 'Vehicle Event',
            'id' => $event->id,
            'detail_url' => route('vehicle-events.show', $event),
            'export_url' => route('vehicle-events.export.csv', [
                'record_type' => 'vehicle_event',
                'record_id' => $event->id,
            ]),
            'event_type' => $event->event_type,
            'plate_number' => $event->plate_text ?: $vehicle?->plate_number ?: 'No plate',
            'owner_name' => $vehicle?->vehicle_owner_name ?: $vehicle?->owner_name ?: 'N/A',
            'vehicle_type' => $event->display_vehicle_type,
            'vehicle_color' => $event->vehicle_color ?: 'N/A',
            'category_label' => $this->displayCategory($event->vehicle_category ?: $vehicle?->category),
            'source_label' => $event->event_origin_label,
            'station_label' => $event->camera?->camera_name ?: ($event->roi_name ?: 'No camera linked'),
            'state_label' => $event->resulting_state_label,
            'display_time' => DisplayTime::datetime($time, 'No time'),
            'summary_label' => 'Vehicle Event #'.$event->id.' • '.(DisplayTime::datetime($time, 'No time')),
            'event_time_export' => $time?->toDateTimeString(),
            'status_label' => $event->display_status_label,
            'status_badge_class' => $event->status_badge_class,
            'match_label' => $event->match_display,
            'rfid_tag_uid' => $event->rfidScanLog?->tag_uid ?: 'N/A',
            'image_url' => $event->has_visual_evidence ? $event->vehicle_image_url : null,
            'sort_time' => $this->sortTimestamp($event->created_at, $time),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function guestEventLogPayload(GuestVehicleObservation $observation): array
    {
        $time = $observation->observed_at;
        return [
            'record_type' => 'guest_observation',
            'record_type_label' => 'Unregistered Visitor',
            'id' => $observation->id,
            'detail_url' => route('logs.index', ['tab' => 'alerts', 'plate_text' => $observation->plate_number ?: $observation->plate_text]),
            'export_url' => route('vehicle-events.export.csv', [
                'record_type' => 'guest_observation',
                'record_id' => $observation->id,
            ]),
            'event_type' => 'UNREGISTERED',
            'plate_number' => $observation->plate_number ?: $observation->plate_text ?: 'No plate',
            'owner_name' => 'N/A',
            'vehicle_type' => $observation->vehicle_type ?: 'Vehicle',
            'vehicle_color' => $observation->vehicle_color ?: 'N/A',
            'category_label' => \App\Support\VehicleCategory::LABELS[\App\Support\VehicleCategory::UNREGISTERED_VISITOR],
            'source_label' => $observation->observation_source === 'cctv' ? 'Camera · no registered tag' : 'Manual · no registered tag',
            'station_label' => Gate::labelFor($observation->location),
            'state_label' => 'Not tracked',
            'display_time' => DisplayTime::datetime($time, 'No time'),
            'summary_label' => 'Unregistered Visitor #'.$observation->id.' • '.(DisplayTime::datetime($time, 'No time')),
            'event_time_export' => $time?->toDateTimeString(),
            'status_label' => 'Unregistered Visitor',
            'status_badge_class' => 'secondary',
            'match_label' => 'Unregistered',
            'rfid_tag_uid' => 'N/A',
            'image_url' => $observation->snapshot_path ? $observation->snapshot_url : null,
            'sort_time' => $this->sortTimestamp($observation->created_at, $time),
        ];
    }

    protected function displayCategory(?string $category): string
    {
        // Phase 4 (visitor model): new names, also for older stored values.
        return \App\Support\VehicleCategory::label($category);
    }

    protected function sortTimestamp($createdAt, $eventAt): float
    {
        return (float) ($createdAt?->format('U.u') ?? $eventAt?->format('U.u') ?? 0);
    }
}
