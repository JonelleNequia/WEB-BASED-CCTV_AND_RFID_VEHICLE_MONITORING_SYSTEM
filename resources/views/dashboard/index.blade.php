{{--
    UI Phase 4: Dashboard. One row of KPIs, "Needs attention" with an action
    per item, live activity from both gates, and today's traffic per hour.
--}}
@extends('layouts.app')

@section('title', 'Dashboard | PHILCST Vehicle Monitoring')
@section('page-title', 'Dashboard')

@section('content')
    <x-page-header title="Dashboard">
        <x-slot:meta><x-datetime :value="now()" format="date" /></x-slot:meta>
    </x-page-header>

    <x-stat-row data-dashboard-metrics>
        {{-- Phase 1: shared inside count (VehicleOccupancyService) --}}
        <x-stat label="Inside Campus" :value="$vehiclesInside" metric="vehicles_inside" tone="brand"
                :hint="($rfidStats['registered_inside'] ?? 0).' registered · '.($rfidStats['guests_inside'] ?? 0).' guests'" />
        <x-stat label="Entries Today" :value="$totalVehiclesEnteredToday" metric="total_vehicles_entered_today" :href="route('logs.index', ['period' => 'today', 'event_type' => 'ENTRY'])" />
        <x-stat label="Exits Today" :value="$totalVehiclesExitedToday" metric="total_vehicles_exited_today" :href="route('logs.index', ['period' => 'today', 'event_type' => 'EXIT'])" />
        <x-stat label="Active Guests" :value="$activeGuests" metric="active_guests" :href="route('guests.index')">
            <x-slot:detail><span data-dashboard-metric="overstay_guests">{{ $overstayGuests }}</span> overstay</x-slot:detail>
        </x-stat>
        <x-stat label="Alerts" :value="$alertCounts['total']" metric="alerts_total" :tone="$alertCounts['total'] > 0 ? 'danger' : null" :href="route('logs.index', ['tab' => 'alerts'])">
            <x-slot:detail><span data-dashboard-metric="no_pass_alerts_today">{{ $noPassAlertsToday }}</span> no-pass · <span data-dashboard-metric="pass_alerts_today">{{ $passAlertsToday }}</span> pass alerts</x-slot:detail>
        </x-stat>
    </x-stat-row>

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
                    <li class="attention-clear">All clear. No anomalies, overstays, lost passes or no-pass alerts.</li>
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
                <h2 class="panel-title">Frequent Entry Ranking</h2>
                <a href="{{ route('logs.index', ['event_type' => 'ENTRY']) }}" class="button button-secondary button-sm">Entry logs</a>
            </div>
            <div class="table-responsive" data-dashboard-ranking-table>
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
                                <td>{{ ucfirst(str_replace('_', ' ', $vehicle->category)) }}</td>
                                <td><strong>{{ $vehicle->ranking_total_entries_count ?? $vehicle->total_entries_count }}</strong></td>
                                <td>{{ $vehicle->ranking_entries_today_count ?? $vehicle->entries_today_count_from_logs }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
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
