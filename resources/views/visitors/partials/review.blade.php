{{--
    Gate direction work, Phase D: Guests › Review, one card at a time.
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
    @if (session('status'))
        <noscript><p class="review-flash" role="status">{{ session('status') }}</p></noscript>
    @endif

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
            $evidence = \App\Services\PlateEvidenceService::presentation($record);
            $read = $evidence['candidate'] ?: ($record->plate_number ?: $record->ocr_plate_number);
            $readConfidence = $evidence['candidate'] ? $evidence['confidence'] : $record->plate_confidence;
            $reviewErrors = (string) old('_review_record_id') === (string) $record->id ? $errors->all() : [];
            $why = match (true) {
                $evidence['candidate'] !== null => 'Needs review · OCR candidate',
                $record->plate_status === \App\Models\VisitorRecord::PLATE_UNREADABLE => 'Plate unreadable',
                $record->match_confidence === 'low' => 'Possible match',
                $needsPlate => 'Not sure',
                default => null,
            };
        @endphp
        <article class="review-card" id="review-card-{{ $record->id }}" data-review-card @if((string) old('_review_record_id') === (string) $record->id) data-review-retry @endif data-needs-plate="{{ $needsPlate ? '1' : '0' }}" data-needs-direction="{{ $needsDirection ? '1' : '0' }}">
            <form method="POST" action="{{ route('visitors.records.review', $record) }}" class="review-card-form" data-review-form>
                @csrf
                @method('PATCH')
                <input type="hidden" name="_review_record_id" value="{{ $record->id }}">
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
                    <p class="field-error" data-review-error role="alert" @if (! $reviewErrors) hidden @endif>{{ implode(' ', $reviewErrors) }}</p>
                    <div class="review-reasons">
                        <span class="field-help" data-registration-reason @if(!$evidence['reason']) hidden @endif>{{ $evidence['reason'] }}</span>
                        @if ($why)<x-badge tone="warning" :label="$why" />@endif
                        @if ($needsDirection)<span data-review-direction-badge><x-badge tone="warning" label="Direction unknown" /></span>@endif
                    </div>
                    <dl class="review-grid">
                        <div><dt>{{ $evidence['source'] ?: 'Camera read' }}</dt><dd><strong class="review-read">{{ $read ?: '—' }}</strong>@if ($readConfidence !== null) <span class="table-subtext" title="OCR evidence confidence does not prove identifier type">{{ round((float) $readConfidence * 100) }}%</span>@endif</dd></div>
                        <div><dt>Gate</dt><dd>{{ $gateLabel($record->gate) }}</dd></div>
                        <div><dt>Direction</dt><dd data-review-direction>{{ $needsDirection ? 'Unknown' : $record->direction }}</dd></div>
                        <div><dt>Time</dt><dd><x-datetime :value="$record->seen_at" /></dd></div>
                        <div><dt>Vehicle</dt><dd>{{ $record->display_vehicle_type }}</dd></div>
                        <div><dt>Plate profile</dt><dd>
                            @if ($record->plateProfile)
                                <a href="{{ route('visitors.profiles.show', $record->plateProfile) }}">{{ $record->plateProfile->plate_number }}</a>
                                @if ($record->plateProfile->owner_name) · {{ $record->plateProfile->owner_name }}@endif
                            @else
                                —
                            @endif
                        </dd></div>
                    </dl>
                    @if ($record->identifier_label)
                        <p class="field-help">{{ $record->identifier_label }}: {{ $record->identifier_text }}. Evidence only; no actual plate assigned.</p>
                    @endif
                    @php($rawReads = \App\Services\PlateEvidenceService::rawReads($record))
                    @if ($rawReads)
                        <details class="review-ocr-evidence">
                            <summary>Raw OCR evidence</summary>
                            <ul>
                                @foreach (array_slice($rawReads, 0, 20) as $raw)
                                    @if (is_array($raw) && is_string($raw['text'] ?? null))
                                        <li><code>{{ mb_substr($raw['text'], 0, 200) }}</code>@if ($raw['truncated'] ?? false) (truncated)@endif</li>
                                    @endif
                                @endforeach
                            </ul>
                        </details>
                    @endif
                    @if ($record->match_reason && $record->match_confidence === 'low')
                        <p class="table-subtext">Possible match: {{ $record->match_reason }}</p>
                    @endif

                    <div class="review-section" data-review-plate @if (! $needsPlate) hidden @endif>
                        <p class="field-help">Save plate confirms that the image shows an actual plate. A matching Registry entry or character pattern alone does not establish the identifier type.</p>
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
                            <input id="review-plate-{{ $record->id }}-input" type="text" name="plate" value="{{ (string) old('_review_record_id') === (string) $record->id ? old('plate', $read) : ($record->plate_number ?: $read) }}" list="review-plate-options" placeholder="ABC 1234" maxlength="30" autocomplete="off" data-review-plate-input data-normalize-plate>
                            <button type="submit" name="action" value="correct_plate" class="button button-primary button-sm" data-review-enter>Save plate</button>
                        </div>
                        <div class="review-buttons">
                            @if ($read && \App\Support\PlateNumber::storageKey($read) && mb_strlen(\App\Support\PlateNumber::display($read)) <= 30)
                                <button type="submit" name="action" value="confirm" class="button button-secondary button-sm" data-review-key="c"><kbd>C</kbd> Confirm {{ $read }} as actual plate</button>
                            @endif
                            <button type="submit" name="action" value="no_plate" class="button button-secondary button-sm" data-review-key="x"><kbd>X</kbd> No plate visible</button>
                        </div>
                        <details class="review-temporary">
                            <summary>Temporary identifier instead of an actual plate</summary>
                            <p class="field-help">Use visible evidence in the images. This saves evidence only and does not match the Registry or create a plate profile.</p>
                            <div class="field">
                                <label for="identifier-type-{{ $record->id }}">Identifier type</label>
                                <select id="identifier-type-{{ $record->id }}" name="identifier_type">
                                    <option value="">Choose the observed type</option>
                                    @foreach (\App\Services\PlateEvidenceService::TEMPORARY as $kind => $label)
                                        <option value="{{ $kind }}" @selected(old('identifier_type') === $kind)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field">
                                <label for="identifier-text-{{ $record->id }}">Identifier text (evidence only)</label>
                                <input id="identifier-text-{{ $record->id }}" type="text" name="identifier_text" maxlength="200" value="{{ old('identifier_text', $record->identifier_text ?: $read) }}">
                            </div>
                            <button type="submit" name="action" value="temporary_identifier" class="button button-secondary button-sm">Confirm temporary identifier</button>
                        </details>
                    </div>

                    <div class="review-section" data-review-direction-actions @if (! $needsDirection) hidden @endif>
                        <span class="review-label">Direction:</span>
                        <button type="submit" name="action" value="set_direction:IN" class="button button-secondary button-sm" data-review-key="i"><kbd>I</kbd> Set IN</button>
                        <button type="submit" name="action" value="set_direction:OUT" class="button button-secondary button-sm" data-review-key="o"><kbd>O</kbd> Set OUT</button>
                    </div>

                    <div class="review-section review-inline">
                        <label class="review-label" for="review-name-{{ $record->id }}">Name</label>
                        <input id="review-name-{{ $record->id }}" type="text" name="name" value="{{ $record->plateProfile?->owner_name }}" placeholder="e.g. Ma. José Dela Cruz" autocomplete="off" data-review-name-input aria-describedby="review-name-help-{{ $record->id }}">
                        <span class="field-help" id="review-name-help-{{ $record->id }}">Optional. New names use letters, spaces, apostrophes, hyphens and periods (up to 120 characters). Existing organization names can be kept unchanged.</span>
                        <button type="submit" name="action" value="set_name" class="button button-secondary button-sm">Save name</button>
                    </div>

                    <div class="review-buttons review-dismiss">
                        <span class="review-label">Dismiss:</span>
                        <button type="submit" name="action" value="dismiss:not_vehicle" class="button button-secondary button-sm" data-review-key="d"><kbd>D</kbd> Not a vehicle</button>
                        <button type="submit" name="action" value="dismiss:duplicate" class="button button-secondary button-sm" data-review-key="u"><kbd>U</kbd> Duplicate</button>
                    </div>
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
