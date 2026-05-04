@extends('layouts.app')

@section('title', 'Edit Vehicle | PHILCST Vehicle Access Monitoring')
@section('page-title', 'Edit Vehicle')
@section('page-description', 'Update registered vehicle details and RFID sticker assignment.')

@section('content')
    <section class="panel">
        @php($selectedCategory = old('category', $vehicle->category))
        @php($categoryOtherValue = old('category_other', ! in_array($selectedCategory, $vehicleCategories, true) && $selectedCategory !== 'others' ? $selectedCategory : ''))
        @php($categorySelectValue = $categoryOtherValue !== '' ? 'others' : $selectedCategory)
        @php($selectedVehicleType = old('vehicle_type', $vehicle->vehicle_type))
        @php($vehicleTypeOtherValue = old('vehicle_type_other', ! in_array($selectedVehicleType, $vehicleTypes, true) && $selectedVehicleType !== 'Others' ? $selectedVehicleType : ''))
        @php($vehicleTypeSelectValue = $vehicleTypeOtherValue !== '' ? 'Others' : $selectedVehicleType)
        <div class="panel-header">
            <div>
                <h3>{{ $vehicle->plate_number }}</h3>
            </div>
            <a href="{{ route('vehicle-registry.index') }}" class="button button-secondary">Back to Registry</a>
        </div>

        <form method="POST" action="{{ route('vehicle-registry.update', ['vehicle' => $vehicle->getKey()]) }}" class="stack-form" data-rfid-registration-form>
            @csrf
            @method('PUT')
            <input type="hidden" name="rfid_tag_uid" value="{{ old('rfid_tag_uid', $vehicle->rfid_tag_uid) }}">

            @error('vehicle')
                <div class="alert alert-danger">{{ $message }}</div>
            @enderror

            <div class="form-grid">
                <div class="field">
                    <label for="rfid_tag_id">RFID Tag</label>
                    <select id="rfid_tag_id" name="rfid_tag_id" required>
                        <option value="">Select available RFID tag</option>
                        @foreach ($availableTags as $tag)
                            <option value="{{ $tag->id }}" @selected((string) old('rfid_tag_id', $vehicle->rfid_tag_id) === (string) $tag->id)>
                                {{ $tag->uid }}{{ $tag->vehicle_id === $vehicle->id ? ' | Current tag' : '' }}
                            </option>
                        @endforeach
                    </select>
                    @error('rfid_tag_id')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                    @if ($availableTags->isEmpty())
                        <span class="field-error">No available RFID tags. Enroll cards in RFID Inventory first.</span>
                    @endif
                </div>

                <div class="field">
                    <label for="plate_number">Plate Number</label>
                    <input id="plate_number" type="text" name="plate_number" value="{{ old('plate_number', $vehicle->plate_number) }}" required>
                    @error('plate_number')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="field">
                    <label for="vehicle_owner_name">Vehicle Owner Name</label>
                    <input id="vehicle_owner_name" type="text" name="vehicle_owner_name" value="{{ old('vehicle_owner_name', $vehicle->vehicle_owner_name) }}">
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
                <button type="submit" class="button button-primary">Update Vehicle</button>
                <a href="{{ route('rfid-inventory.index') }}" class="button button-secondary">Enroll Tags</a>
                <a href="{{ route('vehicle-registry.index') }}" class="button button-secondary">Cancel</a>
            </div>
        </form>
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
                };

                select.addEventListener('change', syncOtherField);
                syncOtherField();
            });
        });
    </script>
@endpush
