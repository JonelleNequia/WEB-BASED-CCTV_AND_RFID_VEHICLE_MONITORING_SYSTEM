{{-- UI Phase 2: Activity Logs › RFID Scans (the old RFID Desk history). --}}
    <x-table title="RFID Scans" :paginator="$scanLogs" :empty="$scanLogs->isEmpty()" empty-title="No tag reads yet" empty-text="Tags read at the gates appear here.">
        <x-slot:filters>
        <form method="GET" action="{{ route('logs.index') }}" class="form-grid filter-grid">
            <input type="hidden" name="tab" value="scans">
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
                <label for="history_scan_location">Gate</label>
                <select id="history_scan_location" name="scan_location">
                    <option value="">All</option>
                    @foreach (\App\Models\Gate::options() as $gateCode => $gateName)
                        <option value="{{ $gateCode }}" @selected(($filters['scan_location'] ?? '') === $gateCode)>{{ $gateName }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="verification_status">Result</label>
                <select id="verification_status" name="verification_status">
                    <option value="">All</option>
                    @foreach (['anomaly' => 'Needs attention (flagged)', 'verified' => 'Registered', 'pending' => 'Waiting for the camera', 'scan_only' => 'Scan only (no crossing)', 'unknown_tag' => 'Unknown tag', 'inactive_tag' => 'Inactive Tag', 'unassigned_tag' => 'Unassigned Tag', 'inactive_vehicle' => 'Inactive Vehicle', 'non_recurring_category' => 'Manual Review'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['verification_status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field field-actions">
                <div class="button-row">
                    <button type="submit" class="button button-secondary">Filter History</button>
                    <a href="{{ route('logs.index', ['tab' => 'scans']) }}" class="button button-secondary">Reset</a>
                </div>
            </div>
        </form>
        </x-slot:filters>

                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Tag UID</th>
                        <th>Vehicle</th>
                        <th>Gate</th>
                        <th>Event</th>
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
                                @if ($scan->isUnknownTag())
                                    {{-- Phase 3: unknown tags are never visitor records. --}}
                                    <strong>Unknown tag</strong>
                                    @if (auth()->user()?->isAdmin())
                                        <div class="table-subtext"><a href="{{ route('registry.index', ['tab' => 'vehicles', 'register_tag' => $scan->tag_uid]) }}">Register this tag</a></div>
                                    @endif
                                @else
                                    <strong>{{ $scan->vehicle?->plate_number ?? ($scan->vehicleRfidTag?->label ?? $scan->tag_uid) }}</strong>
                                    <div class="table-subtext">{{ $scan->vehicle ? $scan->vehicle->vehicle_type.' · '.\App\Support\VehicleCategory::label($scan->vehicle->category) : 'No vehicle linked' }}</div>
                                @endif
                            </td>
                            <td>
                                <strong>{{ $scan->scanLocationLabel }}</strong>
                                <div class="table-subtext">{{ $scan->scanDirectionLabel }}</div>
                            </td>
                            <td>
                                {{ $scan->resolvedEventTypeLabel }}
                                <div class="table-subtext">{{ $scan->resultingStateLabel }}</div>
                                @if ($scan->fusionLabel)
                                    <div class="table-subtext" title="{{ $scan->fusion_note }}">{{ $scan->fusionLabel }}</div>
                                @endif
                            </td>
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
