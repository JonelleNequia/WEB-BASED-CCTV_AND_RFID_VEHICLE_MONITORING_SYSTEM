@extends('layouts.app')

@section('title', 'Vehicle Registry | PHILCST Vehicle Access Monitoring')
@section('page-title', 'Vehicle Registry')
@section('page-description', 'Manage recurring vehicle records and assign available RFID tags.')

@section('content')
    @php($shouldOpenVehicleForm = $errors->any() || old('plate_number') || old('rfid_tag_id'))

    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">Vehicle Records</span>
            <h3>Registered recurring vehicles</h3>
            <div class="inline-status-list">
                <span class="chip chip-brand">{{ $rfidStats['registered_vehicles'] ?? 0 }} vehicles</span>
                <span class="chip chip-soft">{{ $rfidStats['available_tags'] ?? 0 }} available RFID tags</span>
            </div>
        </div>

        <div class="hero-panel-actions">
            <a href="{{ route('rfid-inventory.index') }}" class="button button-secondary">RFID Tags</a>
            <a href="{{ route('rfid-scans.index') }}" class="button button-secondary">RFID Desk</a>
        </div>
    </section>

    <div class="page-grid cards-3">
        <article class="stat-card stat-card-brand">
            <div class="stat-card-head">
                <span class="stat-card-label">Registered Vehicles</span>
                <span class="stat-card-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5.5 6A2.5 2.5 0 0 0 3 8.5v6A2.5 2.5 0 0 0 5.5 17H6v1a1 1 0 1 0 2 0v-1h8v1a1 1 0 1 0 2 0v-1h.5A2.5 2.5 0 0 0 21 14.5v-6A2.5 2.5 0 0 0 18.5 6h-13M7 9.5a1.5 1.5 0 1 1-1.5 1.5A1.5 1.5 0 0 1 7 9.5m10 0a1.5 1.5 0 1 1-1.5 1.5A1.5 1.5 0 0 1 17 9.5M8.5 7.5l1-2h5l1 2z"/></svg>
                </span>
            </div>
            <strong>{{ $rfidStats['registered_vehicles'] ?? 0 }}</strong>
            <p>Recurring vehicles with an RFID-based campus flow.</p>
        </article>

        <article class="stat-card stat-card-brand-soft">
            <div class="stat-card-head">
                <span class="stat-card-label">Inside Campus</span>
                <span class="stat-card-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12 12 4l9 8v8a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg>
                </span>
            </div>
            <strong>{{ $rfidStats['vehicles_inside'] ?? 0 }}</strong>
            {{-- Phase 1: shared inside count (VehicleOccupancyService) --}}
            <p>{{ $rfidStats['registered_inside'] ?? 0 }} registered · {{ $rfidStats['guests_inside'] ?? 0 }} guests</p>
        </article>

        <article class="stat-card stat-card-success">
            <div class="stat-card-head">
                <span class="stat-card-label">Available RFID Tags</span>
                <span class="stat-card-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.55 18.7 4.8 13.95a1 1 0 0 1 1.4-1.4l3.35 3.34 8.25-8.24a1 1 0 1 1 1.4 1.4z"/></svg>
                </span>
            </div>
            <strong>{{ $rfidStats['available_tags'] ?? 0 }}</strong>
            <p>Tags ready for vehicle assignment.</p>
        </article>
    </div>

    <details class="panel collapsible-panel" @if ($shouldOpenVehicleForm) open @endif>
        <summary class="collapsible-summary">
            <span>
                <span class="panel-kicker">New Registry Record</span>
                <strong>Add Vehicle</strong>
                <small>Assign one available RFID tag to a recurring vehicle.</small>
            </span>
            <span class="button button-secondary button-sm">Open Form</span>
        </summary>

        <div class="collapsible-body">
            @php($selectedCategory = old('category', 'faculty_staff'))
            @php($categoryOtherValue = old('category_other', ! in_array($selectedCategory, $vehicleCategories, true) && $selectedCategory !== 'others' ? $selectedCategory : ''))
            @php($categorySelectValue = $categoryOtherValue !== '' ? 'others' : $selectedCategory)
            @php($selectedVehicleType = old('vehicle_type', 'Car'))
            @php($vehicleTypeOtherValue = old('vehicle_type_other', ! in_array($selectedVehicleType, $vehicleTypes, true) && $selectedVehicleType !== 'Others' ? $selectedVehicleType : ''))
            @php($vehicleTypeSelectValue = $vehicleTypeOtherValue !== '' ? 'Others' : $selectedVehicleType)

            <form method="POST" action="{{ route('vehicle-registry.store') }}" class="stack-form" data-rfid-registration-form>
                @csrf

                @error('vehicle')
                    <div class="alert alert-danger">{{ $message }}</div>
                @enderror

                <div class="form-grid">
                    <div class="field">
                        <label for="rfid_tag_id">RFID Tag</label>
                        <select id="rfid_tag_id" name="rfid_tag_id" required @disabled($availableTags->isEmpty())>
                            <option value="">{{ $availableTags->isEmpty() ? 'Register RFID tag first' : 'Choose RFID tag number' }}</option>
                            @foreach ($availableTags as $tag)
                                <option value="{{ $tag->id }}" @selected((string) old('rfid_tag_id') === (string) $tag->id)>
                                    RFID #{{ $tag->tag_number ?: 'N/A' }} - {{ $tag->uid }}
                                </option>
                            @endforeach
                        </select>
                        <div class="table-subtext">
                            @if ($availableTags->isEmpty())
                                Add a tag in <a href="{{ route('rfid-inventory.index') }}">RFID Tags</a> before saving a vehicle.
                            @else
                                Available tags are sorted by RFID tag number.
                            @endif
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
                    <a href="{{ route('rfid-inventory.index') }}" class="button button-secondary">Manage RFID Tags</a>
                </div>
            </form>
        </div>
    </details>

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>Registered Vehicles</h3>
                    @include('layouts.partials.help', [
                        'label' => 'Explain registered vehicles list',
                        'text' => 'This list contains recurring vehicle records only. RFID tag inventory is managed from the RFID Tags page.',
                    ])
                </div>
            </div>
            <a href="{{ route('rfid-inventory.index') }}" class="button button-secondary button-sm">RFID Tags</a>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Plate</th>
                        <th>Owner</th>
                        <th>Category</th>
                        <th>Vehicle</th>
                        <th>RFID Tag</th>
                        <th>State</th>
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
                                <span class="badge {{ $vehicle->status === 'active' ? 'badge-matched' : 'badge-unmatched' }}">
                                    {{ ucfirst($vehicle->status) }}
                                </span>
                                <div class="table-subtext">{{ ucfirst(strtolower($vehicle->current_state ?? 'outside')) }}</div>
                            </td>
                            <td>
                                <a href="{{ route('vehicle-registry.edit', $vehicle) }}" class="button button-secondary button-sm">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="table-empty">No registered vehicles yet.</td>
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
        });
    </script>
@endpush
