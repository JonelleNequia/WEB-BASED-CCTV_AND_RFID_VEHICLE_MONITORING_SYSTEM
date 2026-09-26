@extends('layouts.app')

@section('title', 'RFID Desk | PHILCST Vehicle Access Monitoring')
@section('page-title', 'RFID Desk')

@section('content')
    @php($simulationEnabled = ($settings['rfid_simulation_mode'] ?? 'enabled') === 'enabled')
    @php($registeredTagOptions = $registeredTags->map(function ($tag) {
        $owner = $tag->vehicle?->vehicle_owner_name ?: $tag->vehicle?->owner_name ?: 'No owner linked';
        $plate = $tag->vehicle?->plate_number ?: 'No plate';
        $category = $tag->vehicle?->category
            ? str($tag->vehicle->category)->replace('_', ' ')->title()->value()
            : 'No category';
        $uidLabel = $tag->uid.' - '.$owner;
        $plateLabel = $plate.' - '.$category;

        return [
            'id' => $tag->id,
            'uid' => $tag->uid,
            'owner' => $owner,
            'plate' => $plate,
            'category' => $category,
            'uid_label' => $uidLabel,
            'plate_label' => $plateLabel,
            'label' => $uidLabel,
            'description' => $plateLabel,
            'uid_search' => strtolower($tag->uid.' '.$owner),
            'plate_search' => strtolower($plate.' '.$category),
            'search' => strtolower($tag->uid.' '.$owner.' '.$plate.' '.$category.' '.($tag->vehicle?->vehicle_type ?: '')),
        ];
    })->values()->concat($guestPasses->map(fn ($pass) => [
        // Phase 4: guest passes in the simulation picker.
        'id' => $pass->id,
        'uid' => $pass->uid,
        'owner' => $pass->label,
        'plate' => ucfirst($pass->status),
        'category' => 'Guest Pass',
        'uid_label' => $pass->uid.' - '.$pass->label,
        'plate_label' => 'Guest Pass - '.ucfirst($pass->status),
        'label' => $pass->uid.' - '.$pass->label,
        'description' => 'Guest Pass - '.ucfirst($pass->status),
        'uid_search' => strtolower($pass->uid.' '.$pass->display_number),
        'plate_search' => strtolower('guest pass '.$pass->display_number),
        'search' => strtolower($pass->uid.' guest pass '.$pass->display_number.' '.$pass->status),
    ]))->values())
    @php($selectedRegisteredTagId = (string) old('vehicle_rfid_tag_id', ''))
    @php($selectedRegisteredTag = $registeredTagOptions->first(fn ($option) => (string) $option['id'] === $selectedRegisteredTagId))

    <x-page-header title="RFID Desk">
        <x-slot:meta>{{ $simulationEnabled ? 'Simulation mode' : 'Simulation off' }}</x-slot:meta>
    </x-page-header>

    <x-stat-row>
        <x-stat label="Registered Vehicles" :value="$rfidStats['registered_vehicles'] ?? 0" />
        {{-- Phase 1: shared inside count (VehicleOccupancyService) --}}
        <x-stat label="Inside Campus" :value="$rfidStats['vehicles_inside'] ?? 0"
                :hint="($rfidStats['registered_inside'] ?? 0).' registered · '.($rfidStats['guests_inside'] ?? 0).' guests'" />
        <x-stat label="Registered Scans Today" :value="$rfidStats['registered_scans_today'] ?? 0" tone="success" />
        <x-stat label="Needs Attention" :value="$rfidStats['attention_today'] ?? 0" tone="danger"
                :href="($rfidStats['attention_today'] ?? 0) > 0 ? route('rfid-scans.index', ['verification_status' => 'anomaly']) : null"
                hint="Anomalies and lost-pass alerts today" />
    </x-stat-row>

    {{-- Phase 4: today's anomalies and lost/disabled pass alerts. --}}
    @if ($attentionItems->isNotEmpty())
        <section class="panel attention-list">
            @foreach ($attentionItems as $item)
                <p>
                    <x-badge status="anomaly" label="Flagged" />
                    <x-datetime :value="$item->scan_time" format="time" /> · {{ ucfirst($item->scan_location) }} · {{ $item->anomaly_reason }}
                </p>
            @endforeach
        </section>
    @endif

    <div class="page-grid two-column">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>Scan RFID</h3>
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('rfid-scans.store') }}" class="stack-form" data-rfid-scan-form>
                @csrf

                <div class="form-grid">
                    <div class="field span-full">
                        <label for="vehicle_rfid_tag_id">Registered Tag or Guest Pass</label>
                        <input id="vehicle_rfid_tag_id" type="hidden" name="vehicle_rfid_tag_id" value="{{ $selectedRegisteredTagId }}" data-rfid-combobox-value>
                        <div class="combobox" data-rfid-combobox>
                            <input
                                id="registered_tag_search"
                                type="search"
                                value="{{ $selectedRegisteredTag['label'] ?? '' }}"
                                autocomplete="off"
                                placeholder="Type owner, plate, RFID UID, or G-01"
                                data-rfid-combobox-input
                            >
                            <button type="button" class="combobox-clear" data-rfid-combobox-clear aria-label="Clear selected RFID tag">Clear</button>
                            <div class="combobox-menu" data-rfid-combobox-list hidden></div>
                        </div>
                    </div>

                    <div class="field span-full">
                        <label for="tag_uid">Or Enter Tag UID</label>
                        <input id="tag_uid" type="text" name="tag_uid" value="{{ old('tag_uid') }}" autocomplete="off" placeholder="Scan RFID card" autofocus data-rfid-scan-input>
                    </div>

                    <div class="field">
                        <label for="scan_location">Station</label>
                        <select id="scan_location" name="scan_location" required>
                            <option value="entrance" @selected(old('scan_location', 'entrance') === 'entrance')>Entrance</option>
                            <option value="exit" @selected(old('scan_location') === 'exit')>Exit</option>
                        </select>
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
                    {{-- Phase 4: replaced the old "same station can become ENTRY or EXIT" note. --}}
                    <strong>Station readers decide the direction.</strong>
                    <p>
                        At the stations, Entrance always records ENTRY and Exit always records EXIT; a mismatch is flagged for review.
                        This desk keeps the inside/outside toggle for registered vehicles. Guest passes follow the station rules:
                        an available pass at Entrance must be issued (Entrance Station or Guest Passes page), and an issued pass at Exit closes the visit.
                        The same tag at the same station is ignored for {{ $settings['rfid_cooldown_seconds'] ?? 60 }} seconds.
                    </p>
                </div>

                <div class="button-row">
                    <button type="submit" class="button button-primary {{ $simulationEnabled ? '' : 'button-disabled' }}" @disabled(! $simulationEnabled)>Simulate RFID Scan</button>
                </div>
            </form>

            <div class="mini-note" data-rfid-scan-result hidden></div>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>Latest Result</h3>
                    </div>
                </div>
            </div>

            @if ($latestScan)
                <div class="result-card result-card-{{ $latestScan->verification_status === 'verified' ? 'success' : 'warning' }}">
                    <div class="result-card-head">
                        <strong>{{ $latestScan->verificationLabel }}</strong>
                        <x-badge :status="$latestScan->scan_location === 'exit' ? 'exit' : 'entry'" :label="$latestScan->scanLocationLabel" />
                    </div>
                    <div class="detail-list">
                        <div><span>Tag UID</span><strong>{{ $latestScan->tag_uid }}</strong></div>
                        <div><span>Vehicle</span><strong>{{ $latestScan->vehicle?->plate_number ?? 'GUEST' }}</strong></div>
                        <div><span>Category</span><strong>{{ $latestScan->vehicle?->category ? ucfirst(str_replace('_', ' ', $latestScan->vehicle->category)) : 'N/A' }}</strong></div>
                        <div><span>Event Type</span><strong>{{ $latestScan->resolvedEventTypeLabel }}</strong></div>
                        <div><span>Current State</span><strong>{{ $latestScan->resultingStateLabel }}</strong></div>
                        <div><span>Time</span><strong><x-datetime :value="$latestScan->scan_time" /></strong></div>
                    </div>

                    <div class="mini-note">
                        <strong>
                            {{ $latestScan->correlatedVehicleEvent
                                ? 'Vehicle log #'.$latestScan->correlatedVehicleEvent->id.' linked automatically.'
                                : 'No vehicle log linked yet.' }}
                        </strong>
                        <p>{{ $latestScan->vehicle?->vehicle_type ?: 'Check registry details or guest observation for this scan.' }}</p>
                        @if ($latestScan->guestVehicleObservation)
                            <p>Guest observation #{{ $latestScan->guestVehicleObservation->id }} was recorded.</p>
                        @endif
                    </div>
                </div>
            @else
                <x-empty-state title="No scan result yet" text="Record the first RFID scan to see the latest result here." />
            @endif
        </section>
    </div>

    <x-table title="RFID Scan History" :paginator="$scanLogs" :empty="$scanLogs->isEmpty()" empty-title="No RFID scan history yet.">
        <x-slot:filters>
        <form method="GET" action="{{ route('rfid-scans.index') }}" class="form-grid filter-grid">
            <div class="field span-2">
                <label for="history_q">Search RFID Scan History</label>
                <div class="search-input-shell">
                    <span class="search-input-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M10.5 4a6.5 6.5 0 0 1 5.1 10.5l4 4a1 1 0 0 1-1.4 1.4l-4-4A6.5 6.5 0 1 1 10.5 4m0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9"/></svg>
                    </span>
                    <input id="history_q" type="search" name="history_q" value="{{ $filters['history_q'] ?? '' }}" placeholder="Owner name, plate number, or RFID UID">
                </div>
            </div>

            <div class="field">
                <label for="history_scan_location">Station</label>
                <select id="history_scan_location" name="scan_location">
                    <option value="">All</option>
                    <option value="entrance" @selected(($filters['scan_location'] ?? '') === 'entrance')>Entrance</option>
                    <option value="exit" @selected(($filters['scan_location'] ?? '') === 'exit')>Exit</option>
                </select>
            </div>

            <div class="field">
                <label for="verification_status">Result</label>
                <select id="verification_status" name="verification_status">
                    <option value="">All</option>
                    @foreach (['anomaly' => 'Needs attention (flagged)', 'verified' => 'Registered', 'guest_pass_entry' => 'Guest Pass Entry', 'guest_pass_exit' => 'Guest Pass Exit', 'guest_pass_available' => 'Guest Pass (to issue)', 'guest_pass_not_issued' => 'Guest Pass not issued', 'guest_pass_lost' => 'Lost Guest Pass', 'guest' => 'Guest', 'inactive_tag' => 'Inactive Tag', 'unassigned_tag' => 'Unassigned Tag', 'inactive_vehicle' => 'Inactive Vehicle', 'non_recurring_category' => 'Manual Review'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['verification_status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field field-actions">
                <div class="button-row">
                    <button type="submit" class="button button-secondary">Filter History</button>
                    <a href="{{ route('rfid-scans.index') }}" class="button button-secondary">Reset History</a>
                </div>
            </div>
        </form>
        </x-slot:filters>

                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Tag UID</th>
                        <th>Vehicle</th>
                        <th>Category</th>
                        <th>Station</th>
                        <th>Event Type</th>
                        <th>Current State</th>
                        <th>Result</th>
                        <th>Vehicle Log</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($scanLogs as $scan)
                        <tr>
                            <td><x-datetime :value="$scan->scan_time" /></td>
                            <td><strong>{{ $scan->tag_uid }}</strong></td>
                            <td>
                                <strong>{{ $scan->vehicle?->plate_number ?? 'GUEST' }}</strong>
                                <div class="table-subtext">{{ $scan->vehicle?->vehicle_type ?: 'Guest record' }}</div>
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
                            <td>
                                <span class="badge badge-{{ $scan->verificationBadgeClass }}">{{ $scan->verificationLabel }}</span>
                            </td>
                            <td>
                                @if ($scan->correlatedVehicleEvent)
                                    <strong>#{{ $scan->correlatedVehicleEvent->id }}</strong>
                                    <div class="table-subtext">{{ $scan->correlatedVehicleEvent->event_type }} • {{ $scan->correlatedVehicleEvent->plate_text ?: $scan->vehicle?->plate_number ?: 'GUEST' }}</div>
                                @else
                                    <span class="table-subtext">No linked vehicle log</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
    </x-table>

    <script id="registered-rfid-tag-options" type="application/json">{!! json_encode($registeredTagOptions, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.querySelector('[data-rfid-scan-form]');
            const resultBox = document.querySelector('[data-rfid-scan-result]');
            const registeredTagValue = document.querySelector('[data-rfid-combobox-value]');
            const combobox = document.querySelector('[data-rfid-combobox]');
            const comboboxInput = document.querySelector('[data-rfid-combobox-input]');
            const comboboxList = document.querySelector('[data-rfid-combobox-list]');
            const comboboxClear = document.querySelector('[data-rfid-combobox-clear]');
            const manualTagInput = document.getElementById('tag_uid');
            const scanTimeInput = document.getElementById('scan_time');
            const optionNode = document.getElementById('registered-rfid-tag-options');
            const registeredTagOptions = optionNode ? JSON.parse(optionNode.textContent || '[]') : [];

            if (!form || !resultBox) {
                return;
            }

            const currentDateTimeLocal = () => {
                const now = new Date();
                now.setMinutes(now.getMinutes() - now.getTimezoneOffset());

                return now.toISOString().slice(0, 16);
            };

            const focusScannerInput = () => {
                if (!manualTagInput || registeredTagValue?.value) {
                    return;
                }

                const activeElement = document.activeElement;
                const activeTag = activeElement?.tagName;

                if (
                    activeElement !== manualTagInput
                    && (['INPUT', 'SELECT', 'TEXTAREA', 'BUTTON'].includes(activeTag) || activeElement?.type === 'datetime-local')
                ) {
                    return;
                }

                manualTagInput.focus({ preventScroll: true });
            };

            window.setTimeout(focusScannerInput, 100);
            window.addEventListener('focus', focusScannerInput);

            const normalizedTerm = (term = '') => term.trim().toLowerCase();

            const optionDisplayLabel = (option, term = '') => {
                const searchTerm = normalizedTerm(term);

                if (searchTerm && option.plate_search?.includes(searchTerm) && !option.uid_search?.includes(searchTerm)) {
                    return option.plate_label || option.label;
                }

                return option.uid_label || option.label;
            };

            const optionMetaLabel = (option, term = '') => {
                const label = optionDisplayLabel(option, term);

                return label === option.plate_label
                    ? option.uid_label || option.label
                    : option.plate_label || option.description;
            };

            const renderComboboxOptions = (term = '') => {
                if (!comboboxList) {
                    return;
                }

                const searchTerm = normalizedTerm(term);
                const results = registeredTagOptions
                    .filter((option) => !searchTerm || option.search.includes(searchTerm))
                    .slice(0, 12);

                comboboxList.innerHTML = '';

                if (results.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'combobox-empty';
                    empty.textContent = 'No registered tag matched.';
                    comboboxList.appendChild(empty);
                    comboboxList.hidden = false;
                    return;
                }

                results.forEach((option) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'combobox-option';
                    button.dataset.optionId = option.id;
                    const displayLabel = optionDisplayLabel(option, term);
                    const label = document.createElement('strong');
                    const meta = document.createElement('span');
                    label.textContent = displayLabel;
                    meta.textContent = optionMetaLabel(option, term);
                    button.append(label, meta);
                    button.addEventListener('click', () => selectComboboxOption(option, displayLabel));
                    comboboxList.appendChild(button);
                });

                comboboxList.hidden = false;
            };

            const closeCombobox = () => {
                if (comboboxList) {
                    comboboxList.hidden = true;
                }
            };

            const selectComboboxOption = (option, displayLabel = null) => {
                if (!registeredTagValue || !comboboxInput) {
                    return;
                }

                registeredTagValue.value = option.id;
                comboboxInput.value = displayLabel || option.uid_label || option.label;
                if (manualTagInput) {
                    manualTagInput.value = '';
                }
                closeCombobox();
            };

            if (comboboxInput && registeredTagValue) {
                comboboxInput.addEventListener('focus', () => renderComboboxOptions(comboboxInput.value));
                comboboxInput.addEventListener('input', () => {
                    registeredTagValue.value = '';
                    renderComboboxOptions(comboboxInput.value);
                });

                comboboxInput.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape') {
                        closeCombobox();
                        return;
                    }

                    if (event.key !== 'Enter') {
                        return;
                    }

                    const firstOption = comboboxList?.querySelector('[data-option-id]');
                    if (!firstOption) {
                        return;
                    }

                    event.preventDefault();
                    const option = registeredTagOptions.find((item) => String(item.id) === String(firstOption.dataset.optionId));
                    if (option) {
                        selectComboboxOption(option, firstOption.querySelector('strong')?.textContent || null);
                    }
                });

                comboboxClear?.addEventListener('click', () => {
                    registeredTagValue.value = '';
                    comboboxInput.value = '';
                    renderComboboxOptions('');
                    manualTagInput?.focus();
                });

                document.addEventListener('click', (event) => {
                    if (!combobox?.contains(event.target)) {
                        closeCombobox();
                    }
                });
            }

            if (registeredTagValue && manualTagInput) {
                manualTagInput.addEventListener('input', () => {
                    if (manualTagInput.value.trim() !== '') {
                        registeredTagValue.value = '';
                        if (comboboxInput) {
                            comboboxInput.value = '';
                        }
                    }
                });

                manualTagInput.addEventListener('keydown', (event) => {
                    if (event.key !== 'Enter' || manualTagInput.value.trim() === '') {
                        return;
                    }

                    event.preventDefault();
                    registeredTagValue.value = '';
                    if (comboboxInput) {
                        comboboxInput.value = '';
                    }
                    form.requestSubmit();
                });
            }

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                resultBox.hidden = false;
                resultBox.textContent = 'Recording RFID scan...';

                try {
                    if (!registeredTagValue?.value && manualTagInput?.value.trim() === '') {
                        resultBox.textContent = 'Scan or select an RFID tag first.';
                        focusScannerInput();
                        return;
                    }

                    if (scanTimeInput) {
                        scanTimeInput.value = currentDateTimeLocal();
                    }

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
                        focusScannerInput();
                        return;
                    }

                    const scan = payload.scan || {};
                    resultBox.textContent = `${payload.message} ${scan.guest_observation_id ? 'Guest observation #' + scan.guest_observation_id + ' was created.' : ''} Refreshing latest result...`;
                    window.setTimeout(() => {
                        window.location.href = '{{ route('rfid-scans.index') }}';
                    }, 500);
                } catch (error) {
                    resultBox.textContent = 'RFID scan could not reach the server.';
                    focusScannerInput();
                }
            });
        });
    </script>
@endpush
