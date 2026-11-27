<?php

namespace App\Services;

use App\Http\Controllers\VisitorController;
use App\Models\Gate;
use App\Models\RfidTag;
use App\Models\Vehicle;
use App\Models\VisitorRecord;
use App\Support\DisplayTime;
use App\Support\VehicleCategory;

class ListReportService
{
    public const FILTERS = [
        'vehicles' => ['q', 'category', 'state', 'status'],
        'tags' => ['status'],
        'guests' => ['q', 'gate', 'plate_status', 'status', 'record_filter'],
    ];

    public const TAG_STATUSES = ['available' => 'Available', 'assigned' => 'Assigned', 'lost' => 'Lost / Unavailable', 'disabled' => 'Disabled'];

    public const PLATE_RESULTS = ['read' => 'Read by camera', 'corrected' => 'Corrected by guard', 'unreadable' => 'Unreadable / needs review', 'pending' => 'Still reading'];

    public const SHOW = ['active' => 'Counted records', 'duplicate' => 'Duplicates', 'dismissed' => 'Dismissed', 'all' => 'All'];

    public function data(string $dataset, array $filters): array
    {
        $registry = app(VehicleRegistryService::class);
        [$title, $columns, $rows, $groups] = match ($dataset) {
            'vehicles' => ['Vehicle Registry Report', ['id' => 'ID', 'plate' => 'Plate', 'owner' => 'Owner', 'category' => 'Category',
                'type' => 'Vehicle Type', 'registration_status' => 'Registration Status', 'campus_state' => 'Campus State',
                'tag_number' => 'Tag No.', 'uid' => 'RFID UID', 'tag_status' => 'Tag Status', 'registered_by' => 'Registered by', 'last_seen' => 'Last Seen'],
                $registry->registeredVehicles($filters)->map(fn (Vehicle $vehicle) => $this->vehicle($vehicle)),
                ['Registration Status' => 'registration_status', 'Campus State' => 'campus_state']],
            'tags' => ['RFID Tag Inventory Report', ['id' => 'ID', 'tag_number' => 'Tag No.', 'uid' => 'RFID UID', 'status' => 'Status',
                'plate' => 'Vehicle Plate', 'owner' => 'Owner', 'assignment' => 'Assignment', 'last_scan' => 'Last Scan', 'scans' => 'Scans'],
                $registry->filteredRfidTagInventory($filters)->map(fn (RfidTag $tag) => $this->tag($tag)), ['Tag Status' => 'status']],
            'guests' => ['Guest Records Report', ['id' => 'ID', 'time' => 'Time (Asia/Manila)', 'plate' => 'Plate / identifier evidence',
                'classification' => 'Classification', 'source' => 'Source', 'gate' => 'Gate', 'direction' => 'Direction',
                'plate_result' => 'Plate Result', 'status' => 'Record Status', 'type' => 'Vehicle Type', 'type_source' => 'Type Source'],
                app(GuestRecordQuery::class)->query($filters)->get()->map(fn (VisitorRecord $record) => $this->guest($record)),
                ['Classification' => 'classification', 'Source' => 'source']],
        };
        $summaries = [];
        foreach ($groups as $heading => $column) {
            $summaries[$heading] = $rows->countBy(fn (array $row) => $row['cells'][$column])->sortKeys()->all();
        }

        return ['dataset' => $dataset, 'title' => $title, 'columns' => $columns, 'rows' => $rows->values(), 'summaries' => $summaries,
            'supportsImages' => $dataset === 'guests', 'filtersLabel' => $this->filtersLabel($dataset, $filters)];
    }

    private function vehicle(Vehicle $vehicle): array
    {
        $tag = $vehicle->rfidTag;

        return $this->row($vehicle->id, ['id' => $vehicle->id, 'plate' => $vehicle->plate_number, 'owner' => $vehicle->vehicle_owner_name ?: '—',
            'category' => VehicleCategory::label($vehicle->category), 'type' => $vehicle->vehicle_type ?: 'Unknown',
            'registration_status' => ucfirst($vehicle->status ?? 'active'), 'campus_state' => ucfirst(strtolower($vehicle->current_state ?? 'outside')),
            'tag_number' => $tag?->number_label ?: '—', 'uid' => $tag?->uid ?? 'No RFID assigned', 'tag_status' => $tag?->status_label ?? 'No RFID assigned',
            'registered_by' => $vehicle->registered_by ?: '—', 'last_seen' => DisplayTime::datetime($vehicle->last_seen_at, 'Never')]);
    }

