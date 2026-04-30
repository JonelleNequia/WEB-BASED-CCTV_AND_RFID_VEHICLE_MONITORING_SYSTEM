@extends('layouts.app')

@section('title', 'Guest Monitoring | PHILCST Parking Monitoring')
@section('page-title', 'Guest Monitoring')
@section('page-description', 'Manual and CCTV-supported guest vehicle observation records for parking and gate operations.')

@section('content')
    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">CCTV Support</span>
            <h3>Guest vehicle observation desk</h3>
            <div class="inline-status-list">
                <span class="chip chip-brand">Guest flow</span>
                <span class="chip chip-soft">No RFID required</span>
            </div>
        </div>

        <div class="hero-panel-actions">
            <a href="{{ route('stations.entrance') }}" class="button button-secondary">Entrance Station</a>
            <a href="{{ route('stations.exit') }}" class="button button-secondary">Exit Station</a>
            <a href="{{ route('vehicle-events.index') }}" class="button button-secondary">Event Logs</a>
        </div>
    </section>

    <div class="page-grid cards-4">
        <article class="stat-card stat-card-warning">
            <div class="stat-card-head">
                <span class="stat-card-label">Guest Observations Today</span>
            </div>
            <strong>{{ $guestCountToday }}</strong>
            <p>Guest entries and parking observations recorded today.</p>
        </article>
    </div>

    <div class="page-grid two-column">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>Add Guest Observation</h3>
                        @include('layouts.partials.help', [
                            'label' => 'Explain guest observation form',
                            'text' => 'Use this form for guest vehicles and parking observation. This flow is separate from recurring RFID scanning.',
                        ])
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('guest-observations.store') }}" enctype="multipart/form-data" class="stack-form">
                @csrf

                <div class="form-grid">
                    <div class="field">
                        <label for="plate_text">Plate Number</label>
                        <input id="plate_text" type="text" name="plate_text" value="{{ old('plate_text') }}" placeholder="Optional for guest vehicle">
                    </div>

                    <div class="field">
                        <label for="vehicle_type">Vehicle Type</label>
                        <input id="vehicle_type" type="text" name="vehicle_type" value="{{ old('vehicle_type') }}" placeholder="Car, Van, Motorcycle" required>
                    </div>

                    <div class="field">
                        <label for="vehicle_color">Vehicle Color</label>
                        <input id="vehicle_color" type="text" name="vehicle_color" value="{{ old('vehicle_color') }}" placeholder="Optional">
                    </div>

                    <div class="field">
                        <label for="location">Location</label>
                        <select id="location" name="location" required>
                            @foreach (['entrance' => 'Entrance', 'exit' => 'Exit', 'parking' => 'Parking Area', 'other' => 'Other'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('location', 'parking') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="observation_source">Source</label>
                        <select id="observation_source" name="observation_source" required>
                            <option value="manual" @selected(old('observation_source', 'manual') === 'manual')>Manual</option>
                            <option value="cctv" @selected(old('observation_source') === 'cctv')>CCTV Supported</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="camera_id">Camera</label>
                        <select id="camera_id" name="camera_id">
                            <option value="">No camera selected</option>
                            @foreach ($cameras as $camera)
                                <option value="{{ $camera->id }}" @selected((string) old('camera_id') === (string) $camera->id)>
                                    {{ $camera->camera_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="observed_at">Observation Time</label>
                        <input id="observed_at" type="datetime-local" name="observed_at" value="{{ old('observed_at', now()->format('Y-m-d\TH:i')) }}" required>
                    </div>

                    <div class="field">
                        <label for="snapshot_image">Snapshot</label>
                        <input id="snapshot_image" type="file" name="snapshot_image" accept="image/*">
                    </div>

                    <div class="field span-full">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" rows="3" placeholder="Guard remarks or parking notes">{{ old('notes') }}</textarea>
                    </div>
                </div>

                <div class="button-row">
                    <button type="submit" class="button button-primary">Save Guest Observation</button>
                </div>
            </form>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>Latest Unregistered Capture</h3>
                        @include('layouts.partials.help', [
                            'label' => 'Explain unregistered capture',
                            'text' => 'Unknown RFID scans create a CCTV-supported guest observation with the latest available camera frame.',
                        ])
                    </div>
                </div>
            </div>

            @if ($latestUnregisteredCapture)
                <div class="result-card result-card-warning">
                    <div class="result-card-head">
                        <strong>Guard review needed</strong>
                        <span class="badge badge-manual-review">{{ ucfirst($latestUnregisteredCapture->location) }}</span>
                    </div>
                    <img src="{{ $latestUnregisteredCapture->snapshot_url }}" alt="Unregistered vehicle capture" class="capture-preview">
                    <div class="detail-list">
                        <div><span>Camera</span><strong>{{ $latestUnregisteredCapture->camera?->camera_name ?: 'No camera linked' }}</strong></div>
                        <div><span>Captured</span><strong>{{ $latestUnregisteredCapture->observed_at->format('M d, Y h:i A') }}</strong></div>
                    </div>
                    <p>{{ $latestUnregisteredCapture->notes }}</p>
                </div>
            @else
                <div class="empty-state">
                    <h4>No unregistered capture yet</h4>
                    <p>Unknown RFID scans will appear here with a CCTV snapshot when a latest frame is available.</p>
                </div>
            @endif
        </section>
    </div>

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>Filter Guest Logs</h3>
                    @include('layouts.partials.help', [
                        'label' => 'Explain guest log filters',
                        'text' => 'Filter by plate, location, source, and date range.',
                    ])
                </div>
            </div>
        </div>

            <form method="GET" action="{{ route('guest-observations.index') }}" class="form-grid filter-grid">
                <div class="field">
                    <label for="filter_plate_text">Plate</label>
                    <input id="filter_plate_text" type="text" name="plate_text" value="{{ $filters['plate_text'] ?? '' }}">
                </div>

                <div class="field">
                    <label for="filter_location">Location</label>
                    <select id="filter_location" name="location">
                        <option value="">All</option>
                        @foreach (['entrance' => 'Entrance', 'exit' => 'Exit', 'parking' => 'Parking Area', 'other' => 'Other'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['location'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="filter_observation_source">Source</label>
                    <select id="filter_observation_source" name="observation_source">
                        <option value="">All</option>
                        <option value="manual" @selected(($filters['observation_source'] ?? '') === 'manual')>Manual</option>
                        <option value="cctv" @selected(($filters['observation_source'] ?? '') === 'cctv')>CCTV</option>
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

                <div class="field field-actions">
                    <div class="button-row">
                        <button type="submit" class="button button-secondary">Apply</button>
                        <a href="{{ route('guest-observations.index') }}" class="button button-secondary">Reset</a>
                    </div>
                </div>
            </form>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>Guest Observation Logs</h3>
                    @include('layouts.partials.help', [
                        'label' => 'Explain guest observation logs',
                        'text' => 'Guest observations are intentionally separate from registered RFID activity.',
                    ])
                </div>
            </div>
            <span class="chip chip-soft">{{ $observations->total() }} total</span>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Snapshot</th>
                        <th>Plate</th>
                        <th>Vehicle</th>
                        <th>Location</th>
                        <th>Source</th>
                        <th>Camera</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($observations as $observation)
                        <tr>
                            <td>{{ $observation->observed_at->format('M d, Y h:i A') }}</td>
                            <td><img src="{{ $observation->snapshot_url }}" alt="Guest vehicle snapshot" class="thumb thumb-sm"></td>
                            <td>{{ $observation->plate_text ?: 'No plate' }}</td>
                            <td>{{ trim(($observation->vehicle_color ?: '').' '.($observation->vehicle_type ?: 'N/A')) }}</td>
                            <td>{{ ucfirst($observation->location) }}</td>
                            <td>{{ strtoupper($observation->observation_source) }}</td>
                            <td>{{ $observation->camera?->camera_name ?: 'N/A' }}</td>
                            <td>{{ $observation->notes ?: 'No notes' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="table-empty">No guest observations yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('layouts.partials.pagination', ['paginator' => $observations])
    </section>
@endsection
