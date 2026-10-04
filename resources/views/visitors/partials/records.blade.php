{{--
    Phase 5: rows of Unregistered Visitor records (Visitors page and plate profile).
    UI Phase 4: snapshot, plate, gate, direction, time and one "⋯" menu
    (dialogs in visitors.partials.record-dialogs).
--}}
<thead>
    <tr>
        <th>Snapshot</th>
        <th>Plate</th>
        <th>Gate</th>
        <th>Direction</th>
        <th>Time</th>
        <th><span class="sr-only">Actions</span></th>
    </tr>
</thead>
<tbody>
    @foreach ($records as $record)
        <tr id="visitor-record-{{ $record->id }}">
            <td>
                @if ($record->snapshot_url)
                    <button type="button" class="thumb-button" data-zoom="{{ $record->snapshot_url }}" data-zoom-label="Snapshot of {{ $record->plateLabel() }}">
                        <img src="{{ $record->snapshot_url }}" alt="Snapshot of record {{ $record->id }}" class="thumb thumb-sm" loading="lazy">
                    </button>
                @else
                    <span class="table-subtext">No snapshot</span>
                @endif
            </td>
            <td>
                <div class="plate-cell">
                    @if ($record->plate_status === 'unreadable')
                        <x-badge tone="warning" label="Plate unreadable" />
                    @elseif ($record->plateProfile)
                        <a href="{{ route('visitors.profiles.show', $record->plateProfile) }}"><strong>{{ $record->plateLabel() }}</strong></a>
                    @else
                        <strong>{{ $record->plateLabel() }}</strong>
                    @endif
                    @if ($record->plate_image_url)
                        <button type="button" class="thumb-button" data-zoom="{{ $record->plate_image_url }}" data-zoom-label="Plate image">
                            <img src="{{ $record->plate_image_url }}" alt="Plate image" class="thumb thumb-plate" loading="lazy">
                        </button>
                    @endif
                </div>
                <div class="table-subtext">
                    @switch($record->plate_status)
                        @case('read') Camera{{ $record->plate_confidence !== null ? ' · '.number_format($record->plate_confidence * 100).'%' : '' }} @break
                        @case('corrected') {{ $record->source === 'manual' ? 'Typed by' : 'Corrected by' }} {{ $record->corrector?->name ?? 'a guard' }}{{ $record->ocr_plate_number && $record->ocr_plate_number !== $record->plate_number ? ' · camera read '.$record->ocr_plate_number : '' }} @break
                        @case('unreadable') {{ $record->ocr_plate_number ? 'Best guess: '.$record->ocr_plate_number : 'No plate found' }} @break
                        @default OCR running
                    @endswitch
                    @if ($record->plateProfile && $record->plateProfile->visit_count > 1)
                        · {{ $record->plateProfile->visit_count }} visits
                    @endif
                    @if ($record->source === 'manual')
                        · Recorded by hand
                    @endif
                </div>
                @if ($record->vehicle)
                    {{-- Phase 6: the plate is in the Registry (registered later, or its tag was not read). --}}
                    <div class="table-subtext">Registered vehicle{{ $record->seen_at && $record->plateProfile?->registered_at && $record->seen_at->lt($record->plateProfile->registered_at) ? ' (visit before registration)' : ' · tag not read' }}</div>
                @endif
                @if ($record->status !== 'active')
                    <x-badge tone="neutral" :label="ucfirst($record->status)" />
                    <div class="table-subtext">{{ $record->status_note }}</div>
                @endif
            </td>
            <td class="nowrap">{{ \App\Models\Gate::labelFor($record->gate) }}</td>
            <td><x-badge :tone="$record->direction === 'UNKNOWN' ? 'warning' : 'info'" :label="$record->direction === 'UNKNOWN' ? 'Direction unknown' : $record->direction" /></td>
            <td><x-datetime :value="$record->seen_at" /></td>
            <td class="row-actions">
                <details class="menu row-menu">
                    <summary class="button button-secondary button-sm" aria-label="Actions for record {{ $record->id }}">⋯</summary>
                    <div class="menu-panel" role="menu">
                        <button type="button" role="menuitem" data-visitor-action="plate"
                                data-action-url="{{ route('visitors.records.plate', $record) }}"
                                data-value="{{ $record->plate_number ?? $record->ocr_plate_number }}">Correct plate</button>
                        @if ($record->plateProfile)
                            <button type="button" role="menuitem" data-visitor-action="note"
                                    data-action-url="{{ route('visitors.profiles.note', $record->plateProfile) }}"
                                    data-value="{{ $record->plateProfile->note }}"
                                    data-label="{{ $record->plateProfile->plate_number }}">Add note</button>
                        @endif
                        @if ($record->status === 'active')
                            <button type="button" role="menuitem" class="menu-item-danger" data-visitor-action="dismiss"
                                    data-action-url="{{ route('visitors.records.dismiss', $record) }}">Dismiss…</button>
                        @endif
                    </div>
                </details>
            </td>
        </tr>
    @endforeach
</tbody>
