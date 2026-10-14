{{--
    Gate direction work, Phase D: Visitors › Review, one card at a time.
    A card waits for its plate (unreadable, not sure, or only a possible
    match) and/or its direction (unknown). IN / OUT is already counted when
    the direction is known: Review is for the plate and the matching.
    Keys: C confirm · E type the plate · 1-3 pick a known plate · I / O set
    IN / OUT · N name · X no plate visible · D not a vehicle · U duplicate ·
    J / K next / previous. public/js/visitor-review.js; without JavaScript
    each button posts the form.
--}}
@php
    $gateLabel = fn ($code) => \App\Models\Gate::labelFor($code);
@endphp

<section class="review-queue" data-review-queue data-total="{{ $reviewQueue->count() }}">
    <div class="review-queue-head">
        <h3>To review <span class="badge badge-secondary" data-review-remaining>{{ $reviewQueue->count() }}</span></h3>
        @if ($reviewQueue->isNotEmpty())
            <div class="review-queue-nav" data-review-nav hidden>
                <button type="button" class="button button-secondary button-sm" data-review-prev title="Previous (K)">← Previous</button>
                <span data-review-position>1 of {{ $reviewQueue->count() }}</span>
                <button type="button" class="button button-secondary button-sm" data-review-next title="Next (J)">Skip →</button>
            </div>
        @endif
    </div>
    <p class="field-help review-keys" data-review-keys hidden>Keys: <kbd>C</kbd> confirm · <kbd>E</kbd> type the plate · <kbd>1</kbd>–<kbd>3</kbd> pick a known plate · <kbd>I</kbd> / <kbd>O</kbd> set IN / OUT · <kbd>N</kbd> name · <kbd>X</kbd> no plate visible · <kbd>D</kbd> not a vehicle · <kbd>U</kbd> duplicate · <kbd>J</kbd> / <kbd>K</kbd> next / previous.</p>
    <p class="review-flash" data-review-flash role="status" aria-live="polite" hidden></p>

    @if ($reviewQueue->isEmpty())
        <x-empty-state title="Nothing to review" text="Every plate was read clearly and every vehicle has a direction." />
    @endif

    <datalist id="review-plate-options">
        @foreach ($plateOptions as $option)
            <option value="{{ $option }}"></option>
        @endforeach
    </datalist>

    @foreach ($reviewQueue as $record)
        @php
            $needsPlate = $record->plateNeedsReview();
            $needsDirection = $record->direction === 'UNKNOWN';
            $suggestions = $reviewService->suggestions($record);
            $read = $record->plate_number ?: $record->ocr_plate_number;
            $why = match (true) {
                $record->plate_status === \App\Models\VisitorRecord::PLATE_UNREADABLE => 'Plate unreadable',
                $record->match_confidence === 'low' => 'Possible match',
                $needsPlate => 'Not sure',
                default => null,
            };
        @endphp
        <article class="review-card" id="review-card-{{ $record->id }}" data-review-card data-needs-plate="{{ $needsPlate ? '1' : '0' }}" data-needs-direction="{{ $needsDirection ? '1' : '0' }}">
            <form method="POST" action="{{ route('visitors.records.review', $record) }}" class="review-card-form" data-review-form>
                @csrf
                @method('PATCH')
                {{-- Enter in the plate box saves the plate (the form's first button is its default). --}}
                <button type="submit" name="action" value="correct_plate" class="sr-only" tabindex="-1" aria-hidden="true">Save plate</button>
                <div class="review-media">
                    @if ($record->snapshot_url)
                        <button type="button" class="thumb-button review-snapshot" data-zoom="{{ $record->snapshot_url }}" data-zoom-label="Snapshot of record #{{ $record->id }}">
                            <img src="{{ $record->snapshot_url }}" alt="Snapshot of record #{{ $record->id }}" loading="lazy">
                        </button>
                    @else
                        <div class="review-snapshot review-empty">No snapshot</div>
                    @endif
                    @if ($record->plate_image_url)
                        <button type="button" class="thumb-button review-plate-zoom" data-zoom="{{ $record->plate_image_url }}" data-zoom-label="Plate picture of record #{{ $record->id }}">
                            <img src="{{ $record->plate_image_url }}" alt="Plate picture" loading="lazy">
                        </button>
                    @else
                        <div class="review-plate-zoom review-empty">No plate picture</div>
                    @endif
                </div>

                <div class="review-facts">
                    <div class="review-reasons">
                        @if ($why)<x-badge tone="warning" :label="$why" />@endif
                        @if ($needsDirection)<x-badge tone="warning" label="Direction unknown" />@endif
                    </div>
                    <dl class="review-grid">
                        <div><dt>Camera read</dt><dd><strong class="review-read">{{ $read ?: '—' }}</strong>@if ((float) $record->plate_confidence > 0) <span class="table-subtext">{{ round((float) $record->plate_confidence * 100) }}%</span>@endif</dd></div>
                        <div><dt>Gate</dt><dd>{{ $gateLabel($record->gate) }}</dd></div>
                        <div><dt>Direction</dt><dd data-review-direction>{{ $needsDirection ? 'Unknown' : $record->direction }}</dd></div>
                        <div><dt>Time</dt><dd><x-datetime :value="$record->seen_at" /></dd></div>
                        <div><dt>Vehicle</dt><dd>{{ collect([$record->vehicle_color, $record->vehicle_type])->filter()->implode(' ') ?: '—' }}</dd></div>
                        <div><dt>Plate profile</dt><dd>
                            @if ($record->plateProfile)
                                <a href="{{ route('visitors.profiles.show', $record->plateProfile) }}">{{ $record->plateProfile->plate_number }}</a>
                                @if ($record->plateProfile->owner_name) · {{ $record->plateProfile->owner_name }}@endif
                            @else
                                —
                            @endif
                        </dd></div>
                    </dl>
                    @if ($record->match_reason && $record->match_confidence === 'low')
                        <p class="table-subtext">Possible match: {{ $record->match_reason }}</p>
                    @endif

                    <div class="review-section" data-review-plate @if (! $needsPlate) hidden @endif>
                        @if ($suggestions->isNotEmpty())
                            <div class="review-suggestions" role="group" aria-label="Known plates like this one">
                                @foreach ($suggestions as $index => $suggestion)
                                    <button type="submit" name="action" value="pick_match:{{ $suggestion['profile']->id }}" class="button button-secondary button-sm" data-review-key="{{ $index + 1 }}" title="Pick ({{ $index + 1 }})">
                                        <kbd>{{ $index + 1 }}</kbd> {{ $suggestion['profile']->plate_number }}
                                        <span class="table-subtext">{{ $suggestion['profile']->visit_count }} {{ \Illuminate\Support\Str::plural('pass', $suggestion['profile']->visit_count) }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                        <div class="review-inline">
                            <label class="sr-only" for="review-plate-{{ $record->id }}-input">Plate of record #{{ $record->id }}</label>
                            <input id="review-plate-{{ $record->id }}-input" type="text" name="plate" value="{{ $record->suggestedProfile?->plate_number ?: $read }}" list="review-plate-options" placeholder="ABC 1234" maxlength="30" autocomplete="off" data-review-plate-input>
                            <button type="submit" name="action" value="correct_plate" class="button button-primary button-sm" data-review-enter>Save plate</button>
                        </div>
                        <div class="review-buttons">
                            @if ($record->plate_number)
                                <button type="submit" name="action" value="confirm" class="button button-secondary button-sm" data-review-key="c"><kbd>C</kbd> Confirm {{ $record->plate_number }}</button>
                            @endif
                            <button type="submit" name="action" value="no_plate" class="button button-secondary button-sm" data-review-key="x"><kbd>X</kbd> No plate visible</button>
                        </div>
                    </div>

                    <div class="review-section" data-review-direction-actions @if (! $needsDirection) hidden @endif>
                        <span class="review-label">Direction:</span>
                        <button type="submit" name="action" value="set_direction:IN" class="button button-secondary button-sm" data-review-key="i"><kbd>I</kbd> Set IN</button>
                        <button type="submit" name="action" value="set_direction:OUT" class="button button-secondary button-sm" data-review-key="o"><kbd>O</kbd> Set OUT</button>
                    </div>

                    <div class="review-section review-inline">
                        <label class="review-label" for="review-name-{{ $record->id }}">Name</label>
                        <input id="review-name-{{ $record->id }}" type="text" name="name" value="{{ $record->plateProfile?->owner_name }}" maxlength="120" placeholder="e.g. Juan Dela Cruz (delivery)" autocomplete="off" data-review-name-input>
                        <button type="submit" name="action" value="set_name" class="button button-secondary button-sm">Save name</button>
                    </div>

                    <div class="review-buttons review-dismiss">
                        <span class="review-label">Dismiss:</span>
                        <button type="submit" name="action" value="dismiss:not_vehicle" class="button button-secondary button-sm" data-review-key="d"><kbd>D</kbd> Not a vehicle</button>
                        <button type="submit" name="action" value="dismiss:duplicate" class="button button-secondary button-sm" data-review-key="u"><kbd>U</kbd> Duplicate</button>
                    </div>
                    <p class="field-error" data-review-error hidden></p>
                </div>
            </form>
        </article>
    @endforeach
</section>

{{--
    ROI work, Phase 2: registered records the camera could not tell, saved
    before Gate direction work Phase A (now a registered vehicle's state is used).
--}}
@if ($review->isNotEmpty())
    <x-table title="Registered, direction unknown (older records)">
        <x-slot:filters>
            <p class="field-help">Recorded before the vehicle's state was used for an unclear direction. Not counted until set. Look at the snapshot and the time, then press Set IN or Set OUT.</p>
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
                    <td><strong>{{ $item['plate'] }}</strong><div class="table-subtext">{{ $item['who'] }}</div></td>
                    <td class="nowrap">{{ $gateLabel($item['gate']) }}</td>
                    <td><x-datetime :value="$item['time']" /></td>
                    <td class="table-subtext">{{ $item['reason'] ?: 'Direction unknown' }}</td>
                    <td class="row-actions"><x-set-direction :url="$item['url']" /></td>
                </tr>
            @endforeach
        </tbody>
    </x-table>
@endif

{{-- Gate direction work, Phase D: audit log of Review actions (who, when, before and after). --}}
<x-table title="Recent review actions" :empty="$reviewLog->isEmpty()" empty-title="No review actions yet">
    <thead>
        <tr>
            <th>When</th>
            <th>Who</th>
            <th>Record</th>
            <th>Action</th>
            <th>Before</th>
            <th>After</th>
        </tr>
    </thead>
    <tbody data-review-log>
        @foreach ($reviewLog as $entry)
            <tr>
                <td class="nowrap"><x-datetime :value="$entry->created_at" /></td>
                <td>{{ $entry->user?->name ?? '—' }}</td>
                <td>#{{ $entry->visitor_record_id }} · {{ $entry->record ? $gateLabel($entry->record->gate) : '' }}</td>
                <td>{{ $entry->label() }}</td>
                <td class="table-subtext">{{ $entry->from_value ?? '—' }}</td>
                <td>{{ $entry->to_value ?? '—' }}</td>
            </tr>
        @endforeach
    </tbody>
</x-table>

@push('scripts')
    <script src="{{ asset('js/visitor-review.js') }}"></script>
@endpush
