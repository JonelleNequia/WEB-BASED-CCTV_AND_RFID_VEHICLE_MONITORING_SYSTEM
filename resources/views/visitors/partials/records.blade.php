{{-- Phase 5: rows of Unregistered Visitor records (Visitors page and plate profile). --}}
<thead>
    <tr>
        <th>Snapshot</th>
        <th>Plate</th>
        <th>Gate</th>
        <th>Direction</th>
        <th>Time</th>
        <th>Plate profile</th>
        <th>Actions</th>
    </tr>
</thead>
<tbody>
    @foreach ($records as $record)
        <tr id="visitor-record-{{ $record->id }}">
            <td>
                @if ($record->snapshot_url)
                    <a href="{{ $record->snapshot_url }}" target="_blank" rel="noopener"><img src="{{ $record->snapshot_url }}" alt="Snapshot of record {{ $record->id }}" class="thumb thumb-sm"></a>
                @else
                    <span class="table-subtext">No snapshot</span>
                @endif
            </td>
            <td>
                <strong>{{ $record->plateLabel() }}</strong>
                @if ($record->plate_image_url)
                    <div><a href="{{ $record->plate_image_url }}" target="_blank" rel="noopener"><img src="{{ $record->plate_image_url }}" alt="Plate image" class="thumb thumb-xs"></a></div>
                @endif
                <div class="table-subtext">
                    @switch($record->plate_status)
                        @case('read') Camera{{ $record->plate_confidence !== null ? ' · '.number_format($record->plate_confidence * 100).'%' : '' }} @break
                        @case('corrected') {{ $record->source === 'manual' ? 'Typed by' : 'Corrected by' }} {{ $record->corrector?->name ?? 'a guard' }}{{ $record->ocr_plate_number && $record->ocr_plate_number !== $record->plate_number ? ' · camera read '.$record->ocr_plate_number : '' }} @break
                        @case('unreadable') {{ $record->ocr_plate_number ? 'Best guess: '.$record->ocr_plate_number : 'No plate found' }} @break
                        @default OCR running
                    @endswitch
                </div>
                @if ($record->source === 'manual')
                    <div class="table-subtext">Recorded by hand</div>
                @endif
                @if ($record->vehicle)
                    {{-- Phase 6: the plate is in the Registry (registered later, or its tag was not read). --}}
                    <div class="table-subtext">Registered vehicle{{ $record->seen_at && $record->plateProfile?->registered_at && $record->seen_at->lt($record->plateProfile->registered_at) ? ' (visit before registration)' : ' · tag not read' }}</div>
                @endif
                @if ($record->status !== 'active')
                    <x-badge tone="neutral" :label="ucfirst($record->status)" />
                    <div class="table-subtext">{{ $record->status_note }}</div>
                @endif
            </td>
            <td>{{ \App\Models\Gate::labelFor($record->gate) }}</td>
            <td><x-badge :tone="$record->direction === 'UNKNOWN' ? 'warning' : 'success'" :label="$record->direction === 'UNKNOWN' ? 'Direction unknown' : $record->direction" /></td>
            <td><x-datetime :value="$record->seen_at" /></td>
            <td>
                @if ($record->plateProfile)
                    <a href="{{ route('visitors.profiles.show', $record->plateProfile) }}">{{ $record->plateProfile->plate_number }}</a>
                    <div class="table-subtext">{{ $record->plateProfile->visit_count }} {{ \Illuminate\Support\Str::plural('visit', $record->plateProfile->visit_count) }}</div>
                @else
                    <span class="table-subtext">—</span>
                @endif
            </td>
            <td>
                <details class="inline-action">
                    <summary class="button button-secondary button-sm">Correct plate</summary>
                    <form method="POST" action="{{ route('visitors.records.plate', $record) }}" class="stack-form">
                        @csrf
                        @method('PATCH')
                        <label class="sr-only" for="plate_{{ $record->id }}">Plate number</label>
                        <input id="plate_{{ $record->id }}" type="text" name="plate_number" value="{{ $record->plate_number ?? $record->ocr_plate_number }}" placeholder="ABC 1234" maxlength="30" autocomplete="off">
                        <div class="button-row">
                            <button type="submit" class="button button-primary button-sm">Save plate</button>
                            <button type="submit" name="unreadable" value="1" class="button button-secondary button-sm">Plate unreadable</button>
                        </div>
                    </form>
                </details>
                @if ($record->status === 'active')
                    <details class="inline-action">
                        <summary class="button button-secondary button-sm">Dismiss</summary>
                        <form method="POST" action="{{ route('visitors.records.dismiss', $record) }}" class="stack-form">
                            @csrf
                            @method('PATCH')
                            <label class="sr-only" for="reason_{{ $record->id }}">Reason</label>
                            <input id="reason_{{ $record->id }}" type="text" name="reason" placeholder="e.g. not a vehicle, registered car with its tag" maxlength="150" required>
                            <button type="submit" class="button button-secondary button-sm">Dismiss record</button>
                        </form>
                    </details>
                @endif
            </td>
        </tr>
    @endforeach
</tbody>
