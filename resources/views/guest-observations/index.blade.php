@extends('layouts.app')

@section('title', 'Guest Monitoring | PHILCST Vehicle Monitoring')
@section('page-title', 'Guest Monitoring')
@section('page-description', 'Manual and CCTV-supported guest vehicle observation records for entrance and exit operations.')

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
            <p>Guest vehicle observations recorded today.</p>
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
                            'text' => 'Use this form for guest vehicles at the entrance or exit. This flow is separate from recurring RFID scanning.',
                        ])
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('guest-observations.store') }}" enctype="multipart/form-data" class="stack-form">
                @csrf
                <input type="hidden" name="observation_source" value="manual">

                <div class="form-grid">
                    <div class="field">
                        <label for="plate_number">Plate Number</label>
                        <input id="plate_number" type="text" name="plate_number" value="{{ old('plate_number', old('plate_text')) }}" placeholder="Optional for guest vehicle">
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
                            @foreach (['entrance' => 'Entrance', 'exit' => 'Exit'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('location', 'entrance') === $value)>{{ $label }}</option>
                            @endforeach
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
                        <label for="snapshot">Snapshot</label>
                        <input id="snapshot" type="file" name="snapshot" accept="image/*">
                    </div>

                    <div class="field span-full">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" rows="3" placeholder="Guard remarks">{{ old('notes') }}</textarea>
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
                @php($latestSnapshotUrl = $latestUnregisteredCapture->snapshot_path ? asset('storage/'.$latestUnregisteredCapture->snapshot_path) : $latestUnregisteredCapture->snapshot_url)
                <div class="result-card result-card-warning">
                    <div class="result-card-head">
                        <strong>Guard review needed</strong>
                        <span class="badge badge-manual-review">{{ ucfirst($latestUnregisteredCapture->location) }}</span>
                    </div>
                    <img src="{{ $latestSnapshotUrl }}" alt="Unregistered vehicle capture" class="capture-preview">
                    <div class="detail-list">
                        <div><span>Camera</span><strong>{{ $latestUnregisteredCapture->camera?->camera_name ?: 'No camera linked' }}</strong></div>
                        <div><span>Plate</span><strong>{{ $latestUnregisteredCapture->plate_number ?: $latestUnregisteredCapture->plate_text ?: 'No plate detected' }}</strong></div>
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
                        'text' => 'Filter by plate, location, and date range.',
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
                        @foreach (['entrance' => 'Entrance', 'exit' => 'Exit'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['location'] ?? '') === $value)>{{ $label }}</option>
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
                        <th>Plate Number</th>
                        <th>Vehicle</th>
                        <th>Location</th>
                        <th>Camera</th>
                        <th>Notes</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($observations as $observation)
                        @php($snapshotUrl = $observation->snapshot_path ? asset('storage/'.$observation->snapshot_path) : $observation->snapshot_url)
                        <tr>
                            <td>{{ $observation->observed_at->format('M d, Y h:i A') }}</td>
                            <td><img src="{{ $snapshotUrl }}" alt="Guest vehicle snapshot" class="thumb thumb-sm"></td>
                            <td>{{ $observation->plate_number ?: $observation->plate_text ?: 'No plate' }}</td>
                            <td>{{ trim(($observation->vehicle_color ?: '').' '.($observation->vehicle_type ?: 'N/A')) }}</td>
                            <td>{{ ucfirst($observation->location) }}</td>
                            <td>{{ $observation->camera?->camera_name ?: 'N/A' }}</td>
                            <td>{{ $observation->notes ?: 'No notes' }}</td>
                            <td>
                                <button type="button" class="button button-secondary button-sm" data-guest-view="{{ $observation->id }}">
                                    View Image
                                </button>
                            </td>
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

    <div class="modal-backdrop is-hidden" data-guest-modal>
        <section class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="guest_modal_title">
            <div class="modal-header">
                <div>
                    <span class="panel-kicker">Guest Review</span>
                    <h3 id="guest_modal_title">Captured Vehicle</h3>
                </div>
                <button type="button" class="button button-secondary button-sm" data-guest-modal-close>Close</button>
            </div>

            <div class="guest-review-grid">
                <div>
                    <div class="zoom-image-frame" data-zoom-frame>
                        <img src="" alt="Captured guest vehicle" class="capture-preview zoom-image" data-guest-modal-image>
                    </div>
                    <div class="detail-list">
                        <div><span>Detected Plate</span><strong data-guest-modal-plate>No plate</strong></div>
                        <div><span>Timestamp</span><strong data-guest-modal-time>No time</strong></div>
                        <div><span>Location</span><strong data-guest-modal-location>No location</strong></div>
                        <div><span>Status</span><strong data-guest-modal-status>Pending Review</strong></div>
                    </div>
                </div>

                <form method="POST" action="" class="stack-form" data-guest-modal-form>
                    @csrf
                    @method('PATCH')

                    <div class="form-grid">
                        <div class="field">
                            <label for="modal_plate_number">Plate Number</label>
                            <input id="modal_plate_number" type="text" name="plate_number" data-guest-modal-field="plate_number">
                        </div>

                        <div class="field">
                            <label for="modal_vehicle_type">Vehicle Type</label>
                            <input id="modal_vehicle_type" type="text" name="vehicle_type" data-guest-modal-field="vehicle_type">
                        </div>

                        <div class="field">
                            <label for="modal_vehicle_color">Vehicle Color</label>
                            <input id="modal_vehicle_color" type="text" name="vehicle_color" data-guest-modal-field="vehicle_color">
                        </div>

                        <div class="field">
                            <label for="modal_location">Location</label>
                            <select id="modal_location" name="location" data-guest-modal-field="location">
                                @foreach (['entrance' => 'Entrance', 'exit' => 'Exit'] as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="field">
                            <label for="modal_observed_at">Observation Time</label>
                            <input id="modal_observed_at" type="datetime-local" name="observed_at" data-guest-modal-field="observed_at">
                        </div>

                        <div class="field">
                            <label for="modal_status">Review Status</label>
                            <select id="modal_status" name="status" data-guest-modal-field="status">
                                <option value="pending_review">Pending Review</option>
                                <option value="reviewed">Reviewed</option>
                            </select>
                        </div>

                        <div class="field span-full">
                            <label for="modal_notes">Notes</label>
                            <textarea id="modal_notes" name="notes" rows="4" data-guest-modal-field="notes"></textarea>
                        </div>
                    </div>

                    <div class="button-row">
                        <button type="submit" class="button button-primary">Save Review</button>
                    </div>
                </form>
            </div>
        </section>
    </div>

    @php($guestObservationPayload = $observations->getCollection()->map(fn ($observation) => [
        'id' => $observation->id,
        'plate_number' => $observation->plate_number ?: $observation->plate_text,
        'vehicle_type' => $observation->vehicle_type,
        'vehicle_color' => $observation->vehicle_color,
        'location' => $observation->location,
        'observed_at' => $observation->observed_at?->format('Y-m-d\TH:i'),
        'display_time' => $observation->observed_at?->format('M d, Y h:i A'),
        'status' => $observation->status,
        'status_label' => ucfirst(str_replace('_', ' ', $observation->status)),
        'notes' => $observation->notes,
        'snapshot_url' => $observation->snapshot_path ? asset('storage/'.$observation->snapshot_path) : $observation->snapshot_url,
        'update_url' => route('guest-observations.update', $observation),
    ])->values())
    <script id="guest-observations-data" type="application/json">{!! json_encode($guestObservationPayload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
@endsection

@push('scripts')
    <script>
        (() => {
            const payloadNode = document.getElementById('guest-observations-data');
            const modal = document.querySelector('[data-guest-modal]');

            if (!payloadNode || !modal) {
                return;
            }

            const observations = new Map(JSON.parse(payloadNode.textContent).map((item) => [String(item.id), item]));
            const form = modal.querySelector('[data-guest-modal-form]');
            const image = modal.querySelector('[data-guest-modal-image]');
            const plate = modal.querySelector('[data-guest-modal-plate]');
            const time = modal.querySelector('[data-guest-modal-time]');
            const location = modal.querySelector('[data-guest-modal-location]');
            const status = modal.querySelector('[data-guest-modal-status]');
            const zoomFrame = modal.querySelector('[data-zoom-frame]');

            function setField(name, value) {
                const field = modal.querySelector(`[data-guest-modal-field="${name}"]`);

                if (field) {
                    field.value = value || '';
                }
            }

            function openModal(observation) {
                form.action = observation.update_url;
                image.src = observation.snapshot_url;
                plate.textContent = observation.plate_number || 'No plate detected';
                time.textContent = observation.display_time || 'No time';
                location.textContent = observation.location ? observation.location.charAt(0).toUpperCase() + observation.location.slice(1) : 'No location';
                status.textContent = observation.status_label || 'Pending Review';

                setField('plate_number', observation.plate_number);
                setField('vehicle_type', observation.vehicle_type);
                setField('vehicle_color', observation.vehicle_color);
                setField('location', observation.location);
                setField('observed_at', observation.observed_at);
                setField('status', observation.status || 'pending_review');
                setField('notes', observation.notes);

                modal.classList.remove('is-hidden');
            }

            document.querySelectorAll('[data-guest-view]').forEach((button) => {
                button.addEventListener('click', () => {
                    const observation = observations.get(String(button.dataset.guestView));

                    if (observation) {
                        openModal(observation);
                    }
                });
            });

            modal.querySelector('[data-guest-modal-close]')?.addEventListener('click', () => {
                modal.classList.add('is-hidden');
            });

            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    modal.classList.add('is-hidden');
                }
            });

            if (zoomFrame && image) {
                zoomFrame.addEventListener('mousemove', (event) => {
                    const rect = zoomFrame.getBoundingClientRect();
                    const x = ((event.clientX - rect.left) / rect.width) * 100;
                    const y = ((event.clientY - rect.top) / rect.height) * 100;

                    image.style.transformOrigin = `${x}% ${y}%`;
                    image.classList.add('is-zoomed');
                });

                zoomFrame.addEventListener('mouseleave', () => {
                    image.classList.remove('is-zoomed');
                    image.style.transformOrigin = 'center center';
                });
            }

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    modal.classList.add('is-hidden');
                }
            });
        })();
    </script>
@endpush
