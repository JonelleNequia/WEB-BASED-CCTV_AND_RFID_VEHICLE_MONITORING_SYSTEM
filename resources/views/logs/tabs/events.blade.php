{{--
    UI Phase 4: Activity Logs › All Events. One table for every record, filter
    chips, date range and quick timeframes; snapshot thumbnails open larger;
    Print and CSV live in the toolbar (not on every row).
--}}
@php
    $baseQuery = ['tab' => 'events'] + array_filter($filters ?? [], fn ($value) => filled($value));
    $activeChip = (string) ($filters['log_type'] ?? '');
    $activePeriod = (string) ($filters['period'] ?? '');
    $csvQuery = array_merge($baseQuery, ['all' => 1]);
    unset($csvQuery['tab']);
    // Clicking the active timeframe again clears it.
    $periodLinks = collect(['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month'])
        ->map(fn (string $label, string $period) => [
            'label' => $label,
            'url' => route('logs.index', array_filter(
                array_merge($baseQuery, ['period' => $activePeriod === $period ? null : $period, 'date_from' => null, 'date_to' => null, 'page' => null]),
                fn ($v) => filled($v)
            )),
        ]);
    $hasMoreFilters = filled($filters['category'] ?? null) || filled($filters['event_type'] ?? null) || filled($filters['match_status'] ?? null) || filled($filters['vehicle_owner_name'] ?? null) || filled($filters['gate'] ?? null);
@endphp

<x-stat-row>
    <x-stat :label="$selectedPeriodLabel" :value="$eventLogSummary['total']" hint="Records" data-log-summary="total" />
    <x-stat label="IN" :value="$eventLogSummary['entries']" data-log-summary="entries" />
    <x-stat label="OUT" :value="$eventLogSummary['exits']" data-log-summary="exits" />
    <x-stat label="Unregistered" :value="$eventLogSummary['guests']" tone="brand" hint="Camera, no registered tag" data-log-summary="guests" />
    <x-stat label="RFID Only" :value="$eventLogSummary['rfid']" hint="Scans without a linked event" data-log-summary="rfid" />
</x-stat-row>

