@extends('layouts.app')

@section('title', 'RFID Desk | PHILCST Vehicle Access Monitoring')
@section('page-title', 'RFID Desk')
@section('page-description', 'Primary station for RFID verification, scan results, and automatic vehicle logs.')

@section('content')
    @php($simulationEnabled = ($settings['rfid_simulation_mode'] ?? 'enabled') === 'enabled')

    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">Primary Workflow</span>
            <h3>RFID Scan Simulation and Verification</h3>
            <div class="inline-status-list">
                <span class="chip chip-brand">RFID: Active</span>
                <span class="chip chip-soft">Mode: {{ $simulationEnabled ? 'Simulation' : 'Restricted' }}</span>
            </div>
        </div>

        <div class="hero-panel-actions">
            <a href="{{ route('portals.show', 'entrance') }}" class="button button-primary">Entrance Station</a>
            <a href="{{ route('portals.show', 'exit') }}" class="button button-primary">Exit Station</a>
            <a href="{{ route('vehicle-registry.index') }}" class="button button-secondary">Vehicle Registry</a>
        </div>
    </section>

    <div class="page-grid cards-4">
        <article class="stat-card stat-card-brand">
            <div class="stat-card-head">
                <span class="stat-card-label">Registered Vehicles</span>
                <span class="stat-card-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5.5 6A2.5 2.5 0 0 0 3 8.5v6A2.5 2.5 0 0 0 5.5 17H6v1a1 1 0 1 0 2 0v-1h8v1a1 1 0 1 0 2 0v-1h.5A2.5 2.5 0 0 0 21 14.5v-6A2.5 2.5 0 0 0 18.5 6h-13M7 9.5a1.5 1.5 0 1 1-1.5 1.5A1.5 1.5 0 0 1 7 9.5m10 0a1.5 1.5 0 1 1-1.5 1.5A1.5 1.5 0 0 1 17 9.5M8.5 7.5l1-2h5l1 2z"/></svg>
                </span>
            </div>
            <strong>{{ $rfidStats['registered_vehicles'] ?? 0 }}</strong>
            <p>Recurring registered vehicles in RFID workflow.</p>
        </article>

        <article class="stat-card stat-card-brand-soft">
            <div class="stat-card-head">
                <span class="stat-card-label">Inside Campus</span>
                <span class="stat-card-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5a1 1 0 0 1 1 1v12a1 1 0 1 1-2 0V6a1 1 0 0 1 1-1m4 2a1 1 0 0 1 1 1v8a1 1 0 1 1-2 0V8a1 1 0 0 1 1-1m4-2a1 1 0 0 1 1 1v12a1 1 0 1 1-2 0V6a1 1 0 0 1 1-1m4 3a1 1 0 0 1 1 1v6a1 1 0 1 1-2 0V9a1 1 0 0 1 1-1"/></svg>
                </span>
            </div>
            <strong>{{ $rfidStats['vehicles_inside'] ?? 0 }}</strong>
            <p>Current inside count from state-based RFID tracking.</p>
        </article>

        <article class="stat-card stat-card-success">
            <div class="stat-card-head">
                <span class="stat-card-label">Registered Scans Today</span>
                <span class="stat-card-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.55 18.7 4.8 13.95a1 1 0 0 1 1.4-1.4l3.35 3.34 8.25-8.24a1 1 0 1 1 1.4 1.4z"/></svg>
                </span>
            </div>
            <strong>{{ $rfidStats['registered_scans_today'] ?? 0 }}</strong>
            <p>RFID scans converted to ENTRY/EXIT by current vehicle state.</p>
        </article>

        <article class="stat-card stat-card-warning">
            <div class="stat-card-head">
                <span class="stat-card-label">Needs Attention</span>
                <span class="stat-card-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 1 21h22zm0 6a1 1 0 0 1 1 1v4a1 1 0 1 1-2 0V9a1 1 0 0 1 1-1m0 9a1.25 1.25 0 1 1 1.25-1.25A1.25 1.25 0 0 1 12 17"/></svg>
                </span>
            </div>
            <strong>{{ $rfidStats['attention_today'] ?? 0 }}</strong>
            <p>Scans that need checking before access is trusted.</p>
        </article>
    </div>

    <div class="page-grid two-column">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>Scan RFID</h3>
                        @include('layouts.partials.help', [
                            'label' => 'Explain RFID scan form',
                            'text' => 'For recurring registered vehicles, scan type is decided automatically from current state: outside means ENTRY, inside means EXIT.',
                        ])
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('rfid-scans.store') }}" class="stack-form" data-rfid-scan-form>
                @csrf

                <div class="form-grid">
                    <div class="field span-full">
                        <label for="vehicle_rfid_tag_id">Registered Tag</label>
                        <select id="vehicle_rfid_tag_id" name="vehicle_rfid_tag_id">
                            <option value="">Select a registered tag</option>
                            @foreach ($registeredTags as $tag)
                                <option value="{{ $tag->id }}" @selected((string) old('vehicle_rfid_tag_id') === (string) $tag->id)>
                                    {{ $tag->tag_uid }} | {{ $tag->vehicle?->plate_number ?? 'No vehicle linked' }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field span-full">
                        <label for="tag_uid">Or Enter Tag UID</label>
                        <input id="tag_uid" type="text" name="tag_uid" value="{{ old('tag_uid') }}" placeholder="UNKNOWN-TAG-0001">
                    </div>

                    <div class="field">
                        <label for="scan_location">Station</label>
                        <select id="scan_location" name="scan_location" required>
                            <option value="entrance" @selected(old('scan_location', 'entrance') === 'entrance')>Entrance</option>
                            <option value="exit" @selected(old('scan_location') === 'exit')>Exit</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="reader_name">Reader Name</label>
                        <input id="reader_name" type="text" name="reader_name" value="{{ old('reader_name') }}" placeholder="{{ $settings['entrance_rfid_reader_name'] }}">
                    </div>

                    <div class="field">
                        <label for="scan_time">Scan Time</label>
                        <input id="scan_time" type="datetime-local" name="scan_time" value="{{ old('scan_time', now()->format('Y-m-d\TH:i')) }}">
                    </div>

                    <div class="field span-full">
                        <label for="notes">Remarks</label>
                        <textarea id="notes" name="notes" rows="3" placeholder="Optional remarks">{{ old('notes') }}</textarea>
                    </div>
                </div>

                <div class="mini-note">
                    <strong>State-based RFID logic is active.</strong>
                    <p>Same station scan can become ENTRY or EXIT depending on the vehicle's latest inside/outside state.</p>
                </div>

                <div class="button-row">
                    <button type="submit" class="button button-primary {{ $simulationEnabled ? '' : 'button-disabled' }}" @disabled(! $simulationEnabled)>Simulate RFID Scan</button>
                    <a href="{{ route('vehicle-registry.index') }}" class="button button-secondary">Manage Registry</a>
                </div>
            </form>

            <div class="mini-note" data-rfid-scan-result hidden></div>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>Latest Result</h3>
                        @include('layouts.partials.help', [
                            'label' => 'Explain latest result',
                            'text' => 'This shows the most recent scan outcome, including whether the vehicle was recognized and whether a vehicle log was linked automatically.',
                        ])
                    </div>
                </div>
            </div>

            @php($latestScan = $scanLogs->first())
            @if ($latestScan)
                <div class="result-card result-card-{{ $latestScan->verification_status === 'verified' ? 'success' : 'warning' }}">
                    <div class="result-card-head">
                        <strong>{{ $latestScan->verificationLabel }}</strong>
                        <span class="badge badge-{{ $latestScan->verificationBadgeClass }}">{{ $latestScan->scanLocationLabel }}</span>
                    </div>
                    <div class="detail-list">
                        <div><span>Tag UID</span><strong>{{ $latestScan->tag_uid }}</strong></div>
                        <div><span>Vehicle</span><strong>{{ $latestScan->vehicle?->plate_number ?? 'Unknown vehicle' }}</strong></div>
                        <div><span>Category</span><strong>{{ $latestScan->vehicle?->category ? ucfirst(str_replace('_', ' ', $latestScan->vehicle->category)) : 'N/A' }}</strong></div>
                        <div><span>Event Type</span><strong>{{ $latestScan->resolvedEventTypeLabel }}</strong></div>
                        <div><span>Current State</span><strong>{{ $latestScan->resultingStateLabel }}</strong></div>
                        <div><span>Reader</span><strong>{{ $latestScan->reader_name }}</strong></div>
                        <div><span>Time</span><strong>{{ $latestScan->scan_time->format('M d, Y h:i A') }}</strong></div>
                    </div>

                    <div class="mini-note">
                        <strong>
                            {{ $latestScan->correlatedVehicleEvent
                                ? 'Vehicle log #'.$latestScan->correlatedVehicleEvent->id.' linked automatically.'
                                : 'No vehicle log linked yet.' }}
                        </strong>
                        <p>{{ $latestScan->vehicle?->vehicle_type ?: 'Check registry details or guest observation for this scan.' }}</p>
                        @if ($latestScan->guestVehicleObservation)
                            <p>Guest observation #{{ $latestScan->guestVehicleObservation->id }} is ready for manual review.</p>
                        @endif
                    </div>
                </div>
            @else
                <div class="empty-state">
                    <h4>No scan result yet</h4>
                    <p>Record the first RFID scan to see the latest result here.</p>
                </div>
            @endif
        </section>
    </div>

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>RFID Scan History</h3>
                    @include('layouts.partials.help', [
                        'label' => 'Explain RFID scan history',
                        'text' => 'Verified scans can feed vehicle logs automatically. Attention states stay listed here even when no vehicle log is created.',
                    ])
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Tag UID</th>
                        <th>Vehicle</th>
                        <th>Category</th>
                        <th>Station</th>
                        <th>Event Type</th>
                        <th>Current State</th>
                        <th>Reader</th>
                        <th>Result</th>
                        <th>Vehicle Log</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($scanLogs as $scan)
                        <tr>
                            <td>{{ $scan->scan_time->format('M d, Y h:i A') }}</td>
                            <td><strong>{{ $scan->tag_uid }}</strong></td>
                            <td>
                                <strong>{{ $scan->vehicle?->plate_number ?? 'Unknown vehicle' }}</strong>
                                <div class="table-subtext">{{ $scan->vehicle?->vehicle_type ?: 'No registered vehicle linked' }}</div>
                                @if ($scan->guestVehicleObservation)
                                    <div class="table-subtext">Guest observation #{{ $scan->guestVehicleObservation->id }}</div>
                                @endif
                            </td>
                            <td>{{ $scan->vehicle?->category ? ucfirst(str_replace('_', ' ', $scan->vehicle->category)) : 'N/A' }}</td>
                            <td>
                                <strong>{{ $scan->scanLocationLabel }}</strong>
                                <div class="table-subtext">{{ $scan->scanDirectionLabel }}</div>
                            </td>
                            <td>{{ $scan->resolvedEventTypeLabel }}</td>
                            <td>{{ $scan->resultingStateLabel }}</td>
                            <td>{{ $scan->reader_name }}</td>
                            <td>
                                <span class="badge badge-{{ $scan->verificationBadgeClass }}">{{ $scan->verificationLabel }}</span>
                            </td>
                            <td>
                                @if ($scan->correlatedVehicleEvent)
                                    <strong>#{{ $scan->correlatedVehicleEvent->id }}</strong>
                                    <div class="table-subtext">{{ $scan->correlatedVehicleEvent->event_type }} • {{ $scan->correlatedVehicleEvent->plate_text ?: 'Incomplete record' }}</div>
                                @else
                                    <span class="table-subtext">No linked vehicle log</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="table-empty">No RFID scan history yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.querySelector('[data-rfid-scan-form]');
            const resultBox = document.querySelector('[data-rfid-scan-result]');
            const registeredTagSelect = document.getElementById('vehicle_rfid_tag_id');
            const manualTagInput = document.getElementById('tag_uid');

            if (!form || !resultBox) {
                return;
            }

            if (registeredTagSelect && manualTagInput) {
                registeredTagSelect.addEventListener('change', () => {
                    if (registeredTagSelect.value) {
                        manualTagInput.value = '';
                    }
                });

                manualTagInput.addEventListener('input', () => {
                    if (manualTagInput.value.trim() !== '') {
                        registeredTagSelect.value = '';
                    }
                });
            }

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                resultBox.hidden = false;
                resultBox.textContent = 'Recording RFID scan...';

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: new FormData(form),
                    });
                    const payload = await response.json();

                    if (!response.ok) {
                        resultBox.textContent = payload.message || 'RFID scan could not be recorded.';
                        return;
                    }

                    const scan = payload.scan || {};
                    resultBox.textContent = `${payload.message} ${scan.guest_observation_id ? 'Guest observation #' + scan.guest_observation_id + ' was created for review.' : ''} Refreshing latest result...`;
                    window.setTimeout(() => {
                        window.location.href = '{{ route('rfid-scans.index') }}';
                    }, 500);
                } catch (error) {
                    resultBox.textContent = 'RFID scan could not reach the server.';
                }
            });
        });
    </script>
@endpush
