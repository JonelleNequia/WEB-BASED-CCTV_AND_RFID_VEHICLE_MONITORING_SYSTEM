{{--
    UI Phase 4: Dashboard. One row of KPIs, "Needs attention" with an action
    per item, live activity from both gates, and today's traffic per hour.
--}}
@extends('layouts.app')

@section('title', 'Dashboard | PHILCST Vehicle Monitoring')
@section('page-title', 'Dashboard')

@section('content')
    <x-page-header title="Dashboard">
        <x-slot:meta><x-datetime :value="now()" format="date" /> <x-live-indicator /></x-slot:meta>
    </x-page-header>

    <x-stat-row data-dashboard-metrics>
        {{-- Phase 1: shared inside count (VehicleOccupancyService) --}}
        <x-stat label="Inside Campus" :value="$vehiclesInside" metric="vehicles_inside" tone="brand">
            {{-- Phase 7 (visitor model): registered vehicles only; unregistered visitors are counted IN/OUT, never inside. --}}
            <x-slot:detail>
                Registered vehicles:
                @foreach ($insideByCategory as $category => $row)
                    <span data-dashboard-inside="{{ $category }}">{{ $row['inside'] }}</span> {{ $row['label'] }}@if (! $loop->last) · @endif
                @endforeach
            </x-slot:detail>
        </x-stat>
        <x-stat label="IN Today" :value="$totalVehiclesEnteredToday" metric="total_vehicles_entered_today" hint="All categories, all gates" :href="route('logs.index', ['period' => 'today', 'event_type' => 'ENTRY'])" />
        <x-stat label="OUT Today" :value="$totalVehiclesExitedToday" metric="total_vehicles_exited_today" hint="All categories, all gates" :href="route('logs.index', ['period' => 'today', 'event_type' => 'EXIT'])" />
        <x-stat label="Alerts" :value="$alertCounts['total']" metric="alerts_total" :tone="$alertCounts['total'] > 0 ? 'danger' : null" :href="route('logs.index', ['tab' => 'alerts'])">
            <x-slot:detail><span data-dashboard-metric="no_pass_alerts_today">{{ $noPassAlertsToday }}</span> no-pass · <span data-dashboard-metric="pass_alerts_today">{{ $passAlertsToday }}</span> lost/disabled tags</x-slot:detail>
        </x-stat>
    </x-stat-row>

    {{-- Phase 7 (visitor model): IN / OUT per period, category and gate (MovementCountService). --}}
    <section class="panel movement-counts-panel">
        <div class="panel-header panel-header-modern">
            <h2 class="panel-title">IN / OUT Counts</h2>
            <a href="{{ route('logs.index') }}" class="button button-secondary button-sm">Activity logs</a>
        </div>
        <div class="table-responsive">
            <table class="movement-counts" data-dashboard-movement-counts>
                <thead>
                    <tr>
                        <th rowspan="2"></th>
                        @foreach ($movementCounts as $period => $counts)
                            <th colspan="2" class="movement-period">{{ $counts['label'] }}</th>
                        @endforeach
                    </tr>
                    <tr>
                        @foreach ($movementCounts as $period => $counts)
                            <th>IN</th><th>OUT</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @php($first = reset($movementCounts))
                    <tr class="movement-total">
                        <th scope="row">All vehicles</th>
                        @foreach ($movementCounts as $period => $counts)
                            <td data-movement="{{ $period }}.in">{{ $counts['in'] }}</td>
                            <td data-movement="{{ $period }}.out">{{ $counts['out'] }}</td>
                        @endforeach
                    </tr>
                    <tr class="movement-group"><th scope="rowgroup" colspan="{{ 1 + 2 * count($movementCounts) }}">By category</th></tr>
                    @foreach ($first['categories'] as $category => $row)
                        <tr>
                            <th scope="row">{{ $row['label'] }}</th>
                            @foreach ($movementCounts as $period => $counts)
                                <td data-movement="{{ $period }}.categories.{{ $category }}.in">{{ $counts['categories'][$category]['in'] ?? 0 }}</td>
                                <td data-movement="{{ $period }}.categories.{{ $category }}.out">{{ $counts['categories'][$category]['out'] ?? 0 }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr class="movement-group"><th scope="rowgroup" colspan="{{ 1 + 2 * count($movementCounts) }}">By gate</th></tr>
                    @foreach ($first['gates'] as $gate => $row)
                        <tr>
                            <th scope="row">{{ $row['label'] }}</th>
                            @foreach ($movementCounts as $period => $counts)
                                <td data-movement="{{ $period }}.gates.{{ $gate }}.in">{{ $counts['gates'][$gate]['in'] ?? 0 }}</td>
                                <td data-movement="{{ $period }}.gates.{{ $gate }}.out">{{ $counts['gates'][$gate]['out'] ?? 0 }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="field-help">
            Direction unknown (not counted above):
            @foreach ($movementCounts as $period => $counts)
                {{ $counts['label'] }} <strong data-movement="{{ $period }}.unknown">{{ $counts['unknown'] }}</strong>@if (! $loop->last) · @endif
            @endforeach
            . Unregistered visitors are counted IN and OUT but never as inside. A registered vehicle whose plate the camera read without a tag read counts for its category.
        </p>
    </section>

    <div class="dashboard-grid">
        <section class="panel">
            <div class="panel-header panel-header-modern">
                <h2 class="panel-title">Needs attention</h2>
                <a href="{{ route('logs.index', ['tab' => 'alerts']) }}" class="button button-secondary button-sm">All alerts</a>
            </div>
            <ul class="attention-items" data-dashboard-attention>
                @forelse ($attentionItems as $item)
                    <li>
                        <x-badge :tone="$item['tone']" :label="$item['label']" />
                        <div>
                            <strong>{{ $item['title'] }}</strong>
                            <span class="text-muted">{{ $item['detail'] }}</span>
                        </div>
                        <time>{{ $item['time'] }}</time>
                        <a href="{{ $item['action_url'] }}" class="button button-secondary button-sm">{{ $item['action_label'] }}</a>
                    </li>
                @empty
                    <li class="attention-clear">All clear. No anomalies or no-pass alerts.</li>
                @endforelse
            </ul>
        </section>

        <section class="panel">
            <div class="panel-header panel-header-modern">
                <h2 class="panel-title">Live activity</h2>
                <a href="{{ route('gates.index') }}" class="button button-secondary button-sm">Gate Monitor</a>
            </div>
            <div class="panel-scroll-area" data-dashboard-stream="events">
                @forelse ($latestEvents as $event)
                    <article class="stream-item stream-item-compact">
                        <div>
                            <strong>{{ $event['title'] }}</strong>
                            <p>{{ $event['summary'] }}</p>
                            <small>{{ $event['display_time'] }}</small>
                        </div>
                        <span class="badge badge-{{ $event['badge_class'] }}">{{ $event['badge_label'] }}</span>
                    </article>
                @empty
                    <x-empty-state title="No activity yet" text="Scans and camera events from both gates appear here." />
                @endforelse
            </div>
        </section>

        <section class="panel">
            <div class="panel-header panel-header-modern">
                <h2 class="panel-title">Today's traffic</h2>
                <span class="chart-legend"><i class="legend-entries"></i> Entries <i class="legend-exits"></i> Exits</span>
            </div>
            <div class="traffic-chart" data-dashboard-chart role="img" aria-label="Entries and exits per hour today"></div>
        </section>

        <section class="panel">
            <div class="panel-header panel-header-modern">
                {{-- Phase 6 (visitor model): registered and unregistered rankings are separate. --}}
                <h2 class="panel-title">Registered Vehicles · Most Entries</h2>
                <a href="{{ route('logs.index', ['event_type' => 'ENTRY']) }}" class="button button-secondary button-sm">Entry logs</a>
            </div>
            <div class="table-responsive dashboard-ranking" data-dashboard-ranking-table>
                <table>
                    <thead>
                        <tr><th>Rank</th><th>Plate</th><th>Owner</th><th>Category</th><th>Total Entries</th><th>Today</th></tr>
                    </thead>
                    <tbody data-dashboard-ranking>
                        @foreach ($frequentEntryVehicles as $vehicle)
                            <tr>
                                <td><strong>#{{ $loop->iteration }}</strong></td>
                                <td><strong>{{ $vehicle->plate_number }}</strong></td>
                                <td>{{ $vehicle->vehicle_owner_name ?: 'N/A' }}</td>
                                <td>{{ \App\Support\VehicleCategory::label($vehicle->category) }}</td>
                                <td><strong>{{ $vehicle->ranking_total_entries_count ?? $vehicle->total_entries_count }}</strong></td>
                                <td>{{ $vehicle->ranking_entries_today_count ?? $vehicle->entries_today_count_from_logs }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header panel-header-modern">
                <h2 class="panel-title">Visitor Ranking · Unregistered Visitors</h2>
                <a href="{{ route('visitors.index', ['tab' => 'plates', 'sort' => 'entries']) }}" class="button button-secondary button-sm">All plates</a>
            </div>
            <div class="table-responsive dashboard-ranking" data-dashboard-visitor-ranking-table>
                <table>
                    <thead>
                        <tr><th>Rank</th><th>Plate</th><th>Entries</th><th>Today</th><th>Last seen</th><th>Note</th><th></th></tr>
                    </thead>
                    <tbody data-dashboard-visitor-ranking>
                        @foreach ($frequentUnregisteredVisitors as $profile)
                            <tr>
                                <td><strong>#{{ $loop->iteration }}</strong></td>
                                <td><a href="{{ route('visitors.profiles.show', $profile) }}"><strong>{{ $profile->plate_number }}</strong></a></td>
                                <td><strong>{{ $profile->entries_count }}</strong> <span class="table-subtext">of {{ $profile->visit_count }} seen</span></td>
                                <td>{{ $profile->entries_today_count }}</td>
                                <td><x-datetime :value="$profile->last_seen_at" /></td>
                                <td>{{ \Illuminate\Support\Str::limit((string) $profile->note, 40) ?: '—' }}</td>
                                <td><a href="{{ route('registry.index', ['tab' => 'vehicles', 'register_plate' => $profile->id]) }}" class="button button-secondary button-sm">Register this vehicle</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($frequentUnregisteredVisitors->isEmpty())
                    <p class="field-help" data-dashboard-visitor-ranking-empty>No unregistered visitor with a readable plate yet.</p>
                @endif
            </div>
        </section>
    </div>

    <script id="dashboard-live-data" type="application/json">{!! json_encode([
        'routes' => [
            'liveState' => route('dashboard.live-state'),
        ],
        'hourly' => $hourlyTraffic,
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
@endsection

@push('scripts')
    <script src="{{ asset('js/dashboard-live.js') }}"></script>
@endpush
