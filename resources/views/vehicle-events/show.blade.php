@extends('layouts.app')

@section('title', 'Vehicle Log Details | PHILCST Vehicle Access Monitoring')
@section('page-title', 'Vehicle Log Details')

@section('content')
    <x-page-header :title="'Vehicle Log #'.$vehicleEvent->id" :back="route('logs.index')" back-label="Back to logs">
        <x-slot:meta>
            <span class="badge badge-{{ $activityLog['status_badge_class'] }}">{{ $activityLog['status_label'] }}</span>
        </x-slot:meta>
    </x-page-header>

    <div class="page-grid two-column">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>Summary</h3>
                    </div>
                </div>
            </div>

            <div class="detail-list">
                <div><span>Log Type</span><strong>{{ $vehicleEvent->event_type }}</strong></div>
                <div><span>Status</span><strong>{{ $activityLog['status_label'] }}</strong></div>
                @if ($activityLog['is_alert'] && filled($activityLog['alert_reason']))
                    <div><span>Alert reason</span><strong>{{ $activityLog['alert_reason'] }}</strong></div>
                @endif
                <div><span>Source</span><strong>{{ $vehicleEvent->event_origin_label }}</strong></div>
                <div><span>Plate</span><strong>{{ $vehicleEvent->plate_text ?: $vehicleEvent->vehicle?->plate_number ?: 'GUEST' }}</strong></div>
                <div><span>Vehicle</span><strong>{{ $vehicleEvent->display_vehicle_type }}</strong></div>
                <div><span>Type source</span><strong>{{ $vehicleEvent->vehicle_type_source_label }}</strong></div>
                <div><span>Detector type</span><strong>{{ $vehicleEvent->detected_vehicle_type ?: 'Not recorded' }}</strong></div>
                <div><span>Category</span><strong>{{ $activityLog['category_label'] }}</strong></div>
                <div><span>Station / Camera</span><strong>{{ $vehicleEvent->camera_label }}</strong></div>
                <div><span>RFID Match</span><strong>{{ $vehicleEvent->rfidScanLog ? '#'.$vehicleEvent->rfidScanLog->id.' • '.$vehicleEvent->rfidScanLog->verificationLabel : 'No RFID scan linked' }}</strong></div>
                <div><span>Current State</span><strong>{{ $vehicleEvent->resulting_state_label }}</strong></div>
                <div><span>Vehicle snapshot</span><strong>{{ $activityLog['image_status'] }}</strong></div>
                @if ($activityLog['image_selected_at'])
                    <div><span>Frame selected</span><strong><x-datetime :value="$activityLog['image_selected_at']" /></strong></div>
                @endif
                @if ($activityLog['image_uploaded_at'])
                    <div><span>Image stored</span><strong><x-datetime :value="$activityLog['image_uploaded_at']" /></strong></div>
                @endif
                <div><span>Time</span><strong><x-datetime :value="$vehicleEvent->event_time" /></strong></div>
                <div><span>Match</span><strong>{{ $vehicleEvent->match_display }}</strong></div>
                <div><span>Plate Confidence</span><strong>{{ $vehicleEvent->plate_confidence ?? 'N/A' }}</strong></div>
            </div>

            @if ($vehicleEvent->visitor_record_id)
                <form method="POST" action="{{ route('visitors.records.type', $vehicleEvent->visitor_record_id) }}" class="stack-form">
                    @csrf
                    @method('PATCH')
                    <div class="field">
                        <label for="occurrence_vehicle_type">Correct vehicle type for this occurrence</label>
                        <select id="occurrence_vehicle_type" name="vehicle_type" required>
                            <option value="">Choose a vehicle type</option>
                            @foreach (\App\Support\VehicleType::TYPES as $type)
                                <option value="{{ $type }}" @selected(\App\Support\VehicleType::canonical(old('vehicle_type', $vehicleEvent->display_vehicle_type)) === $type)>{{ $type }}</option>
                            @endforeach
                        </select>
                        @error('vehicle_type')<span class="field-error">{{ $message }}</span>@enderror
                        <span class="field-help">Changes this occurrence only. The registered type and camera evidence are retained.</span>
                    </div>
                    <button type="submit" class="button button-secondary">Save type</button>
                </form>
            @endif

            @if ($vehicleEvent->event_type === 'ENTRY')
                <div class="mini-note">
                    <strong>{{ $vehicleEvent->activeSession?->status ? strtoupper($vehicleEvent->activeSession->status) : 'NO ACTIVE SESSION' }}</strong>
                    <p>Entry sessions remain open until an EXIT record closes them successfully.</p>
                </div>
            @endif
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>Visual Support</h3>
                    </div>
                </div>
            </div>

            @if ($vehicleEvent->has_visual_evidence)
                <div class="image-grid">
                    <div>
                        <label>Vehicle Image</label>
                        <button type="button" class="thumb-button" data-zoom="{{ $vehicleEvent->vehicle_image_url }}" data-zoom-label="Vehicle image"><img src="{{ $vehicleEvent->vehicle_image_url }}" alt="Vehicle image" class="thumb thumb-large"></button>
                    </div>
                    <div>
                        <label>Plate Image</label>
                        <button type="button" class="thumb-button" data-zoom="{{ $vehicleEvent->plate_image_url }}" data-zoom-label="Plate image"><img src="{{ $vehicleEvent->plate_image_url }}" alt="Plate image" class="thumb thumb-large"></button>
                    </div>
                </div>
            @else
                <div class="empty-state empty-state-inline">
                    <h4>No visual evidence attached</h4>
                    <p>{{ $vehicleEvent->visitor_record_id && !$vehicleEvent->rfid_scan_log_id ? 'This record relies on its confirmed plate and registry data.' : 'This record currently relies on RFID and registry data.' }}</p>
                </div>
            @endif
        </section>
    </div>

    <div class="page-grid two-column">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>Registered Vehicle</h3>
                    </div>
                </div>
            </div>

            @if ($vehicleEvent->vehicle)
                <div class="detail-list">
                    <div><span>Registered Plate</span><strong>{{ $vehicleEvent->vehicle->plate_number }}</strong></div>
                    <div><span>Vehicle Owner Name</span><strong>{{ $vehicleEvent->vehicle->vehicle_owner_name ?: 'N/A' }}</strong></div>
                    <div><span>Vehicle Type</span><strong>{{ $vehicleEvent->vehicle->vehicle_type }}</strong></div>
                    <div><span>Status</span><strong>{{ ucfirst($vehicleEvent->vehicle->status) }}</strong></div>
                    <div><span>RFID Tags</span><strong>{{ $vehicleEvent->vehicle->rfidTags->count() }}</strong></div>
                </div>

                @if ($vehicleEvent->vehicle->rfidTags->isNotEmpty())
                    <div class="badge-row">
                        @foreach ($vehicleEvent->vehicle->rfidTags as $tag)
                            <x-badge :status="$tag->status" :label="$tag->uid" />
                        @endforeach
                    </div>
                @endif
            @else
                <x-empty-state title="No registered vehicle linked" text="Add or update the vehicle in the registry to strengthen RFID verification." />
            @endif
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>RFID Match</h3>
                    </div>
                </div>
            </div>

            @if ($vehicleEvent->rfidScanLog)
                    <div class="detail-list">
                        <div><span>RFID Log</span><strong>#{{ $vehicleEvent->rfidScanLog->id }}</strong></div>
                        <div><span>Tag UID</span><strong>{{ $vehicleEvent->rfidScanLog->tag_uid }}</strong></div>
                        <div><span>Result</span><strong>{{ $vehicleEvent->rfidScanLog->verificationLabel }}</strong></div>
                        <div><span>Gate</span><strong>{{ $vehicleEvent->rfidScanLog->scanLocationLabel }} • {{ $vehicleEvent->rfidScanLog->scanDirectionLabel }}</strong></div>
                        <div><span>Event Type</span><strong>{{ $vehicleEvent->rfidScanLog->resolvedEventTypeLabel }}</strong></div>
                        <div><span>Current State</span><strong>{{ $vehicleEvent->rfidScanLog->resultingStateLabel }}</strong></div>
                        <div><span>Reader</span><strong>{{ $vehicleEvent->rfidScanLog->reader_name }}</strong></div>
                        <div><span>Time</span><strong><x-datetime :value="$vehicleEvent->rfidScanLog->scan_time" /></strong></div>
                    </div>

                @if ($vehicleEvent->rfidScanLog->notes)
                    <div class="mini-note">
                        <strong>RFID remarks</strong>
                        <p>{{ $vehicleEvent->rfidScanLog->notes }}</p>
                    </div>
                @endif
            @else
                <x-empty-state title="No RFID match linked" text="This record currently stands on its own." />
            @endif
        </section>
    </div>

    @if ($vehicleEvent->event_status === 'pending_details' && auth()->user()?->isAdmin())
        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>Complete Camera Record</h3>
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('vehicle-events.complete', $vehicleEvent) }}" enctype="multipart/form-data" class="stack-form">
                @csrf
                @method('PUT')

                <div class="form-grid">
                    <div class="field">
                        <label for="plate_text">Plate</label>
                        <input id="plate_text" type="text" name="plate_text" value="{{ old('plate_text', $vehicleEvent->plate_text) }}" placeholder="ABC-1234" required maxlength="50" data-normalize-plate data-plate-legacy="{{ $vehicleEvent->plate_text }}">
                    </div>

                    <div class="field">
                        <label for="plate_confidence">Plate Confidence</label>
                        <input id="plate_confidence" type="number" name="plate_confidence" min="0" max="100" step="0.01" value="{{ old('plate_confidence', $vehicleEvent->plate_confidence) }}" placeholder="Optional">
                    </div>

                    <div class="field">
                        <label for="vehicle_type">Vehicle Type</label>
                        <select id="vehicle_type" name="vehicle_type" required>
                            @foreach ($vehicleTypes as $vehicleType)
                                <option value="{{ $vehicleType }}" @selected(old('vehicle_type', $vehicleEvent->detected_vehicle_type ?: $vehicleEvent->vehicle_type) === $vehicleType)>{{ $vehicleType }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="plate_image">Plate Image</label>
                        <input id="plate_image" type="file" name="plate_image" accept="image/*">
                    </div>
                </div>

                <div class="button-row">
                    <button type="submit" class="button button-primary">Complete Record</button>
                    <a href="{{ route('registry.index') }}" class="button button-secondary">Registry</a>
                </div>
            </form>
        </section>
    @endif

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>Entry Match</h3>
                </div>
            </div>
        </div>

        @if ($vehicleEvent->matchedEntry)
            <div class="detail-list">
                <div><span>Entry Log</span><strong>#{{ $vehicleEvent->matchedEntry->id }}</strong></div>
                <div><span>Plate</span><strong>{{ $vehicleEvent->matchedEntry->plate_text }}</strong></div>
                <div><span>Vehicle</span><strong>{{ $vehicleEvent->matchedEntry->display_vehicle_type }}</strong></div>
                <div><span>Camera</span><strong>{{ $vehicleEvent->matchedEntry->camera_label }}</strong></div>
                <div><span>Time</span><strong><x-datetime :value="$vehicleEvent->matchedEntry->event_time" /></strong></div>
            </div>
        @else
            <x-empty-state title="No entry match linked" text="This log does not currently point to an entry record." />
        @endif
    </section>

    @if (auth()->user()?->isAdmin())
        <details class="details-card">
            <summary>
                <span>Advanced Record Details</span>
                <span class="chip chip-soft">Admin only</span>
            </summary>

            <div class="details-card-body">
                <div class="detail-list">
                    <div><span>Workflow Status</span><strong>{{ ucfirst(str_replace('_', ' ', $vehicleEvent->event_status)) }}</strong></div>
                    <div><span>Detected Vehicle Type</span><strong>{{ $vehicleEvent->detected_vehicle_type ?: 'N/A' }}</strong></div>
                    <div><span>Camera Source</span><strong>{{ $vehicleEvent->camera_device_label ?: ($vehicleEvent->camera ? \App\Support\CameraSource::display($vehicleEvent->camera->source_type, $vehicleEvent->camera->source_value) : 'N/A') }}</strong></div>
                    <div><span>Station / ROI</span><strong>{{ $vehicleEvent->roi_name ?: 'N/A' }}</strong></div>
                    <div><span>External Event Key</span><strong>{{ $vehicleEvent->external_event_key ?: 'Manual or RFID record' }}</strong></div>
                    <div><span>Details Completed</span><strong><x-datetime :value="$vehicleEvent->details_completed_at" fallback="Not completed yet" /></strong></div>
                    <div>
                        <span>RFID Evidence</span>
                        <strong>
                            @if ($vehicleEvent->rfidScanLog?->payload_file_path)
                                <a href="{{ route('evidence.rfid.payload', $vehicleEvent->rfidScanLog) }}">Download evidence</a>
                            @else
                                Not available
                            @endif
                        </strong>
                    </div>
                </div>
            </div>
        </details>
    @endif
@endsection
