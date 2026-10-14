{{--
    ROI work, Phase 2: passes the camera could not tell IN from OUT
    (registered and unregistered). They are not counted as IN or OUT, and a
    registered vehicle's state is not changed, until they are set here.
--}}
<x-table title="Direction unknown" :empty="$review->isEmpty()" empty-title="Nothing to review" empty-text="Every recorded vehicle has a direction.">
    <x-slot:filters>
        <p class="field-help">The camera saw these vehicles but could not tell whether they went IN or OUT. They are not counted until you set them. Look at the snapshot and the time, then press Set IN or Set OUT.</p>
    </x-slot:filters>
    <thead>
        <tr>
            <th>Snapshot</th>
            <th>Plate</th>
            <th>Gate</th>
            <th>Time</th>
            <th>Why</th>
            <th><span class="sr-only">Set direction</span></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($review as $item)
            <tr id="review-{{ $item['key'] }}">
                <td>
                    @if ($item['snapshot_url'])
                        <button type="button" class="thumb-button" data-zoom="{{ $item['snapshot_url'] }}" data-zoom-label="Snapshot of {{ $item['plate'] }}">
                            <img src="{{ $item['snapshot_url'] }}" alt="Snapshot of {{ $item['plate'] }}" class="thumb thumb-sm" loading="lazy">
                        </button>
                    @else
                        <span class="table-subtext">No snapshot</span>
                    @endif
                </td>
                <td>
                    <div class="plate-cell">
                        <strong>{{ $item['plate'] }}</strong>
                        @if ($item['plate_image_url'])
                            <button type="button" class="thumb-button" data-zoom="{{ $item['plate_image_url'] }}" data-zoom-label="Plate image">
                                <img src="{{ $item['plate_image_url'] }}" alt="Plate image" class="thumb thumb-plate" loading="lazy">
                            </button>
                        @endif
                    </div>
                    <div class="table-subtext">{{ $item['who'] }}</div>
                </td>
                <td class="nowrap">{{ \App\Models\Gate::labelFor($item['gate']) }}</td>
                <td><x-datetime :value="$item['time']" /></td>
                <td class="table-subtext">{{ $item['reason'] ?: 'Direction unknown' }}</td>
                <td class="row-actions"><x-set-direction :url="$item['url']" /></td>
            </tr>
        @endforeach
    </tbody>
</x-table>

{{--
    Gate direction work, Phase B: plates a guard should check. The pass is
    already counted (IN / OUT does not wait for the plate); this is only for
    the plate. Type the plate as seen in the plate picture, or say no plate
    is visible.
--}}
@if ($plateReview !== null)
    <x-table title="Plate to check" :empty="$plateReview->isEmpty()" empty-title="No plate to check" empty-text="Every unregistered visitor's plate was read clearly.">
        <x-slot:filters>
            <p class="field-help">The camera could not read these plates, or is not sure. The pass is already counted. Look at the plate picture, then save the plate, or press "No plate visible".</p>
        </x-slot:filters>
        <thead>
            <tr>
                <th>Snapshot</th>
                <th>Plate picture</th>
                <th>Camera read</th>
                <th>Gate · Direction</th>
                <th>Time</th>
                <th><span class="sr-only">Plate</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($plateReview as $record)
                @php($guess = $record->suggestedProfile?->plate_number ?: ($record->plate_number ?: $record->ocr_plate_number))
                <tr id="review-plate-{{ $record->id }}">
                    <td>
                        @if ($record->snapshot_url)
                            <button type="button" class="thumb-button" data-zoom="{{ $record->snapshot_url }}" data-zoom-label="Snapshot of record #{{ $record->id }}">
                                <img src="{{ $record->snapshot_url }}" alt="Snapshot of record #{{ $record->id }}" class="thumb thumb-sm" loading="lazy">
                            </button>
                        @else
                            <span class="table-subtext">No snapshot</span>
                        @endif
                    </td>
                    <td>
                        @if ($record->plate_image_url)
                            <button type="button" class="thumb-button" data-zoom="{{ $record->plate_image_url }}" data-zoom-label="Plate picture of record #{{ $record->id }}">
                                <img src="{{ $record->plate_image_url }}" alt="Plate picture" class="thumb review-plate-crop" loading="lazy">
                            </button>
                        @else
                            <span class="table-subtext">No plate picture</span>
                        @endif
                    </td>
                    <td>
                        <strong>{{ $guess ?: '—' }}</strong>
                        <div class="table-subtext">
                            {{ $record->plate_status === \App\Models\VisitorRecord::PLATE_UNREADABLE ? 'Plate unreadable' : ($record->match_confidence === 'low' ? 'Possible match' : 'Not sure') }}@if ((float) $record->plate_confidence > 0) · {{ round((float) $record->plate_confidence * 100) }}%@endif
                        </div>
                        <div class="table-subtext">{{ $record->vehicle_type ?: 'Vehicle' }}@if ($record->vehicle_color) · {{ $record->vehicle_color }}@endif</div>
                        @if ($record->suggestedProfile)
                            {{-- Phase C: one character off a plate already seen, not sure enough to tie. --}}
                            <div class="table-subtext" data-suggested-match>Read {{ $record->plate_number }} · possibly <a href="{{ route('visitors.profiles.show', $record->suggestedProfile) }}">{{ $record->suggestedProfile->plate_number }}</a> ({{ $record->suggestedProfile->visit_count }} {{ \Illuminate\Support\Str::plural('visit', $record->suggestedProfile->visit_count) }}): {{ $record->match_reason }}</div>
                        @endif
                    </td>
                    <td class="nowrap">{{ \App\Models\Gate::labelFor($record->gate) }} · {{ $record->direction === 'UNKNOWN' ? 'Direction unknown' : $record->direction }}</td>
                    <td><x-datetime :value="$record->seen_at" /></td>
                    <td class="row-actions">
                        <form method="POST" action="{{ route('visitors.records.plate', $record) }}" class="review-plate-form">
                            @csrf
                            @method('PATCH')
                            <label class="sr-only" for="review-plate-{{ $record->id }}-input">Plate of record #{{ $record->id }}</label>
                            <input id="review-plate-{{ $record->id }}-input" type="text" name="plate_number" value="{{ $guess }}" placeholder="ABC 1234" maxlength="30" autocomplete="off">
                            <button type="submit" class="button button-primary button-sm">Save plate</button>
                            <button type="submit" name="unreadable" value="1" class="button button-secondary button-sm" formnovalidate>No plate visible</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </x-table>
@endif
