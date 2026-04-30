@extends('layouts.app')

@section('title', 'Edit Vehicle | PHILCST Vehicle Access Monitoring')
@section('page-title', 'Edit Vehicle')
@section('page-description', 'Update registered vehicle details and RFID sticker assignment.')

@section('content')
    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>{{ $vehicle->plate_number }}</h3>
            </div>
            <a href="{{ route('vehicle-registry.index') }}" class="button button-secondary">Back to Registry</a>
        </div>

        <form method="POST" action="{{ route('vehicle-registry.update', $vehicle) }}" class="stack-form" data-rfid-registration-form>
            @csrf
            @method('PUT')

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
                    <select id="category" name="category" required>
                        @foreach ($vehicleCategories as $category)
                            <option value="{{ $category }}" @selected(old('category', $vehicle->category) === $category)>
                                {{ ucfirst(str_replace('_', ' ', $category)) }}
                            </option>
                        @endforeach
                    </select>
                    @error('category')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="field">
                    <label for="vehicle_type">Vehicle Type</label>
                    <select id="vehicle_type" name="vehicle_type" required>
                        @foreach ($vehicleTypes as $vehicleType)
                            <option value="{{ $vehicleType }}" @selected(old('vehicle_type', $vehicle->vehicle_type) === $vehicleType)>{{ $vehicleType }}</option>
                        @endforeach
                    </select>
                    @error('vehicle_type')
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
