{{-- Phase 4: Guest Passes page. Replaces Guest Monitoring as the main guest flow. --}}
@extends('layouts.app')

@section('title', 'Guest Passes | PHILCST Vehicle Monitoring')
@section('page-title', 'Guest Passes')
@section('page-description', 'Temporary RFID passes for guest vehicles: issue at the Entrance, collect at the Exit.')

@php
    $statusBadge = fn (string $status): string => match ($status) {
        'active' => 'badge-open',
        'overstay' => 'badge-unmatched',
        'lost_tag' => 'badge-manual-review',
        default => 'badge-matched',
    };
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
    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">Temporary RFID</span>
            <h3>Guest pass desk</h3>
            <div class="inline-status-list">
                <span class="chip chip-brand">{{ $stats['passes_total'] }} guest passes</span>
                <span class="chip chip-soft">Valid for {{ intdiv($validityMinutes, 60) }}h {{ $validityMinutes % 60 }}m by default</span>
            </div>
        </div>

        <div class="hero-panel-actions">
            <a href="{{ route('stations.entrance') }}" class="button button-secondary">Entrance Station</a>
            <a href="{{ route('stations.exit') }}" class="button button-secondary">Exit Station</a>
            <a href="{{ route('rfid-inventory.index', ['tag_type' => 'guest_pass']) }}" class="button button-secondary">Manage Passes</a>
        </div>
    </section>

    <div class="page-grid cards-3">
        <article class="stat-card stat-card-brand">
            <span class="stat-card-label">Active Guests</span>
            <strong data-guest-pass-stat="active_guests">{{ $stats['active_guests'] }}</strong>
            <p>Guests inside with a pass (including overstay).</p>
        </article>

        <article class="stat-card stat-card-success">
            <span class="stat-card-label">Passes Available</span>
            <strong>{{ $stats['passes_available'] }} <small>/ {{ $stats['passes_total'] }}</small></strong>
            <p>{{ $stats['passes_lost'] }} lost pass(es).</p>
        </article>

        <article class="stat-card stat-card-warning">
            <span class="stat-card-label">Overstay</span>
            <strong>{{ $stats['overstay'] }}</strong>
            <p>Still inside after their valid-until time.</p>
        </article>
    </div>

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>Issue Guest Pass</h3>
                    @include('layouts.partials.help', [
                        'label' => 'Explain issuing',
                        'text' => 'Normally the Entrance Station opens this form when an available pass is tapped. Use this form when the reader is not available.',
                    ])
                </div>
            </div>
        </div>

        @if ($availablePasses->isEmpty())
            <div class="empty-state-inline">No available guest passes. Register passes in RFID Tags or use the manual fallback below.</div>
        @else
            <form method="POST" data-issue-form action="{{ route('guest-passes.issue', $availablePasses->first()) }}" class="stack-form">
                @csrf
                <div class="form-grid">
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
                    <button type="submit" class="button button-primary">Issue Pass</button>
                </div>
            </form>
        @endif
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Guest Visits</h3>
            </div>
            <span class="chip chip-soft">{{ $visits->total() }} visit(s)</span>
        </div>

        <form method="GET" action="{{ route('guest-passes.index') }}" class="form-grid filter-grid">
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
                    <a href="{{ route('guest-passes.index') }}" class="button button-secondary">Reset</a>
                </div>
            </div>
        </form>

        <div class="table-responsive">
            <table>
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
                    @forelse ($visits as $visit)
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
                            <td>{{ $visit->entry_at?->format('M d, h:i A') ?? 'N/A' }}</td>
                            <td>
                                {{ $visit->exit_at?->format('M d, h:i A') ?? 'Inside' }}
                                @if ($visit->isOpen() && $visit->valid_until)
                                    <div class="table-subtext">Valid until {{ $visit->valid_until->format('h:i A') }}</div>
                                @endif
                            </td>
                            <td>{{ $duration($visit) }}</td>
                            <td><span class="badge {{ $statusBadge($visit->status) }}">{{ $statusLabel($visit->status) }}</span></td>
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
                    @empty
                        <tr>
                            <td colspan="9" class="table-empty">No guest visits for this filter.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $visits->links() }}
    </section>

    <details class="details-card">
        <summary>
            <span>Manual guest entry (fallback)</span>
            <span class="chip chip-soft">When passes run out</span>
        </summary>

        <div class="details-card-body">
            <p class="table-subtext">
                Records a guest observation without a pass. It does not count as a guest inside.
                <a href="{{ route('guest-observations.index') }}">View CCTV observations</a>
            </p>

            <form method="POST" action="{{ route('guest-observations.store') }}" enctype="multipart/form-data" class="stack-form">
                @csrf
                <input type="hidden" name="observation_source" value="manual">
                <div class="form-grid">
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
                    <button type="submit" class="button button-secondary">Save Manual Entry</button>
                </div>
            </form>
        </div>
    </details>
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
