{{-- UI Phase 2: Guests page (was Guest Passes). Visits here; the pass cards are in Registry › Guest Passes. --}}
@extends('layouts.app')

@section('title', 'Guests | PHILCST Vehicle Monitoring')
@section('page-title', 'Guests')

@php
    $statusLabel = fn (string $status): string => match ($status) {
        'lost_tag' => 'Lost pass',
        default => ucfirst($status),
    };
    $duration = function ($visit): string {
        if (! $visit->entry_at) {
            return 'N/A';
        }

        $minutes = (int) $visit->entry_at->diffInMinutes($visit->exit_at ?? now());

        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    };
@endphp

@section('content')
    <x-page-header title="Guests">
        <x-slot:meta>Default validity {{ intdiv($validityMinutes, 60) }}h {{ $validityMinutes % 60 }}m</x-slot:meta>
        <x-slot:actions>
            <button type="button" class="button button-secondary" data-drawer-open="manual-guest-drawer">Manual guest entry</button>
            <button type="button" class="button button-primary" data-drawer-open="issue-pass-drawer">Issue Guest Pass</button>
        </x-slot:actions>
    </x-page-header>

    <x-stat-row>
        <x-stat label="Active Guests" :value="$stats['active_guests']" tone="brand" hint="Inside with a pass (incl. overstay)" data-guest-pass-stat="active_guests" />
        <x-stat label="Passes Available" :value="$stats['passes_available'].' / '.$stats['passes_total']" :hint="$stats['passes_lost'].' lost pass(es)'" :href="route('registry.index', ['tab' => 'passes'])" />
        <x-stat label="Overstay" :value="$stats['overstay']" tone="warning" hint="Past their valid-until time" :href="route('guests.index', ['status' => 'overstay'])" />
    </x-stat-row>

    <x-drawer id="issue-pass-drawer" title="Issue Guest Pass" :open="$errors->hasAny(['id_presented', 'rfid_tag_id', 'plate'])">
        <p class="field-help">Normally the Entrance Station opens this form when an available pass is tapped. Use it here when the reader is not available.</p>
        @if ($availablePasses->isEmpty())
            <x-empty-state title="No available guest passes" text="Register passes in RFID Tags, or use Manual guest entry." />
        @else
            <form method="POST" data-issue-form action="{{ route('guest-passes.issue', $availablePasses->first()) }}" class="stack-form">
                @csrf
                <div class="stack-form">
                    <div class="field">
                        <label for="issue_pass">Guest Pass</label>
                        <select id="issue_pass" data-issue-pass-select required>
                            @foreach ($availablePasses as $pass)
                                <option value="{{ route('guest-passes.issue', $pass) }}">{{ $pass->display_number }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="issue_plate">Plate Number</label>
                        <input id="issue_plate" type="text" name="plate" value="{{ old('plate') }}" placeholder="ABC 1234">
                    </div>
                    <div class="field">
                        <label for="issue_driver">Driver Name</label>
                        <input id="issue_driver" type="text" name="driver_name" value="{{ old('driver_name') }}">
                    </div>
                    <div class="field">
                        <label for="issue_vehicle_type">Vehicle Type</label>
                        <input id="issue_vehicle_type" type="text" name="vehicle_type" value="{{ old('vehicle_type') }}" placeholder="Car, Van, Motorcycle">
                    </div>
                    <div class="field">
                        <label for="issue_color">Color</label>
                        <input id="issue_color" type="text" name="color" value="{{ old('color') }}">
                    </div>
                    <div class="field">
                        <label for="issue_purpose">Purpose</label>
                        <input id="issue_purpose" type="text" name="purpose" value="{{ old('purpose') }}" placeholder="Delivery, visit, enrollment">
                    </div>
                    <div class="field">
                        <label for="issue_destination">Destination</label>
                        <input id="issue_destination" type="text" name="destination" value="{{ old('destination') }}" placeholder="Registrar, Admin Office">
                    </div>
                    <div class="field">
                        <label for="issue_id">ID Presented {{ $requiresId ? '*' : '' }}</label>
                        <input id="issue_id" type="text" name="id_presented" value="{{ old('id_presented') }}" placeholder="Driver's License" @required($requiresId)>
                        @error('id_presented')<span class="field-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="field">
                        <label for="issue_valid">Valid For</label>
                        <select id="issue_valid" name="valid_minutes">
                            @foreach ([60 => '1 hour', 120 => '2 hours', 240 => '4 hours', 480 => '8 hours', 720 => '12 hours'] as $minutes => $label)
                                <option value="{{ $minutes }}" @selected($minutes === $validityMinutes)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                @error('rfid_tag_id')<span class="field-error">{{ $message }}</span>@enderror
                <div class="button-row">
                    <button type="button" class="button button-secondary" data-drawer-close>Cancel</button>
                    <button type="submit" class="button button-primary">Issue Pass</button>
                </div>
            </form>
        @endif
    </x-drawer>

    <x-table title="Guest Visits" :paginator="$visits" :empty="$visits->isEmpty()" empty-title="No guest visits for this filter.">
        <x-slot:toolbar><span class="text-muted">{{ $visits->total() }} visit(s)</span></x-slot:toolbar>
        <x-slot:filters>
        <form method="GET" action="{{ route('guests.index') }}" class="form-grid filter-grid">
            <div class="field">
                <label for="filter_status">Status</label>
                <select id="filter_status" name="status">
                    @foreach (['open' => 'Inside (active + overstay)', 'all' => 'All', 'active' => 'Active', 'overstay' => 'Overstay', 'completed' => 'Completed', 'lost_tag' => 'Lost pass'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['status'] ?? 'open') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="filter_q">Pass / Plate / Driver</label>
                <input id="filter_q" type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="G-03 or ABC 1234">
            </div>
            <div class="field">
                <label for="filter_date">Entry Date</label>
                <input id="filter_date" type="date" name="date" value="{{ $filters['date'] ?? '' }}">
            </div>
            <div class="field field-actions">
                <div class="button-row">
                    <button type="submit" class="button button-secondary">Apply</button>
                    <a href="{{ route('guests.index') }}" class="button button-secondary">Reset</a>
                </div>
            </div>
        </form>
        </x-slot:filters>

                <thead>
                    <tr>
                        <th>Pass</th>
                        <th>Plate</th>
                        <th>Driver</th>
                        <th>Purpose</th>
                        <th>Entry</th>
                        <th>Exit</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($visits as $visit)
                        <tr>
                            <td><strong>{{ $visit->rfidTag?->display_number ?? 'N/A' }}</strong></td>
                            <td>{{ $visit->plate ?: 'No plate' }}</td>
                            <td>{{ $visit->driver_name ?: 'N/A' }}</td>
                            <td>
                                {{ $visit->purpose ?: 'N/A' }}
                                @if ($visit->destination)
                                    <div class="table-subtext">{{ $visit->destination }}</div>
                                @endif
                            </td>
                            <td><x-datetime :value="$visit->entry_at" /></td>
                            <td>
                                <x-datetime :value="$visit->exit_at" fallback="Inside" />
                                @if ($visit->isOpen() && $visit->valid_until)
                                    <div class="table-subtext">Valid until <x-datetime :value="$visit->valid_until" format="time" /></div>
                                @endif
                            </td>
                            <td>{{ $duration($visit) }}</td>
                            <td><x-badge :status="$visit->status === 'lost_tag' ? 'lost' : $visit->status" :label="$statusLabel($visit->status)" /></td>
                            <td>
                                <div class="button-row guest-pass-actions">
                                    <a href="{{ route('guest-passes.visits.show', $visit) }}" class="button button-secondary button-sm">View</a>
                                    @if ($visit->isOpen())
                                        <details class="guest-pass-action">
                                            <summary class="button button-secondary button-sm">Close</summary>
                                            <form method="POST" action="{{ route('guest-passes.visits.close', $visit) }}" class="stack-form">
                                                @csrf
                                                <input type="text" name="reason" placeholder="Reason (required)" required maxlength="255">
                                                <button type="submit" class="button button-primary button-sm">Close visit</button>
                                            </form>
                                        </details>
                                        <details class="guest-pass-action">
                                            <summary class="button button-subtle-danger button-sm">Lost</summary>
                                            <form method="POST" action="{{ route('guest-passes.visits.lost', $visit) }}" class="stack-form">
                                                @csrf
                                                <input type="text" name="reason" placeholder="Notes (optional)" maxlength="255">
                                                <button type="submit" class="button button-subtle-danger button-sm">Mark pass lost</button>
                                            </form>
                                        </details>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
    </x-table>

    <x-drawer id="manual-guest-drawer" title="Manual guest entry (fallback)">
            <p class="field-help">
                For when passes run out. Records a guest observation without a pass; it does not count as a guest inside.
            </p>

            <form method="POST" action="{{ route('guest-observations.store') }}" enctype="multipart/form-data" class="stack-form">
                @csrf
                <input type="hidden" name="observation_source" value="manual">
                <div class="stack-form">
                    <div class="field">
                        <label for="manual_plate">Plate Number</label>
                        <input id="manual_plate" type="text" name="plate_number" placeholder="Optional">
                    </div>
                    <div class="field">
                        <label for="manual_type">Vehicle Type</label>
                        <input id="manual_type" type="text" name="vehicle_type" placeholder="Car, Van, Motorcycle" required>
                    </div>
                    <div class="field">
                        <label for="manual_color">Vehicle Color</label>
                        <input id="manual_color" type="text" name="vehicle_color" placeholder="Optional">
                    </div>
                    <div class="field">
                        <label for="manual_location">Location</label>
                        <select id="manual_location" name="location" required>
                            <option value="entrance">Entrance</option>
                            <option value="exit">Exit</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="manual_camera">Camera</label>
                        <select id="manual_camera" name="camera_id">
                            <option value="">No camera selected</option>
                            @foreach ($cameras as $camera)
                                <option value="{{ $camera->id }}">{{ $camera->camera_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="manual_observed_at">Time</label>
                        <input id="manual_observed_at" type="datetime-local" name="observed_at" value="{{ now()->format('Y-m-d\TH:i') }}" required>
                    </div>
                    <div class="field">
                        <label for="manual_snapshot">Snapshot</label>
                        <input id="manual_snapshot" type="file" name="snapshot" accept="image/*">
                    </div>
                    <div class="field span-full">
                        <label for="manual_notes">Notes</label>
                        <textarea id="manual_notes" name="notes" rows="2" placeholder="Why no pass was issued"></textarea>
                    </div>
                </div>
                <div class="button-row">
                    <button type="button" class="button button-secondary" data-drawer-close>Cancel</button>
                    <button type="submit" class="button button-primary">Save Manual Entry</button>
                </div>
            </form>
    </x-drawer>
@endsection

@push('scripts')
    <script>
        // Phase 4: point the issue form at the selected pass.
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.querySelector('[data-issue-form]');
            const select = document.querySelector('[data-issue-pass-select]');

            if (form && select) {
                select.addEventListener('change', () => { form.action = select.value; });
                form.action = select.value;
            }
        });
    </script>
@endpush
