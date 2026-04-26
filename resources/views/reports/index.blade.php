@extends('layouts.app')

@section('title', 'Reports | PHILCST Parking Monitoring')
@section('page-title', 'Reports')
@section('page-description', 'Operational summary of vehicle activity and guest monitoring.')

@section('content')
    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">Operations Summary</span>
            <h3>Daily and date-range reports</h3>
        </div>

        <div class="hero-panel-actions">
            <a href="{{ route('vehicle-events.index') }}" class="button button-secondary">Event Logs</a>
            <a href="{{ route('rfid-scans.index') }}" class="button button-secondary">RFID Desk</a>
            <a href="{{ route('guest-observations.index') }}" class="button button-secondary">Guest Monitoring</a>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>Date Filter</h3>
                    @include('layouts.partials.help', [
                        'label' => 'Report date filter',
                        'text' => 'Select a date range to summarize entries, exits, scans, and guest observations.',
                    ])
                </div>
            </div>
        </div>

        <form method="GET" action="{{ route('reports.index') }}" class="form-grid filter-grid">
            <div class="field">
                <label for="date_from">From</label>
                <input id="date_from" type="date" name="date_from" value="{{ $filters['date_from'] }}">
            </div>

            <div class="field">
                <label for="date_to">To</label>
                <input id="date_to" type="date" name="date_to" value="{{ $filters['date_to'] }}">
            </div>

            <div class="field field-actions">
                <div class="button-row">
                    <button type="submit" class="button button-primary">Apply</button>
                    <a href="{{ route('reports.index') }}" class="button button-secondary">Today</a>
                </div>
            </div>
        </form>
    </section>

    <div class="page-grid cards-4">
        <article class="stat-card stat-card-brand">
            <span class="stat-card-label">Entries</span>
            <strong>{{ $summary['entries'] }}</strong>
            <p>Total entry logs in the selected range.</p>
        </article>

        <article class="stat-card stat-card-success">
            <span class="stat-card-label">Exits</span>
            <strong>{{ $summary['exits'] }}</strong>
            <p>Total exit logs in the selected range.</p>
        </article>

        <article class="stat-card stat-card-brand-soft">
            <span class="stat-card-label">RFID Scans</span>
            <strong>{{ $summary['rfid_scans'] }}</strong>
            <p>{{ $summary['verified_rfid_scans'] }} verified scans.</p>
        </article>

        <article class="stat-card stat-card-warning">
            <span class="stat-card-label">Guest Observations</span>
            <strong>{{ $summary['guest_observations'] }}</strong>
            <p>Guest monitoring records in this range.</p>
        </article>
    </div>

    <div class="page-grid cards-2">
        <article class="stat-card">
            <span class="stat-card-label">Review Queue</span>
            <strong>{{ $summary['review_queue'] }}</strong>
            <p>Records waiting for review confirmation.</p>
        </article>

        <article class="stat-card">
            <span class="stat-card-label">Incomplete Records</span>
            <strong>{{ $summary['incomplete_records'] }}</strong>
            <p>Records that still need completion details.</p>
        </article>
    </div>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Daily Breakdown</h3>
            </div>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Entries</th>
                        <th>Exits</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($dailyBreakdown as $day)
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($day->report_date)->format('M d, Y') }}</td>
                            <td>{{ (int) $day->entry_count }}</td>
                            <td>{{ (int) $day->exit_count }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="table-empty">No records found for the selected range.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
