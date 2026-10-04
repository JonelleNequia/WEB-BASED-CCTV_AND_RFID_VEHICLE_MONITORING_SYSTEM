{{--
    Dashboard. One row of KPIs; IN / OUT counts one period at a time; the
    latest 8 vehicles; "Needs attention"; today's traffic; both rankings in
    one card with tabs (UI Phase 4, layout).
--}}
@extends('layouts.app')

@section('title', 'Dashboard | PHILCST Vehicle Monitoring')
@section('page-title', 'Dashboard')

@section('content')
    <x-page-header title="Dashboard">
        <x-slot:meta><x-datetime :value="now()" format="date" /> <x-live-indicator /></x-slot:meta>
    </x-page-header>

    {{-- UI Phase 4 (layout): one KPI row. --}}
    <x-stat-row data-dashboard-metrics>
        {{-- Phase 1: shared inside count (VehicleOccupancyService); registered vehicles only. --}}
        <x-stat label="Inside Campus" :value="$vehiclesInside" metric="vehicles_inside" tone="brand">
            <x-slot:detail>
                Registered vehicles:
                @foreach ($insideByCategory as $category => $row)
                    <span data-dashboard-inside="{{ $category }}">{{ $row['inside'] }}</span> {{ $row['label'] }}@if (! $loop->last) · @endif
                @endforeach
            </x-slot:detail>
        </x-stat>
        <x-stat label="IN Today" :value="$totalVehiclesEnteredToday" metric="total_vehicles_entered_today" hint="All vehicles, all gates" :href="route('logs.index', ['period' => 'today', 'event_type' => 'ENTRY'])" />
        <x-stat label="OUT Today" :value="$totalVehiclesExitedToday" metric="total_vehicles_exited_today" hint="All vehicles, all gates" :href="route('logs.index', ['period' => 'today', 'event_type' => 'EXIT'])" />
        <x-stat label="Needs Attention" :value="$alertCounts['total']" metric="alerts_total" :tone="$alertCounts['total'] > 0 ? 'danger' : null" :href="route('logs.index', ['tab' => 'alerts'])">
            <x-slot:detail><span data-dashboard-metric="alert_anomalies">{{ $alertCounts['anomalies'] }}</span> anomalies · <span data-dashboard-metric="alert_unknown_tags">{{ $alertCounts['unknown_tags'] }}</span> unknown tags</x-slot:detail>
        </x-stat>
    </x-stat-row>

    <div class="dashboard-grid">
        {{-- Phase 7 (visitor model): IN / OUT per period, category and gate (MovementCountService). UI Phase 4: one period at a time. --}}
        <section class="panel movement-counts-panel" data-segments="dashboard-counts">
            <div class="panel-header panel-header-modern">
                <h2 class="panel-title">IN / OUT Counts</h2>
                <div class="segmented" role="tablist" aria-label="Period">
                    @foreach ($movementCounts as $period => $counts)
                        <button type="button" role="tab" @class(['segmented-option', 'is-active' => $loop->first]) data-segment="{{ $period }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">{{ str_replace('This ', '', $counts['label']) }}</button>
                    @endforeach
                </div>
            </div>
            @foreach ($movementCounts as $period => $counts)
                <div class="movement-period-panel" data-segment-panel="{{ $period }}" @unless ($loop->first) hidden @endunless>
                    <div class="movement-totals">
                        <div><span>IN</span><strong data-movement="{{ $period }}.in">{{ $counts['in'] }}</strong></div>
                        <div><span>OUT</span><strong data-movement="{{ $period }}.out">{{ $counts['out'] }}</strong></div>
                    </div>
                    <div class="movement-split">
                        <table class="movement-counts">
                            <thead><tr><th scope="col">By category</th><th scope="col">IN</th><th scope="col">OUT</th></tr></thead>
                            <tbody>
                                @foreach ($counts['categories'] as $category => $row)
                                    <tr>
                                        <th scope="row">{{ $row['label'] }}</th>
                                        <td data-movement="{{ $period }}.categories.{{ $category }}.in">{{ $row['in'] ?? 0 }}</td>
                                        <td data-movement="{{ $period }}.categories.{{ $category }}.out">{{ $row['out'] ?? 0 }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <table class="movement-counts">
                            <thead><tr><th scope="col">By gate</th><th scope="col">IN</th><th scope="col">OUT</th></tr></thead>
                            <tbody>
                                @foreach ($counts['gates'] as $gate => $row)
                                    <tr>
                                        <th scope="row">{{ $row['label'] }}</th>
                                        <td data-movement="{{ $period }}.gates.{{ $gate }}.in">{{ $row['in'] ?? 0 }}</td>
                                        <td data-movement="{{ $period }}.gates.{{ $gate }}.out">{{ $row['out'] ?? 0 }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="field-help">Direction unknown (not counted): <strong data-movement="{{ $period }}.unknown">{{ $counts['unknown'] }}</strong> · Unregistered visitors count IN/OUT, never as inside.</p>
                </div>
            @endforeach
        </section>

        <section class="panel">
            <div class="panel-header panel-header-modern">
                <h2 class="panel-title">Live activity</h2>
                <a href="{{ route('logs.index') }}" class="button button-secondary button-sm">View all</a>
            </div>
            <div class="dashboard-stream" data-dashboard-stream="events">
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
                    <x-empty-state title="No vehicles have passed yet" text="Vehicles from both gates appear here." />
                @endforelse
            </div>
        </section>

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
                    <li class="attention-clear">All clear. No anomalies or unknown tags.</li>
                @endforelse
            </ul>
        </section>

        <section class="panel">
            <div class="panel-header panel-header-modern">
                <h2 class="panel-title">Today's traffic</h2>
                <span class="chart-legend"><i class="legend-entries"></i> IN <i class="legend-exits"></i> OUT</span>
            </div>
            <div class="traffic-chart" data-dashboard-chart role="img" aria-label="Vehicles IN and OUT per hour today"></div>
        </section>

        {{-- Phase 6 (visitor model): registered and unregistered rankings stay separate. UI Phase 4: one card, two tabs. --}}
        <section class="panel dashboard-wide" data-segments="dashboard-ranking">
            <div class="panel-header panel-header-modern">
                <h2 class="panel-title">Most Entries</h2>
                <div class="segmented" role="tablist" aria-label="Ranking">
                    <button type="button" role="tab" class="segmented-option is-active" data-segment="registered" aria-selected="true">Registered Vehicles</button>
                    <button type="button" role="tab" class="segmented-option" data-segment="visitors" aria-selected="false">Unregistered Visitors</button>
                </div>
            </div>

            <div data-segment-panel="registered">
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
                    <p class="empty-inline" data-dashboard-ranking-empty @if ($frequentEntryVehicles->isNotEmpty()) hidden @endif>No registered vehicle has entered yet.</p>
                </div>
                <div class="panel-footer-link"><a href="{{ route('logs.index', ['event_type' => 'ENTRY']) }}">Entry logs</a></div>
            </div>

            <div data-segment-panel="visitors" hidden>
                <div class="table-responsive dashboard-ranking" data-dashboard-visitor-ranking-table>
                    <table>
                        <thead>
                            <tr><th>Rank</th><th>Plate</th><th>Entries</th><th>Today</th><th>Last seen</th><th></th></tr>
                        </thead>
                        <tbody data-dashboard-visitor-ranking>
                            @foreach ($frequentUnregisteredVisitors as $profile)
                                <tr>
                                    <td><strong>#{{ $loop->iteration }}</strong></td>
                                    <td><a href="{{ route('visitors.profiles.show', $profile) }}"><strong>{{ $profile->plate_number }}</strong></a></td>
                                    <td><strong>{{ $profile->entries_count }}</strong> <span class="table-subtext">of {{ $profile->visit_count }} seen</span></td>
                                    <td>{{ $profile->entries_today_count }}</td>
                                    <td><x-datetime :value="$profile->last_seen_at" /></td>
                                    <td class="row-actions"><a href="{{ route('registry.index', ['tab' => 'vehicles', 'register_plate' => $profile->id]) }}" class="button button-secondary button-sm">Register this vehicle</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="empty-inline" data-dashboard-visitor-ranking-empty @if ($frequentUnregisteredVisitors->isNotEmpty()) hidden @endif>No unregistered visitor with a readable plate yet.</p>
                </div>
                <div class="panel-footer-link"><a href="{{ route('visitors.index', ['tab' => 'plates']) }}">All plates</a></div>
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
