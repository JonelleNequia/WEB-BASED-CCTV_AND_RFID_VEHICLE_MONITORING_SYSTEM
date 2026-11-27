{{--
    UI Phase 3: plate / owner / category / vehicle type fields shared by the
    Add Vehicle and Edit Vehicle drawers. $prefix keeps the input ids unique;
    $useOld = true when this form is the one that failed validation.
--}}
@php
    $useOld = $useOld ?? false;
    // Phase 6 (visitor model): "Register this vehicle" fills plate, type and category.
    $prefill = $useOld ? [] : ($prefill ?? []);
    $value = fn (string $field, $default = '') => $useOld ? old($field, $default) : ($prefill[$field] ?? $default);

    // Phase 4 (visitor model): Faculty & Staff or Registered Guest only.
    $categorySelectValue = \App\Support\VehicleCategory::normalize($value('category', 'faculty_staff'));
    $selectedVehicleType = $value('vehicle_type', 'Car');
    $vehicleTypeOtherValue = $value('vehicle_type_other', ! in_array($selectedVehicleType, $vehicleTypes, true) && $selectedVehicleType !== 'Others' ? $selectedVehicleType : '') ?? '';
    $vehicleTypeSelectValue = in_array($selectedVehicleType, $vehicleTypes, true) ? $selectedVehicleType : 'Others';
@endphp

<div class="field">
    <label for="{{ $prefix }}_plate_number">Plate Number</label>
    <input id="{{ $prefix }}_plate_number" type="text" name="plate_number" value="{{ $value('plate_number') }}" placeholder="ABC 1234" required maxlength="50" autocomplete="off" data-field="plate_number" data-normalize-plate>
    @if ($useOld)
        @error('plate_number')<span class="field-error">{{ $message }}</span>@enderror
    @endif
</div>

<div class="field">
    <label for="{{ $prefix }}_owner">Owner Name</label>
    <input id="{{ $prefix }}_owner" type="text" name="vehicle_owner_name" value="{{ $value('vehicle_owner_name') }}" placeholder="Ma. José Dela Cruz" autocomplete="off" data-field="vehicle_owner_name" aria-describedby="{{ $prefix }}_owner_help {{ $prefix }}_owner_error" @if($prefix === 'add') required data-registration-owner @endif aria-invalid="{{ $useOld && $errors->has('vehicle_owner_name') ? 'true' : 'false' }}">
    <span class="field-help" id="{{ $prefix }}_owner_help">{{ $prefix === 'add' ? 'Enter the vehicle owner’s name.' : 'An existing blank or unchanged organization name can be kept.' }} Names may use letters, spaces, apostrophes, hyphens and periods (up to 100 characters).</span>
    <span class="field-error" id="{{ $prefix }}_owner_error" aria-live="polite" aria-atomic="true" @if(!$useOld || !$errors->has('vehicle_owner_name')) hidden @endif>{{ $useOld ? $errors->first('vehicle_owner_name') : '' }}</span>
</div>

<div class="field">
    <label for="{{ $prefix }}_category">Category</label>
    <select id="{{ $prefix }}_category" name="category" required data-field="category">
        @foreach ($vehicleCategories as $category)
            <option value="{{ $category }}" @selected($categorySelectValue === $category)>{{ \App\Support\VehicleCategory::label($category) }}</option>
        @endforeach
    </select>
    <span class="field-help">Unregistered Guest is set by the system for vehicles the camera sees without a registered tag.</span>
    @if ($useOld)
        @error('category')<span class="field-error">{{ $message }}</span>@enderror
    @endif
</div>

<div class="field">
    <label for="{{ $prefix }}_vehicle_type">Vehicle Type</label>
    <select id="{{ $prefix }}_vehicle_type" name="vehicle_type" required data-other-select data-other-target="{{ $prefix }}_vehicle_type_other" data-field="vehicle_type">
        @foreach ($vehicleTypes as $vehicleType)
            <option value="{{ $vehicleType }}" @selected($vehicleTypeSelectValue === $vehicleType)>{{ $vehicleType }}</option>
        @endforeach
        <option value="Others" @selected($vehicleTypeSelectValue === 'Others')>Others</option>
    </select>
    <label for="{{ $prefix }}_vehicle_type_other" data-type-specify-label @if ($vehicleTypeSelectValue !== 'Others') hidden @endif>Specify vehicle type</label>
    <input id="{{ $prefix }}_vehicle_type_other" type="text" name="vehicle_type_other" value="{{ $vehicleTypeOtherValue }}" placeholder="Specify vehicle type" maxlength="50" aria-label="Specify vehicle type" aria-describedby="{{ $prefix }}_type_other_error" data-registration-type-other data-other-field data-field="vehicle_type_other" @if ($vehicleTypeSelectValue !== 'Others') hidden @endif @required($vehicleTypeSelectValue === 'Others')>
    <span id="{{ $prefix }}_type_other_error" class="field-error" data-type-specify-error aria-live="polite" @if (!$useOld || !$errors->has('vehicle_type_other')) hidden @endif>{{ $useOld ? $errors->first('vehicle_type_other') : '' }}</span>
    @if ($useOld)
        @error('vehicle_type')<span class="field-error">{{ $message }}</span>@enderror
    @endif
</div>