<x-table :paginator="$logs" class="printable-report-panel event-log-list-view">
    <x-slot:toolbar>
        <div class="chip-group" role="group" aria-label="Log type">
            @foreach ($logFilterChips as $value => $label)
                <a href="{{ route('logs.index', array_filter(array_merge($baseQuery, ['log_type' => $value, 'page' => null]), fn ($v) => filled($v))) }}"
                   @class(['chip', 'chip-brand' => $activeChip === $value, 'chip-soft' => $activeChip !== $value])
                   @if ($activeChip === $value) aria-current="true" @endif>{{ $label }}</a>
            @endforeach
        </div>

        <div class="chip-group" role="group" aria-label="Timeframe">
            @foreach ($periodLinks as $period => $link)
                <a href="{{ $link['url'] }}"
                   @class(['chip', 'chip-brand' => $activePeriod === $period, 'chip-soft' => $activePeriod !== $period])>{{ $link['label'] }}</a>
            @endforeach
        </div>

        <details class="menu">
            <summary class="button button-secondary button-sm">Print</summary>
            <div class="menu-panel" role="menu" aria-label="Print Reports">
                <span class="menu-title">Print Reports</span>
                @foreach ($printReports as $reportKey => $report)
                    <button type="button" role="menuitem" data-event-log-report-print="{{ $reportKey }}">Print {{ $report['label'] }}</button>
                @endforeach
            </div>
        </details>
        <a href="{{ route('vehicle-events.export.csv', $csvQuery) }}" class="button button-secondary button-sm">CSV</a>
    </x-slot:toolbar>

    <x-slot:filters>
        <form method="GET" action="{{ route('logs.index') }}" class="log-filter-form">
            <input type="hidden" name="tab" value="events">
            @if ($activeChip !== '')
                <input type="hidden" name="log_type" value="{{ $activeChip }}">
            @endif

            <div class="field">
                <label for="plate_text">Plate</label>
                <input id="plate_text" type="search" name="plate_text" value="{{ $filters['plate_text'] ?? '' }}" placeholder="ABC 1234">
            </div>
            {{-- Phase 7 (visitor model): gate filter, also used by CSV and Print. --}}
            <div class="field">
                <label for="gate">Gate</label>
                <select id="gate" name="gate">
                    <option value="">All gates</option>
                    @foreach ($gateOptions as $code => $name)
                        <option value="{{ $code }}" @selected(($filters['gate'] ?? '') === $code)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="date_from">From</label>
                <input id="date_from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
            </div>
            <div class="field">
                <label for="date_to">To</label>
                <input id="date_to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
            </div>

            <div class="log-filter-actions">
                <button type="submit" class="button button-primary button-sm">Apply</button>
                <a href="{{ route('logs.index') }}" class="button button-secondary button-sm">Reset</a>
            </div>

            <details class="more-filters" @if ($hasMoreFilters) open @endif>
                <summary>More filters</summary>
                <div class="more-filters-grid">
                    <div class="field">
                        <label for="vehicle_owner_name">Vehicle Owner Name</label>
                        <input id="vehicle_owner_name" type="text" name="vehicle_owner_name" value="{{ $filters['vehicle_owner_name'] ?? '' }}" placeholder="Juan Dela Cruz">
                    </div>
                    <div class="field">
                        <label for="category">Category</label>
                        <select id="category" name="category">
                            <option value="">All</option>
                            @foreach ($categoryOptions as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['category'] ?? '') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="event_type">Movement</label>
                        <select id="event_type" name="event_type">
                            <option value="">All</option>
                            @foreach (\App\Http\Controllers\VehicleEventController::MOVEMENT_OPTIONS as $movement => $movementLabel)
                                <option value="{{ $movement }}" @selected(($filters['event_type'] ?? '') === $movement)>{{ $movementLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="match_status">Status</label>
                        <select id="match_status" name="match_status">
                            <option value="">All</option>
                            @foreach (['open' => 'Entry', 'closed' => 'Exit', 'matched' => 'Matched', 'unmatched' => 'Unmatched', 'verified' => 'Verified'] as $status => $label)
                                <option value="{{ $status }}" @selected(($filters['match_status'] ?? '') === $status)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </details>

        </form>
    </x-slot:filters>

    <thead>
        <tr>
            <th>Time</th>
            <th><span class="sr-only">Snapshot</span></th>
            <th>Plate</th>
            <th>Vehicle / Color</th>
            <th>Movement</th>
            <th>Log Type</th>
            <th>Gate</th>
            <th>Status</th>
            <th><span class="sr-only">Details</span></th>
        </tr>
    </thead>
    <tbody data-event-log-list data-event-log-print>
        @forelse ($logs as $log)
            @include('logs.partials.event-row', ['log' => $log, 'index' => $loop->index])
        @empty
            <tr><td colspan="9"><x-empty-state title="No records matched the current filters" text="Adjust the filters to widen the list." /></td></tr>
        @endforelse
    </tbody>
</x-table>

<x-modal id="log-details" title="Log Details" size="lg">
    <div class="log-details">
        <div class="log-details-image" data-log-details-image-wrap hidden>
            <img src="" alt="Vehicle snapshot" data-log-details-image>
        </div>
        <dl class="log-details-grid" data-log-details-grid></dl>
    </div>
    <x-slot:footer>
        <a href="#" class="button button-secondary button-sm" data-log-details-export>CSV</a>
        <button type="button" class="button button-secondary button-sm" data-log-details-print>Print</button>
        <a href="#" class="button button-primary button-sm" data-log-details-link>Open Full Record</a>
    </x-slot:footer>
</x-modal>

<x-modal id="snapshot-viewer" title="Snapshot" size="lg">
    <img src="" alt="Snapshot" class="snapshot-full" data-snapshot-full>
</x-modal>

<section class="event-log-print-sheet" data-event-log-print-sheet hidden></section>

<script id="event-log-modal-data" type="application/json">{!! json_encode($logs->getCollection()->values(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
<script id="event-log-report-data" type="application/json" data-payload="{{ base64_encode(json_encode($printReports, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) }}"></script>
<script id="event-log-realtime-data" type="application/json">{!! json_encode([
    // UI Phase 4: page 1 refreshes itself with the same filters.
    'refreshUrl' => (int) request('page', 1) === 1 ? route('logs.index', $baseQuery) : null,
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>

@push('scripts')
    <script src="{{ asset('js/activity-logs.js') }}"></script>
@endpush
