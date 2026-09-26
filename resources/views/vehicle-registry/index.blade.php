@extends('layouts.app')

@section('title', 'Vehicle Registry | PHILCST Vehicle Access Monitoring')
@section('page-title', 'Vehicle Registry')

@section('content')
    @php($shouldOpenVehicleForm = $errors->any() || old('plate_number') || old('rfid_tag_id'))

    <x-page-header title="Vehicle Registry">
        <x-slot:actions>
            <button type="button" class="button button-primary" data-drawer-open="add-vehicle-drawer">Add Vehicle</button>
        </x-slot:actions>
    </x-page-header>

    <x-stat-row>
        <x-stat label="Registered Vehicles" :value="$rfidStats['registered_vehicles'] ?? 0" />
        {{-- Phase 1: shared inside count (VehicleOccupancyService) --}}
        <x-stat label="Inside Campus" :value="$rfidStats['vehicles_inside'] ?? 0"
                :hint="($rfidStats['registered_inside'] ?? 0).' registered · '.($rfidStats['guests_inside'] ?? 0).' guests'" />
        <x-stat label="Available RFID Tags" :value="$rfidStats['available_tags'] ?? 0" :href="route('rfid-inventory.index')" />
    </x-stat-row>

    <x-drawer id="add-vehicle-drawer" title="Add Vehicle" :open="$shouldOpenVehicleForm">
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
                    <button type="button" class="button button-secondary" data-drawer-close>Cancel</button>
                    <button type="submit" class="button button-primary" @disabled($availableTags->isEmpty())>Save Vehicle</button>
                </div>
            </form>
    </x-drawer>

    <x-table title="Registered Vehicles" :empty="$vehicles->isEmpty()" empty-title="No registered vehicles yet." empty-text="Add a vehicle and assign it an RFID tag.">
        <x-slot:emptyAction>
            <button type="button" class="button button-primary button-sm" data-drawer-open="add-vehicle-drawer">Add Vehicle</button>
        </x-slot:emptyAction>
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
                    @foreach ($vehicles as $vehicle)
                        <tr>
                            <td><strong>{{ $vehicle->plate_number }}</strong></td>
                            <td>{{ $vehicle->vehicle_owner_name ?: 'N/A' }}</td>
                            <td>{{ ucfirst(str_replace('_', ' ', $vehicle->category)) }}</td>
                            <td>{{ $vehicle->vehicle_type }}</td>
                            <td>
                                @if (! $vehicle->rfidTag && $vehicle->rfidTags->isEmpty())
                                    <x-badge status="no_tag" />
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
                                <x-badge :status="strtolower($vehicle->current_state ?? 'outside')" />
                                @if ($vehicle->status !== 'active')
                                    <x-badge :status="$vehicle->status" />
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('vehicle-registry.edit', $vehicle) }}" class="button button-secondary button-sm">Edit</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
    </x-table>
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