    private function tag(RfidTag $tag): array
    {
        return $this->row($tag->id, ['id' => $tag->id, 'tag_number' => $tag->number_label ?: '—', 'uid' => $tag->uid, 'status' => $tag->status_label,
            'plate' => $tag->vehicle?->plate_number ?: '—', 'owner' => $tag->vehicle?->vehicle_owner_name ?: '—',
            'assignment' => $tag->vehicle ? ($tag->status === RfidTag::STATUS_ASSIGNED ? 'Assigned' : 'Previously on vehicle') : 'Unassigned',
            'last_scan' => DisplayTime::datetime($tag->last_scanned_at, 'No scan yet'), 'scans' => $tag->scan_logs_count]);
    }

    private function guest(VisitorRecord $record): array
    {
        return $this->row($record->id, ['id' => $record->id, 'time' => DisplayTime::datetime($record->seen_at),
            'plate' => $record->plateLabel().($record->identifier_label ? ': '.$record->identifier_text : ''),
            'classification' => $record->isRegisteredAtOccurrence() ? 'Registered' : ($record->classificationCategory() === 'review' ? 'Review' : 'Unregistered Guest'),
            'source' => match ($record->source) {
                VisitorRecord::SOURCE_MANUAL => 'Manual entry', VisitorRecord::SOURCE_LEGACY => 'Legacy camera record', default => 'Camera'
            },
            'gate' => Gate::labelFor($record->gate), 'direction' => $record->direction === 'UNKNOWN' ? 'Direction unknown' : $record->direction,
            'plate_result' => $record->identifier_label ? 'Evidence only' : (\App\Services\PlateEvidenceService::candidate($record)
                ? 'Needs review · '.\App\Services\PlateEvidenceService::presentation($record)['reason']
                : ($record->plate_status === VisitorRecord::PLATE_UNREADABLE ? 'Plate unreadable' : (self::PLATE_RESULTS[$record->plate_status] ?? $record->plate_status))), 'status' => ucfirst($record->status),
            'type' => $record->display_vehicle_type, 'type_source' => $record->vehicle_type_source_label],
            $record->snapshot_url ?? $record->plate_image_url, $record->snapshot_url ? 'Snapshot' : 'Plate image');
    }

    private function row(int $id, array $cells, ?string $image = null, ?string $imageLabel = null): array
    {
        return ['id' => $id, 'cells' => array_map(fn ($value) => (string) ($value ?? ''), $cells), 'image_url' => $image, 'image_label' => $imageLabel];
    }

    private function filtersLabel(string $dataset, array $filters): string
    {
        $labels = [];
        foreach ($filters as $name => $value) {
            if (! filled($value)) {
                continue;
            }
            $labels[] = match ($name) {
                'q' => ($dataset === 'guests' ? 'Plate' : 'Search').': '.$value,
                'category' => 'Category: '.VehicleCategory::label($value),
                'state' => 'Campus State: '.ucfirst($value),
                'status' => match ($dataset) {
                    'guests' => 'Show: '.self::SHOW[$value], 'tags' => 'Tag Status: '.self::TAG_STATUSES[$value], default => 'Registration Status: '.ucfirst($value),
                },
                'gate' => 'Gate: '.Gate::labelFor($value),
                'plate_status' => 'Plate result: '.self::PLATE_RESULTS[$value],
                'record_filter' => 'Records: '.VisitorController::RECORD_FILTERS[$value],
            };
        }
        if ($dataset === 'guests' && ! filled($filters['status'] ?? null)) {
            $labels[] = 'Show: Counted records';
        }

        return $labels ? implode(' · ', $labels) : 'All records';
    }
}
