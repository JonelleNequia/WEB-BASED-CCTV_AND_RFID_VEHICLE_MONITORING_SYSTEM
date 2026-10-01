{{-- UI Phase 2: Activity Logs › Alerts (no-pass alerts, flagged scans). Was Guest Monitoring. --}}
    <x-stat-row>
        <x-stat label="No-pass Alerts Today" :value="$alertCounts['no_pass'] ?? 0" tone="danger" hint="Camera saw a vehicle with no RFID tag" />
        <x-stat label="Anomalies Today" :value="$alertCounts['anomalies'] ?? 0" tone="danger" :href="route('logs.index', ['tab' => 'scans', 'verification_status' => 'anomaly'])" />
        <x-stat label="Guest Captures Today" :value="$guestCountToday" />
    </x-stat-row>

    <x-table title="Flagged RFID Scans" :empty="$flaggedScans->isEmpty()" empty-title="No flagged scans." empty-text="Anomalies and lost or disabled tag scans appear here.">
        <x-slot:toolbar>
            <a href="{{ route('logs.index', ['tab' => 'scans', 'verification_status' => 'anomaly']) }}" class="button button-secondary button-sm">All flagged scans</a>
        </x-slot:toolbar>
        <thead>
            <tr>
                <th>Time</th>
                <th>Tag / Vehicle</th>
                <th>Gate</th>
                <th>Result</th>
                <th>Reason</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($flaggedScans as $scan)
                <tr>
                    <td><x-datetime :value="$scan->scan_time" /></td>
                    <td>
                        <strong>{{ $scan->vehicle?->plate_number ?? $scan->vehicleRfidTag?->label ?? $scan->tag_uid }}</strong>
                        <div class="table-subtext">{{ $scan->tag_uid }}</div>
                    </td>
                    <td>{{ \App\Models\Gate::labelFor($scan->scan_location) }}</td>
                    <td><x-badge status="anomaly" :label="$scan->verificationLabel" /></td>
                    <td>
                        {{ $scan->anomaly_reason ?: '—' }}
                        @if ($scan->isUnknownTag() && auth()->user()?->isAdmin())
                            <div class="table-subtext"><a href="{{ route('registry.index', ['tab' => 'vehicles', 'register_tag' => $scan->tag_uid]) }}">Register this tag</a></div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </x-table>

    <x-drawer id="add-observation-drawer" title="Add Guest Observation" :open="$errors->any()">
            <form method="POST" action="{{ route('guest-observations.store') }}" enctype="multipart/form-data" class="stack-form guest-observation-form">
                @csrf
                <input type="hidden" name="observation_source" value="manual">

                <div class="stack-form">
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
                        <label for="location">Gate</label>
                        <select id="location" name="location" required>
                            @foreach (\App\Models\Gate::options() as $value => $label)
                                <option value="{{ $value }}" @selected(old('location', array_key_first(\App\Models\Gate::options())) === $value)>{{ $label }}</option>
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
                    <button type="button" class="button button-secondary" data-drawer-close>Cancel</button>
                    <button type="submit" class="button button-primary">Save Guest Observation</button>
                </div>
            </form>
    </x-drawer>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>Latest Guest Capture</h3>
                    </div>
                </div>
            </div>

            @if ($latestUnregisteredCapture)
                @php($latestSnapshotUrl = $latestUnregisteredCapture->snapshot_url)
                <div class="result-card result-card-warning">
                    <div class="result-card-head">
                        <strong>Guest captured</strong>
                        <x-badge status="entry" :label="\App\Models\Gate::labelFor($latestUnregisteredCapture->location)" />
                    </div>
                    <img src="{{ $latestSnapshotUrl }}" alt="Guest vehicle capture" class="capture-preview">
                    <div class="detail-list">
                        <div><span>Camera</span><strong>{{ $latestUnregisteredCapture->camera?->camera_name ?: 'No camera linked' }}</strong></div>
                        <div><span>Plate</span><strong>{{ $latestUnregisteredCapture->plate_number ?: $latestUnregisteredCapture->plate_text ?: 'No plate detected' }}</strong></div>
                        <div><span>Color</span><strong>{{ $latestUnregisteredCapture->vehicle_color ?: 'No color detected' }}</strong></div>
                        <div><span>Captured</span><strong><x-datetime :value="$latestUnregisteredCapture->observed_at" /></strong></div>
                    </div>
                    <p>{{ $latestUnregisteredCapture->notes }}</p>
                </div>
            @else
                <x-empty-state title="No guest capture yet" text="Guest detections will appear here with a CCTV snapshot when a latest frame is available." />
            @endif
        </section>

    <x-table title="Guest Observation Logs" :paginator="$observations" class="guest-log-table-wrap">
        <x-slot:toolbar><span class="text-muted" data-guest-total-count>{{ $observations->total() }} total</span></x-slot:toolbar>
        <x-slot:filters>

            <form method="GET" action="{{ route('logs.index') }}" class="form-grid filter-grid guest-filter-grid">
                <input type="hidden" name="tab" value="alerts">
                <div class="field">
                    <label for="filter_plate_text">Plate</label>
                    <input id="filter_plate_text" type="text" name="plate_text" value="{{ $filters['plate_text'] ?? '' }}">
                </div>

                <div class="field">
                    <label for="filter_location">Gate</label>
                    <select id="filter_location" name="location">
                        <option value="">All</option>
                        @foreach (\App\Models\Gate::options() as $value => $label)
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
                        <a href="{{ route('logs.index', ['tab' => 'alerts']) }}" class="button button-secondary">Reset</a>
                    </div>
                </div>
            </form>
        </x-slot:filters>

                <colgroup>
                    <col class="guest-col-time">
                    <col class="guest-col-snapshot">
                    <col class="guest-col-plate">
                    <col class="guest-col-color">
                    <col class="guest-col-vehicle">
                    <col class="guest-col-location">
                    <col class="guest-col-status">
                    <col class="guest-col-camera">
                    <col class="guest-col-notes">
                    <col class="guest-col-action">
                </colgroup>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Snapshot</th>
                        <th>Plate Number</th>
                        <th>Color</th>
                        <th>Vehicle</th>
                        <th>Gate</th>
                        <th>Type</th>
                        <th>Camera</th>
                        <th>Notes</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody data-guest-log-body>
                    @forelse ($observations as $observation)
                        @php($snapshotUrl = $observation->snapshot_url)
                        <tr>
                            <td><x-datetime :value="$observation->observed_at" /></td>
                            <td><img src="{{ $snapshotUrl }}" alt="Guest vehicle snapshot" class="thumb thumb-sm"></td>
                            <td>{{ $observation->plate_number ?: $observation->plate_text ?: 'No plate' }}</td>
                            <td>{{ $observation->vehicle_color ?: 'N/A' }}</td>
                            <td>{{ $observation->vehicle_type ?: 'N/A' }}</td>
                            <td>{{ \App\Models\Gate::labelFor($observation->location) }}</td>
                            <td>
                                <x-badge status="guest" label="Guest" />
                            </td>
                            <td>{{ $observation->camera?->camera_name ?: 'N/A' }}</td>
                            <td>{{ $observation->notes ?: 'No notes' }}</td>
                            <td>
                                <button type="button" class="button button-secondary button-sm" data-guest-view="{{ $observation->id }}">
                                    View Details
                                </button>
                            </td>
                        </tr>
                    @empty
                        {{-- Kept inside the table so live rows can replace it. --}}
                        <tr>
                            <td colspan="10" class="table-empty">No guest observations yet.</td>
                        </tr>
                    @endforelse
                </tbody>
    </x-table>

    <div class="modal-backdrop is-hidden" data-guest-modal>
        <section class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="guest_modal_title">
            <div class="modal-header">
                <div>
                    <span class="panel-kicker">Guest Details</span>
                    <h3 id="guest_modal_title">Guest Observation</h3>
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
                        <div><span>Detected Color</span><strong data-guest-modal-color>No color</strong></div>
                        <div><span>Timestamp</span><strong data-guest-modal-time>No time</strong></div>
                        <div><span>Gate</span><strong data-guest-modal-location>No gate</strong></div>
                        <div><span>Type</span><strong data-guest-modal-status>Guest</strong></div>
                    </div>
                </div>

                <form method="POST" action="" class="stack-form" data-guest-modal-form>
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="verified" data-guest-modal-field="status">

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
                            <label for="modal_location">Gate</label>
                            <select id="modal_location" name="location" data-guest-modal-field="location">
                                @foreach (\App\Models\Gate::options() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="field">
                            <label for="modal_observed_at">Observation Time</label>
                            <input id="modal_observed_at" type="datetime-local" name="observed_at" data-guest-modal-field="observed_at">
                        </div>

                        <div class="field span-full">
                            <label for="modal_notes">Notes</label>
                            <textarea id="modal_notes" name="notes" rows="4" data-guest-modal-field="notes"></textarea>
                        </div>
                    </div>

                    <div class="button-row">
                        <button type="submit" class="button button-primary" data-guest-modal-submit>Save Guest Details</button>
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
        'location_label' => \App\Models\Gate::labelFor($observation->location),
        'observed_at' => $observation->observed_at?->format('Y-m-d\TH:i'),
        'display_time' => \App\Support\DisplayTime::datetime($observation->observed_at),
        'status' => $observation->status,
        'status_label' => 'Guest',
        'status_badge_class' => 'badge-secondary',
        'notes' => $observation->notes,
        'snapshot_url' => $observation->snapshot_url,
        'update_url' => route('guest-observations.update', $observation),
    ])->values())
    <script id="guest-observations-data" type="application/json">{!! json_encode($guestObservationPayload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
    <script id="guest-observations-realtime" type="application/json">{!! json_encode([
        'recentLogsUrl' => route('api.recent-guest-logs', ['limit' => 10]),
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>

@push('scripts')
    <script>
        (() => {
            const payloadNode = document.getElementById('guest-observations-data');
            const realtimeNode = document.getElementById('guest-observations-realtime');
            const modal = document.querySelector('[data-guest-modal]');

            if (!payloadNode || !modal) {
                return;
            }

            const observations = new Map(JSON.parse(payloadNode.textContent).map((item) => [String(item.id), item]));
            const realtimeConfig = realtimeNode ? JSON.parse(realtimeNode.textContent || '{}') : {};
            const logBody = document.querySelector('[data-guest-log-body]');
            const totalCount = document.querySelector('[data-guest-total-count]');
            const form = modal.querySelector('[data-guest-modal-form]');
            const image = modal.querySelector('[data-guest-modal-image]');
            const plate = modal.querySelector('[data-guest-modal-plate]');
            const color = modal.querySelector('[data-guest-modal-color]');
            const time = modal.querySelector('[data-guest-modal-time]');
            const location = modal.querySelector('[data-guest-modal-location]');
            const status = modal.querySelector('[data-guest-modal-status]');
            const submitButton = modal.querySelector('[data-guest-modal-submit]');
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
                color.textContent = observation.vehicle_color || 'No color detected';
                time.textContent = observation.display_time || 'No time';
                location.textContent = observation.location_label || observation.location || 'No gate';
                status.textContent = observation.status_label || 'Guest';
                submitButton.textContent = 'Save Guest Details';

                setField('plate_number', observation.plate_number);
                setField('vehicle_type', observation.vehicle_type);
                setField('vehicle_color', observation.vehicle_color);
                setField('location', observation.location);
                setField('observed_at', observation.observed_at);
                setField('status', 'verified');
                setField('notes', observation.notes);

                modal.classList.remove('is-hidden');
            }

            function appendText(parent, tagName, text, className = null) {
                const node = document.createElement(tagName);

                if (className) {
                    node.className = className;
                }

                node.textContent = text || '';
                parent.appendChild(node);

                return node;
            }

            function buildGuestRow(observation) {
                const row = document.createElement('tr');
                const timeCell = appendText(row, 'td', observation.display_time || 'No time');
                const snapshotCell = document.createElement('td');
                const imageNode = document.createElement('img');
                const statusCell = document.createElement('td');
                const statusBadge = document.createElement('span');
                const actionCell = document.createElement('td');
                const actionButton = document.createElement('button');

                imageNode.src = observation.snapshot_url;
                imageNode.alt = 'Guest vehicle snapshot';
                imageNode.className = 'thumb thumb-sm';
                snapshotCell.appendChild(imageNode);
                row.appendChild(snapshotCell);

                appendText(row, 'td', observation.plate_number || 'No plate');
                appendText(row, 'td', observation.vehicle_color || 'N/A');
                appendText(row, 'td', observation.vehicle_type || 'N/A');
                appendText(row, 'td', observation.location_label || observation.location || 'N/A');

                statusBadge.className = `badge ${observation.status_badge_class || 'badge-secondary'}`;
                statusBadge.textContent = observation.status_label || 'Guest';
                statusCell.appendChild(statusBadge);
                row.appendChild(statusCell);

                appendText(row, 'td', observation.camera_name || 'N/A');
                appendText(row, 'td', observation.notes || 'No notes');

                actionButton.type = 'button';
                actionButton.className = 'button button-secondary button-sm';
                actionButton.dataset.guestView = observation.id;
                actionButton.textContent = 'View Details';
                actionCell.appendChild(actionButton);
                row.appendChild(actionCell);

                timeCell.dataset.guestObservedAt = observation.observed_at || '';

                return row;
            }

            function renderGuestLogs(items, total) {
                if (!logBody || !Array.isArray(items)) {
                    return;
                }

                observations.clear();
                items.forEach((item) => {
                    observations.set(String(item.id), item);
                });

                if (totalCount && Number.isFinite(Number(total))) {
                    totalCount.textContent = `${total} total`;
                }

                if (items.length === 0) {
                    const emptyRow = document.createElement('tr');
                    const emptyCell = document.createElement('td');

                    emptyCell.colSpan = 10;
                    emptyCell.className = 'table-empty';
                    emptyCell.textContent = 'No guest observations yet.';
                    emptyRow.appendChild(emptyCell);
                    logBody.replaceChildren(emptyRow);

                    return;
                }

                logBody.replaceChildren(...items.map(buildGuestRow));
            }

            document.addEventListener('click', (event) => {
                const button = event.target.closest('[data-guest-view]');

                if (!button) {
                    return;
                }

                const observation = observations.get(String(button.dataset.guestView));

                if (observation) {
                    openModal(observation);
                }
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

            let guestPollInFlight = false;

            async function pollGuestLogs() {
                if (!realtimeConfig.recentLogsUrl || guestPollInFlight) {
                    return;
                }

                guestPollInFlight = true;

                try {
                    const response = await fetch(realtimeConfig.recentLogsUrl, {
                        headers: {
                            Accept: 'application/json',
                        },
                    });

                    if (!response.ok) {
                        throw new Error('Guest logs unavailable.');
                    }

                    const body = await response.json();
                    renderGuestLogs(body.logs || [], body.total);
                } catch (error) {
                    // Keep the current table visible when a poll fails.
                } finally {
                    guestPollInFlight = false;
                }
            }

            window.setInterval(pollGuestLogs, 2000);
        })();
    </script>
@endpush
