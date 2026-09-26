@extends('layouts.app')

@section('title', 'RFID Tags | PHILCST Vehicle Access Monitoring')
@section('page-title', 'RFID Tags')
@section('page-description', 'Register RFID tag UIDs and keep the tag inventory separate from vehicle records.')

@section('content')

    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">RFID Inventory</span>
            <h3>Tag registration workspace</h3>
            <div class="inline-status-list">
                <span class="chip chip-brand">{{ $rfidStats['registered_tags'] ?? 0 }} total tags</span>
                <span class="chip chip-soft">{{ $rfidStats['available_tags'] ?? 0 }} available</span>
            </div>
        </div>

        <div class="hero-panel-actions">
            <a href="{{ route('vehicle-registry.index') }}" class="button button-primary">Vehicle Registry</a>
            <a href="{{ route('rfid-scans.index') }}" class="button button-secondary">RFID Desk</a>
        </div>
    </section>

    {{-- Phase 4: separate stats for vehicle tags and guest passes. --}}
    <div class="page-grid cards-5">
        <article class="stat-card stat-card-success">
            <span class="stat-card-label">Vehicle Tags Available</span>
            <strong>{{ $tagStats['vehicle_available'] }}</strong>
            <p>Ready to assign in Vehicle Registry.</p>
        </article>

        <article class="stat-card stat-card-brand-soft">
            <span class="stat-card-label">Vehicle Tags Assigned</span>
            <strong>{{ $tagStats['vehicle_assigned'] }}</strong>
            <p>{{ $tagStats['vehicle_total'] }} vehicle tags in total.</p>
        </article>

        <article class="stat-card stat-card-brand">
            <span class="stat-card-label">Guest Passes Available</span>
            <strong>{{ $tagStats['pass_available'] }}</strong>
            <p>{{ $tagStats['pass_total'] }} guest passes in total.</p>
        </article>

        <article class="stat-card stat-card-brand-soft">
            <span class="stat-card-label">Guest Passes Issued</span>
            <strong>{{ $tagStats['pass_issued'] }}</strong>
            <p>Currently with a guest inside.</p>
        </article>

        <article class="stat-card stat-card-warning">
            <span class="stat-card-label">Guest Passes Lost</span>
            <strong>{{ $tagStats['pass_lost'] }}</strong>
            <p>Scans of lost passes raise an alert.</p>
        </article>
    </div>

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>Register RFID Tag</h3>
                    @include('layouts.partials.help', [
                        'label' => 'Explain RFID inventory',
                        'text' => 'Register tag UIDs here first. Vehicle Registry only assigns available tags to vehicle records.',
                    ])
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('rfid-inventory.store') }}" class="form-grid filter-grid" data-rfid-inventory-form>
            @csrf

            {{-- Phase 4: Vehicle tag or reusable Guest Pass (gets the next G-xx number). --}}
            <div class="field">
                <label for="inventory_tag_type">Tag Type</label>
                <select id="inventory_tag_type" name="tag_type">
                    <option value="vehicle" @selected(old('tag_type', $tagTypeFilter ?? 'vehicle') === 'vehicle')>Vehicle tag</option>
                    <option value="guest_pass" @selected(old('tag_type', $tagTypeFilter) === 'guest_pass')>Guest Pass</option>
                </select>
                @error('tag_type')
                    <span class="field-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label for="inventory_tag_number">RFID Tag No.</label>
                <input
                    id="inventory_tag_number"
                    type="number"
                    name="tag_number"
                    value="{{ old('tag_number') }}"
                    min="1"
                    step="1"
                    placeholder="1"
                    required
                    data-rfid-tag-number-input
                >
                @error('tag_number')
                    <span class="field-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field span-2">
                <label for="inventory_uid">RFID UID</label>
                <input
                    id="inventory_uid"
                    type="text"
                    name="uid"
                    value="{{ old('uid') }}"
                    autocomplete="off"
                    inputmode="none"
                    placeholder="Focus and scan RFID card"
                    readonly
                    required
                    data-rfid-inventory-input
                >
                <div class="table-subtext" data-rfid-scan-message>Enter the tag number, then tap the RFID card. Manual typing is disabled for UID.</div>
                @error('uid')
                    <span class="field-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field field-actions">
                <button type="submit" class="button button-primary">Register RFID Tag</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Tag Inventory</h3>
            </div>
            {{-- Phase 4: filter by tag type. --}}
            <div class="inline-status-list">
                @foreach ([null => 'All', 'vehicle' => 'Vehicle tags', 'guest_pass' => 'Guest passes'] as $value => $label)
                    <a href="{{ route('rfid-inventory.index', array_filter(['tag_type' => $value])) }}"
                       class="chip {{ ($tagTypeFilter ?? null) === ($value ?: null) ? 'chip-brand' : 'chip-soft' }}">{{ $label }}</a>
                @endforeach
                <span class="chip chip-soft">{{ $rfidTagInventory->count() }} records</span>
            </div>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>RFID No.</th>
                        <th>Type</th>
                        <th>RFID UID</th>
                        <th>Status</th>
                        <th>Assigned Vehicle</th>
                        <th>Last Scan</th>
                        <th>Scans</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rfidTagInventory as $tag)
                        <tr>
                            <td><strong>#{{ $tag->tag_number ?: 'N/A' }}</strong></td>
                            <td>
                                @if ($tag->isGuestPass())
                                    <span class="badge badge-open">Guest Pass {{ $tag->display_number }}</span>
                                @else
                                    <span class="badge badge-secondary">Vehicle</span>
                                @endif
                            </td>
                            <td><strong>{{ $tag->uid }}</strong></td>
                            <td>
                                <span class="badge {{ match ($tag->status) {
                                    'available' => 'badge-secondary',
                                    'assigned', 'issued' => 'badge-matched',
                                    'lost' => 'badge-manual-review',
                                    default => 'badge-unmatched',
                                } }}">
                                    {{ ucfirst($tag->status) }}
                                </span>
                            </td>
                            <td>
                                @if ($tag->isGuestPass())
                                    <span class="table-subtext">{{ $tag->status === 'issued' ? 'With a guest' : 'Guest pass pool' }}</span>
                                @elseif ($tag->vehicle)
                                    <strong>{{ $tag->vehicle->plate_number }}</strong>
                                    <div class="table-subtext">{{ $tag->vehicle->vehicle_owner_name ?: 'No owner' }}</div>
                                @else
                                    <span class="table-subtext">Available for assignment</span>
                                @endif
                            </td>
                            <td>{{ $tag->last_scanned_at?->format('M d, Y h:i A') ?: 'No scan yet' }}</td>
                            <td>{{ $tag->scan_logs_count }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="table-empty">No RFID tags registered yet.</td>
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
            const inventoryInput = document.querySelector('[data-rfid-inventory-input]');
            const tagNumberInput = document.querySelector('[data-rfid-tag-number-input]');
            let scanBuffer = '';
            let firstKeyAt = 0;
            let lastKeyAt = 0;
            let idleTimer = null;

            const normalizeUid = (value) => String(value || '').replace(/\s+/g, '').trim().toUpperCase();

            const setScanMessage = (message, isError = false) => {
                const messageNode = inventoryInput?.closest('.field')?.querySelector('[data-rfid-scan-message]');

                if (!messageNode) {
                    return;
                }

                messageNode.textContent = message;
                messageNode.classList.toggle('field-error', isError);
            };

            const acceptScannedUid = (rawUid) => {
                const uid = normalizeUid(rawUid);

                if (!uid || !inventoryInput) {
                    return;
                }

                inventoryInput.value = uid;
                setScanMessage(`${uid} captured from scanner.`);
            };

            const resetScanBuffer = () => {
                scanBuffer = '';
                firstKeyAt = 0;
                lastKeyAt = 0;
                window.clearTimeout(idleTimer);
            };

            const maybeCommitScan = () => {
                if (!inventoryInput || inventoryInput.disabled) {
                    resetScanBuffer();
                    return;
                }

                const elapsed = lastKeyAt - firstKeyAt;
                const averageGap = scanBuffer.length > 1 ? elapsed / (scanBuffer.length - 1) : elapsed;

                if (scanBuffer.length >= 4 && elapsed <= 900 && averageGap <= 80) {
                    acceptScannedUid(scanBuffer);
                }

                resetScanBuffer();
            };

            if (inventoryInput) {
                inventoryInput.addEventListener('focus', resetScanBuffer);
                inventoryInput.addEventListener('paste', (event) => event.preventDefault());
                inventoryInput.addEventListener('drop', (event) => event.preventDefault());
            }

            tagNumberInput?.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && inventoryInput && !inventoryInput.value) {
                    event.preventDefault();
                    inventoryInput.focus({ preventScroll: true });
                }
            });

            document.addEventListener('keydown', (event) => {
                if (!inventoryInput || inventoryInput.disabled || event.ctrlKey || event.metaKey || event.altKey) {
                    return;
                }

                const target = event.target;
                const typingIntoFormField = target instanceof HTMLInputElement
                    || target instanceof HTMLTextAreaElement
                    || target instanceof HTMLSelectElement;

                if (typingIntoFormField && target !== inventoryInput) {
                    return;
                }

                if (event.key === 'Enter') {
                    event.preventDefault();
                    maybeCommitScan();
                    return;
                }

                if (event.key.length !== 1) {
                    return;
                }

                event.preventDefault();

                const now = Date.now();
                if (!scanBuffer || now - lastKeyAt > 120) {
                    scanBuffer = '';
                    firstKeyAt = now;
                }

                scanBuffer += event.key;
                lastKeyAt = now;
                window.clearTimeout(idleTimer);
                idleTimer = window.setTimeout(maybeCommitScan, 140);
            });

            if (inventoryInput && document.activeElement === document.body) {
                inventoryInput.focus({ preventScroll: true });
            }
        });
    </script>
@endpush
