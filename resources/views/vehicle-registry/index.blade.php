@extends('layouts.app')

@section('title', 'Vehicle Registry | PHILCST Vehicle Access Monitoring')
@section('page-title', 'Vehicle Registry')
@section('page-description', 'Register vehicles and assign RFID tags before entrance and exit scanning.')

@section('content')
    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">Step 1 and 2</span>
            <h3>Register vehicles and assign RFID tags</h3>
            <div class="inline-status-list">
                <span class="chip chip-brand">Registry: Active</span>
                <span class="chip chip-soft">Offline Local</span>
            </div>
        </div>

        <div class="hero-panel-actions">
            <a href="{{ route('rfid-scans.index') }}" class="button button-primary">Open RFID Desk</a>
            <a href="{{ route('portals.show', 'entrance') }}" class="button button-secondary">Entrance Station</a>
            <a href="{{ route('portals.show', 'exit') }}" class="button button-secondary">Exit Station</a>
        </div>
    </section>

    <div class="page-grid cards-4">
        <article class="stat-card stat-card-brand">
            <div class="stat-card-head">
                <span class="stat-card-label">Recurring Vehicles</span>
                <span class="stat-card-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5.5 6A2.5 2.5 0 0 0 3 8.5v6A2.5 2.5 0 0 0 5.5 17H6v1a1 1 0 1 0 2 0v-1h8v1a1 1 0 1 0 2 0v-1h.5A2.5 2.5 0 0 0 21 14.5v-6A2.5 2.5 0 0 0 18.5 6h-13M7 9.5a1.5 1.5 0 1 1-1.5 1.5A1.5 1.5 0 0 1 7 9.5m10 0a1.5 1.5 0 1 1-1.5 1.5A1.5 1.5 0 0 1 17 9.5M8.5 7.5l1-2h5l1 2z"/></svg>
                </span>
            </div>
            <strong>{{ $rfidStats['registered_vehicles'] ?? 0 }}</strong>
            <p>Parent, student, faculty/staff, and guard vehicles with RFID workflow.</p>
        </article>

        <article class="stat-card stat-card-brand-soft">
            <div class="stat-card-head">
                <span class="stat-card-label">Inside Campus</span>
                <span class="stat-card-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12 12 4l9 8v8a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg>
                </span>
            </div>
            <strong>{{ $rfidStats['vehicles_inside'] ?? 0 }}</strong>
            <p>Recurring vehicles currently marked inside campus.</p>
        </article>

        <article class="stat-card stat-card-success">
            <div class="stat-card-head">
                <span class="stat-card-label">Registered Scans Today</span>
                <span class="stat-card-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.55 18.7 4.8 13.95a1 1 0 0 1 1.4-1.4l3.35 3.34 8.25-8.24a1 1 0 1 1 1.4 1.4z"/></svg>
                </span>
            </div>
            <strong>{{ $rfidStats['registered_scans_today'] ?? 0 }}</strong>
            <p>State-based ENTRY/EXIT scans from recurring registered vehicles.</p>
        </article>

        <article class="stat-card stat-card-warning">
            <div class="stat-card-head">
                <span class="stat-card-label">Guest Profiles</span>
                <span class="stat-card-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v11a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 17.5zm4 1.5v8l6-4z"/></svg>
                </span>
            </div>
            <strong>{{ $rfidStats['guest_vehicles'] ?? 0 }}</strong>
            <p>Guest profiles in registry; monitor them through Guest Monitoring flow.</p>
        </article>
    </div>

    <div class="page-grid two-column">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>Add or Update Vehicle</h3>
                        @include('layouts.partials.help', [
                            'label' => 'Explain registry form',
                            'text' => 'Use the same plate number to update an existing vehicle. A tag can be assigned immediately or later.',
                        ])
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('vehicle-registry.store') }}" class="stack-form">
                @csrf

                <div class="form-grid">
                    <div class="field">
                        <label for="plate_number">Plate Number</label>
                        <input id="plate_number" type="text" name="plate_number" value="{{ old('plate_number') }}" placeholder="ABC-1234" required>
                    </div>

                    <div class="field">
                        <label for="owner_name">Owner / Assigned User</label>
                        <input id="owner_name" type="text" name="owner_name" value="{{ old('owner_name') }}" placeholder="Faculty, staff, or assigned user">
                    </div>

                    <div class="field">
                        <label for="category">Category</label>
                        <select id="category" name="category" required>
                            @foreach ($vehicleCategories as $category)
                                <option value="{{ $category }}" @selected(old('category', 'faculty_staff') === $category)>
                                    {{ ucfirst(str_replace('_', ' ', $category)) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="status">Vehicle Status</label>
                        <select id="status" name="status" required>
                            <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
                            <option value="inactive" @selected(old('status') === 'inactive')>Inactive</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="vehicle_type">Vehicle Type</label>
                        <select id="vehicle_type" name="vehicle_type" required>
                            @foreach ($vehicleTypes as $vehicleType)
                                <option value="{{ $vehicleType }}" @selected(old('vehicle_type') === $vehicleType)>{{ $vehicleType }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="vehicle_color">Vehicle Color</label>
                        <select id="vehicle_color" name="vehicle_color" required>
                            @foreach ($vehicleColors as $vehicleColor)
                                <option value="{{ $vehicleColor }}" @selected(old('vehicle_color') === $vehicleColor)>{{ $vehicleColor }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="tag_uid">RFID Tag UID</label>
                        <input id="tag_uid" type="text" name="tag_uid" value="{{ old('tag_uid') }}" placeholder="RFID-ABC-1001">
                    </div>

                    <div class="field">
                        <label for="tag_label">Tag Label</label>
                        <input id="tag_label" type="text" name="tag_label" value="{{ old('tag_label') }}" placeholder="Sticker or card label">
                    </div>

                    <div class="field">
                        <label for="tag_status">Tag Status</label>
                        <select id="tag_status" name="tag_status">
                            <option value="active" @selected(old('tag_status', 'active') === 'active')>Active</option>
                            <option value="inactive" @selected(old('tag_status') === 'inactive')>Inactive</option>
                        </select>
                    </div>

                    <div class="field span-full">
                        <label for="notes">Remarks</label>
                        <textarea id="notes" name="notes" rows="3" placeholder="Optional remarks">{{ old('notes') }}</textarea>
                    </div>
                </div>

                <div class="button-row">
                    <button type="submit" class="button button-primary">Save Vehicle</button>
                    <a href="{{ route('rfid-scans.index') }}" class="button button-secondary">Go to RFID Desk</a>
                </div>
            </form>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>Registry Snapshot</h3>
                        @include('layouts.partials.help', [
                            'label' => 'Explain registry snapshot',
                            'text' => 'Registered vehicles and tags stay on the local workstation and support entrance and exit verification.',
                        ])
                    </div>
                </div>
            </div>

            <div class="detail-list">
                <div><span>Saved Tags</span><strong>{{ $registeredTags->count() }}</strong></div>
                <div><span>Evidence Storage</span><strong>Local file saved</strong></div>
                <div><span>Snapshots</span><strong>Available in logs</strong></div>
                <div><span>RFID Exports</span><strong>Download evidence</strong></div>
            </div>

            <div class="mini-note">
                <strong>RFID is the main vehicle identifier.</strong>
                <p>Use RFID tags only for recurring categories (parent, student, faculty/staff, guard). Use Guest Monitoring for guest vehicles.</p>
            </div>
        </section>
    </div>

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>Registered Vehicles</h3>
                    @include('layouts.partials.help', [
                        'label' => 'Explain registered vehicles list',
                        'text' => 'This list shows the local registry used by the RFID desk and station screens.',
                    ])
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Plate</th>
                        <th>Owner</th>
                        <th>Category</th>
                        <th>Vehicle</th>
                        <th>Current State</th>
                        <th>Status</th>
                        <th>Tags</th>
                        <th>Scans</th>
                        <th>Today</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($vehicles as $vehicle)
                        <tr>
                            <td><strong>{{ $vehicle->plate_number }}</strong></td>
                            <td>{{ $vehicle->owner_name ?: 'N/A' }}</td>
                            <td>{{ ucfirst(str_replace('_', ' ', $vehicle->category)) }}</td>
                            <td>{{ $vehicle->vehicle_color }} {{ $vehicle->vehicle_type }}</td>
                            <td>
                                <span class="badge {{ $vehicle->current_state === 'inside' ? 'badge-matched' : 'badge-secondary' }}">
                                    {{ ucfirst($vehicle->current_state) }}
                                </span>
                            </td>
                            <td>
                                <span class="badge {{ $vehicle->status === 'active' ? 'badge-matched' : 'badge-unmatched' }}">
                                    {{ ucfirst($vehicle->status) }}
                                </span>
                            </td>
                            <td>
                                @if ($vehicle->rfidTags->isEmpty())
                                    <span class="badge badge-secondary">No tag</span>
                                @else
                                    <div class="badge-row">
                                        @foreach ($vehicle->rfidTags as $tag)
                                            <span class="badge {{ $tag->status === 'active' ? 'badge-matched' : 'badge-unmatched' }}">
                                                {{ $tag->tag_uid }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td>{{ $vehicle->rfid_scan_logs_count }}</td>
                            <td>
                                E{{ $vehicle->entries_today_count }} / X{{ $vehicle->exits_today_count }}
                            </td>
                            <td>{{ $vehicle->notes ?: 'No remarks' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="table-empty">No registered vehicles yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
