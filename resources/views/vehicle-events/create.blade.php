@extends('layouts.app')

@section('title', 'Quick Manual Log | PHILCST Vehicle Access Monitoring')
@section('page-title', 'Quick Manual Log')
@section('page-description', 'Fallback logging page for cases where RFID or camera support cannot complete the record automatically.')

@section('content')
    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">Support Only</span>
            <h3>Create a manual vehicle log</h3>
            <div class="inline-status-list">
                <span class="chip chip-soft">Use only when the normal RFID flow cannot be completed</span>
            </div>
        </div>

        <div class="hero-panel-actions">
            <a href="{{ route('vehicle-events.index') }}" class="button button-secondary">Back to Logs</a>
            <a href="{{ route('rfid-scans.index') }}" class="button button-primary">Open RFID Desk</a>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>Manual Vehicle Log</h3>
                    @include('layouts.partials.help', [
                        'label' => 'Explain manual log form',
                        'text' => 'ENTRY logs open sessions. EXIT logs run the same matching rules used by the rest of the system. This page is a fallback workflow.',
                    ])
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('vehicle-events.store') }}" enctype="multipart/form-data" class="form-grid">
            @csrf

            <div class="field">
                <label for="event_type">Log Type</label>
                <select id="event_type" name="event_type" required>
                    <option value="ENTRY" @selected(old('event_type', $eventType) === 'ENTRY')>ENTRY</option>
                    <option value="EXIT" @selected(old('event_type', $eventType) === 'EXIT')>EXIT</option>
                </select>
            </div>

            <div class="field">
                <label for="plate_text">Plate</label>
                <input id="plate_text" type="text" name="plate_text" value="{{ old('plate_text') }}" placeholder="ABC-1234" required>
            </div>

            <div class="field">
                <label for="plate_confidence">Plate Confidence</label>
                <input id="plate_confidence" type="number" step="0.01" min="0" max="100" name="plate_confidence" value="{{ old('plate_confidence') }}" placeholder="Optional">
            </div>

            <div class="field">
                <label for="vehicle_type">Vehicle Type</label>
                <select id="vehicle_type" name="vehicle_type" required>
                    <option value="">Select vehicle type</option>
                    @foreach ($vehicleTypes as $vehicleType)
                        <option value="{{ $vehicleType }}" @selected(old('vehicle_type') === $vehicleType)>{{ $vehicleType }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="vehicle_color">Vehicle Color</label>
                <select id="vehicle_color" name="vehicle_color" required>
                    <option value="">Select vehicle color</option>
                    @foreach ($vehicleColors as $vehicleColor)
                        <option value="{{ $vehicleColor }}" @selected(old('vehicle_color') === $vehicleColor)>{{ $vehicleColor }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="camera_id">Camera</label>
                <select id="camera_id" name="camera_id" required>
                    <option value="">Select camera</option>
                    @foreach ($cameras as $camera)
                        <option value="{{ $camera->id }}" @selected((string) old('camera_id') === (string) $camera->id)>
                            {{ $camera->camera_name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="roi_name">Station / ROI</label>
                <select id="roi_name" name="roi_name" required>
                    <option value="">Select station or ROI</option>
                    @foreach ($rois as $roi)
                        <option value="{{ $roi->roi_name }}" @selected(old('roi_name') === $roi->roi_name)>
                            {{ $roi->roi_name }} ({{ $roi->camera?->camera_name ?? 'No Camera' }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="event_time">Time</label>
                <input id="event_time" type="datetime-local" name="event_time" value="{{ old('event_time', now()->format('Y-m-d\TH:i')) }}" required>
            </div>

            <div class="field">
                <label for="vehicle_image">Vehicle Image</label>
                <input id="vehicle_image" type="file" name="vehicle_image" accept="image/*">
            </div>

            <div class="field">
                <label for="plate_image">Plate Image</label>
                <input id="plate_image" type="file" name="plate_image" accept="image/*">
            </div>

            <div class="field field-actions span-full">
                <div class="button-row">
                    <button type="submit" class="button button-primary">Save Manual Log</button>
                    <a href="{{ route('vehicle-events.index') }}" class="button button-secondary">Back to Logs</a>
                </div>
            </div>
        </form>
    </section>
@endsection
