@extends('layouts.app')

@section('title', 'Vehicle Registry | PHILCST Vehicle Access Monitoring')
@section('page-title', 'Vehicle Registry')
@section('page-description', 'Register RFID tags first, then assign available tags to recurring vehicles before entrance and exit monitoring.')

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
            <a href="{{ route('stations.entrance') }}" class="button button-secondary">Entrance Station</a>
            <a href="{{ route('stations.exit') }}" class="button button-secondary">Exit Station</a>
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
                <span class="stat-card-label">Registered Tags</span>
                <span class="stat-card-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v11a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 17.5zm4 1.5v8l6-4z"/></svg>
                </span>
            </div>
            <strong>{{ $rfidStats['registered_tags'] ?? 0 }}</strong>
            <p>RFID UIDs registered in the inventory before vehicle assignment.</p>
        </article>
    </div>

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>RFID Tag Inventory</h3>
                    @include('layouts.partials.help', [
                        'label' => 'Explain RFID inventory',
                        'text' => 'Register RFID tag UIDs here first. Only available inventory tags can be assigned to a vehicle.',
                    ])
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('vehicle-registry.rfid-tags.store') }}" class="form-grid filter-grid" data-rfid-inventory-form>
            @csrf

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

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>RFID No.</th>
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
                            <td><strong>{{ $tag->uid }}</strong></td>
                            <td>
                                <span class="badge {{ $tag->status === 'available' ? 'badge-secondary' : ($tag->status === 'assigned' ? 'badge-matched' : 'badge-unmatched') }}">
                                    {{ ucfirst($tag->status) }}
                                </span>
                            </td>
                            <td>
                                @if ($tag->vehicle)
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
                            <td colspan="6" class="table-empty">No RFID tags registered yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="page-grid two-column">
        <section class="panel">
            @php($selectedCategory = old('category', 'faculty_staff'))
            @php($categoryOtherValue = old('category_other', ! in_array($selectedCategory, $vehicleCategories, true) && $selectedCategory !== 'others' ? $selectedCategory : ''))
            @php($categorySelectValue = $categoryOtherValue !== '' ? 'others' : $selectedCategory)
            @php($selectedVehicleType = old('vehicle_type', 'Car'))
            @php($vehicleTypeOtherValue = old('vehicle_type_other', ! in_array($selectedVehicleType, $vehicleTypes, true) && $selectedVehicleType !== 'Others' ? $selectedVehicleType : ''))
            @php($vehicleTypeSelectValue = $vehicleTypeOtherValue !== '' ? 'Others' : $selectedVehicleType)
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>Add or Update Vehicle</h3>
                        @include('layouts.partials.help', [
                            'label' => 'Explain registry form',
                            'text' => 'Register the RFID tag in inventory first, then choose one available tag for the vehicle.',
                        ])
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('vehicle-registry.store') }}" class="stack-form" data-rfid-registration-form>
                @csrf

                @error('vehicle')
                    <div class="alert alert-danger">{{ $message }}</div>
                @enderror

                <div class="form-grid">
                    <div class="field">
                        <label for="rfid_tag_id">RFID Tag</label>
                        <select id="rfid_tag_id" name="rfid_tag_id" required @disabled($availableTags->isEmpty())>
                            <option value="">{{ $availableTags->isEmpty() ? 'Register an RFID tag first' : 'Choose RFID tag number' }}</option>
                            @foreach ($availableTags as $tag)
                                <option value="{{ $tag->id }}" @selected((string) old('rfid_tag_id') === (string) $tag->id)>
                                    RFID #{{ $tag->tag_number ?: 'N/A' }} - {{ $tag->uid }}
                                </option>
                            @endforeach
                        </select>
                        <div class="table-subtext" data-rfid-scan-message>
                            {{ $availableTags->isEmpty() ? 'Add a tag in RFID Tag Inventory before saving a vehicle.' : 'Available tags are sorted by RFID tag number.' }}
                        </div>
                        @error('rfid_tag_id')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                        @error('rfid_uid')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="plate_number">Plate Number</label>
                        <input id="plate_number" type="text" name="plate_number" value="{{ old('plate_number') }}" placeholder="ABC-1234" required>
                        @error('plate_number')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="vehicle_owner_name">Vehicle Owner Name</label>
                        <input id="vehicle_owner_name" type="text" name="vehicle_owner_name" value="{{ old('vehicle_owner_name') }}" placeholder="Vehicle owner name">
                        @error('vehicle_owner_name')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="category">Category</label>
                        <select id="category" name="category" required data-other-select data-other-target="category_other">
                            @foreach ($vehicleCategories as $category)
                                <option value="{{ $category }}" @selected($categorySelectValue === $category)>
                                    {{ ucfirst(str_replace('_', ' ', $category)) }}
                                </option>
                            @endforeach
                            <option value="others" @selected($categorySelectValue === 'others')>Others</option>
                        </select>
                        <input
                            id="category_other"
                            type="text"
                            name="category_other"
                            value="{{ $categoryOtherValue }}"
                            placeholder="Enter custom category"
                            data-other-field
                            @if ($categorySelectValue !== 'others') hidden @endif
                        >
                        @error('category')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                        @error('category_other')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="vehicle_type">Vehicle Type</label>
                        <select id="vehicle_type" name="vehicle_type" required data-other-select data-other-target="vehicle_type_other">
                            @foreach ($vehicleTypes as $vehicleType)
                                <option value="{{ $vehicleType }}" @selected($vehicleTypeSelectValue === $vehicleType)>{{ $vehicleType }}</option>
                            @endforeach
                            <option value="Others" @selected($vehicleTypeSelectValue === 'Others')>Others</option>
                        </select>
                        <input
                            id="vehicle_type_other"
                            type="text"
                            name="vehicle_type_other"
                            value="{{ $vehicleTypeOtherValue }}"
                            placeholder="Enter custom vehicle type"
                            data-other-field
                            @if ($vehicleTypeSelectValue !== 'Others') hidden @endif
                        >
                        @error('vehicle_type')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                        @error('vehicle_type_other')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="button-row">
                    <button type="submit" class="button button-primary" @disabled($availableTags->isEmpty())>Save Vehicle</button>
                    <a href="{{ route('rfid-scans.index') }}" class="button button-secondary">Go to RFID Desk</a>
                </div>
            </form>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>RFID Assignment</h3>
                        @include('layouts.partials.help', [
                            'label' => 'Explain RFID assignment',
                            'text' => 'The registry uses an inventory pool: available tags can be assigned, assigned tags cannot be reused.',
                        ])
                    </div>
                </div>
            </div>

            <div class="detail-list">
                <div><span>RFID Tags in Inventory</span><strong>{{ $rfidStats['registered_tags'] ?? 0 }}</strong></div>
                <div><span>Available Tags</span><strong>{{ $rfidStats['available_tags'] ?? 0 }}</strong></div>
                <div><span>Registered Vehicles</span><strong>{{ $rfidStats['registered_vehicles'] ?? 0 }}</strong></div>
                <div><span>Workflow</span><strong>Inventory first</strong></div>
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
                        <th>Status</th>
                        <th>Tags</th>
                        <th>Totals</th>
                        <th>Today</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($vehicles as $vehicle)
                        <tr>
                            <td><strong>{{ $vehicle->plate_number }}</strong></td>
                            <td>{{ $vehicle->vehicle_owner_name ?: 'N/A' }}</td>
                            <td>{{ ucfirst(str_replace('_', ' ', $vehicle->category)) }}</td>
                            <td>{{ $vehicle->vehicle_type }}</td>
                            <td>
                                <span class="badge {{ $vehicle->status === 'active' ? 'badge-matched' : 'badge-unmatched' }}">
                                    {{ ucfirst($vehicle->status) }}
                                </span>
                            </td>
                            <td>
                                @if (! $vehicle->rfidTag && $vehicle->rfidTags->isEmpty())
                                    <span class="badge badge-secondary">No tag</span>
                                @elseif ($vehicle->rfidTag)
                                    <span class="badge {{ $vehicle->rfidTag->status === 'assigned' ? 'badge-matched' : 'badge-unmatched' }}">
                                        #{{ $vehicle->rfidTag->tag_number ?: 'N/A' }} - {{ $vehicle->rfidTag->uid }}
                                    </span>
                                @else
                                    <div class="badge-row">
                                        @foreach ($vehicle->rfidTags as $tag)
                                            <span class="badge {{ $tag->status === 'assigned' ? 'badge-matched' : 'badge-unmatched' }}">
                                                #{{ $tag->tag_number ?: 'N/A' }} - {{ $tag->uid }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td>
                                <strong>E{{ $vehicle->total_entries_count }}</strong>
                                <div class="table-subtext">X{{ $vehicle->total_exits_count }} / {{ $vehicle->rfid_scan_logs_count }} scans</div>
                            </td>
                            <td>
                                E{{ $vehicle->entries_today_count }} / X{{ $vehicle->exits_today_count }}
                            </td>
                            <td>
                                <a href="{{ route('vehicle-registry.edit', $vehicle) }}" class="button button-secondary button-sm">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="table-empty">No registered vehicles yet.</td>
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
            document.querySelectorAll('[data-other-select]').forEach((select) => {
                const field = document.getElementById(select.dataset.otherTarget);
                const otherValues = ['others', 'Others'];

                if (!field) {
                    return;
                }

                const syncOtherField = () => {
                    const show = otherValues.includes(select.value);
                    field.hidden = !show;
                    field.toggleAttribute('required', show);

                    if (show) {
                        field.focus({ preventScroll: true });
                    }
                };

                select.addEventListener('change', syncOtherField);
                syncOtherField();
            });

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
