@extends('layouts.app')

@section('title', 'Vehicle Log Details | PHILCST Vehicle Access Monitoring')
@section('page-title', 'Vehicle Log Details')
@section('page-description', 'Review the vehicle log, RFID match, visual support, and any completion steps still required.')

@section('content')
    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">Vehicle Log</span>
            <h3>Record #{{ $vehicleEvent->id }}</h3>
        </div>

        <div class="hero-panel-actions">
            <span class="badge badge-{{ $vehicleEvent->status_badge_class }}">
                {{ $vehicleEvent->display_status === 'pending_details' ? 'Incomplete Record' : str_replace('_', ' ', ucfirst($vehicleEvent->display_status)) }}
            </span>
            <a href="{{ route('vehicle-events.index') }}" class="button button-secondary">Back to Logs</a>
        </div>
    </section>

    <div class="page-grid two-column">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>Summary</h3>
                        @include('layouts.partials.help', [
                            'label' => 'Explain log summary',
                            'text' => 'This summary keeps the main operational details first: log type, source, plate, vehicle, station, time, and current workflow result.',
                        ])
                    </div>
                </div>
            </div>

            <div class="detail-list">
                <div><span>Log Type</span><strong>{{ $vehicleEvent->event_type }}</strong></div>
                <div><span>Status</span><strong>{{ $vehicleEvent->display_status === 'pending_details' ? 'Incomplete Record' : str_replace('_', ' ', ucfirst($vehicleEvent->display_status)) }}</strong></div>
                <div><span>Source</span><strong>{{ $vehicleEvent->event_origin_label }}</strong></div>
                <div><span>Plate</span><strong>{{ $vehicleEvent->plate_text ?: 'Incomplete record' }}</strong></div>
                <div><span>Vehicle</span><strong>{{ $vehicleEvent->vehicle_color ?: 'Pending color' }} {{ $vehicleEvent->display_vehicle_type }}</strong></div>
                <div><span>Category</span><strong>{{ $vehicleEvent->vehicle_category ? ucfirst(str_replace('_', ' ', $vehicleEvent->vehicle_category)) : 'N/A' }}</strong></div>
                <div><span>Station / Camera</span><strong>{{ $vehicleEvent->camera?->camera_name ?? 'No camera linked' }}</strong></div>
                <div><span>RFID Match</span><strong>{{ $vehicleEvent->rfidScanLog ? '#'.$vehicleEvent->rfidScanLog->id.' • '.$vehicleEvent->rfidScanLog->verificationLabel : 'No RFID scan linked' }}</strong></div>
                <div><span>Current State</span><strong>{{ $vehicleEvent->resulting_state_label }}</strong></div>
                <div><span>Time</span><strong>{{ $vehicleEvent->event_time->format('M d, Y h:i A') }}</strong></div>
                <div><span>Match</span><strong>{{ $vehicleEvent->match_display }}</strong></div>
                <div><span>Plate Confidence</span><strong>{{ $vehicleEvent->plate_confidence ?? 'N/A' }}</strong></div>
            </div>

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
                        @include('layouts.partials.help', [
                            'label' => 'Explain visual support',
                            'text' => 'Images and camera references support visual checking. They do not replace RFID as the main vehicle identifier.',
                        ])
                    </div>
                </div>
            </div>

            @if ($vehicleEvent->has_visual_evidence)
                <div class="image-grid">
                    <div>
                        <label>Vehicle Image</label>
                        <img src="{{ $vehicleEvent->vehicle_image_url }}" alt="Vehicle image" class="thumb thumb-large">
                    </div>
                    <div>
                        <label>Plate Image</label>
                        <img src="{{ $vehicleEvent->plate_image_url }}" alt="Plate image" class="thumb thumb-large">
                    </div>
                </div>
            @else
                <div class="empty-state empty-state-inline">
                    <h4>No visual evidence attached</h4>
                    <p>This record currently relies on RFID and registry data.</p>
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
                        @include('layouts.partials.help', [
                            'label' => 'Explain registered vehicle section',
                            'text' => 'When the plate matches the registry, the record can use the saved vehicle and RFID tag details automatically.',
                        ])
                    </div>
                </div>
            </div>

            @if ($vehicleEvent->vehicle)
                <div class="detail-list">
                    <div><span>Registered Plate</span><strong>{{ $vehicleEvent->vehicle->plate_number }}</strong></div>
                    <div><span>Owner / Assigned User</span><strong>{{ $vehicleEvent->vehicle->owner_name ?: 'N/A' }}</strong></div>
                    <div><span>Vehicle Type</span><strong>{{ $vehicleEvent->vehicle->vehicle_type }}</strong></div>
                    <div><span>Vehicle Color</span><strong>{{ $vehicleEvent->vehicle->vehicle_color }}</strong></div>
                    <div><span>Status</span><strong>{{ ucfirst($vehicleEvent->vehicle->status) }}</strong></div>
                    <div><span>RFID Tags</span><strong>{{ $vehicleEvent->vehicle->rfidTags->count() }}</strong></div>
                </div>

                @if ($vehicleEvent->vehicle->rfidTags->isNotEmpty())
                    <div class="badge-row">
                        @foreach ($vehicleEvent->vehicle->rfidTags as $tag)
                            <span class="badge {{ $tag->status === 'active' ? 'badge-matched' : 'badge-unmatched' }}">
                                {{ $tag->tag_uid }}
                            </span>
                        @endforeach
                    </div>
                @endif
            @else
                <div class="empty-state">
                    <h4>No registered vehicle linked</h4>
                    <p>Add or update the vehicle in the registry to strengthen RFID verification.</p>
                </div>
            @endif
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>RFID Match</h3>
                        @include('layouts.partials.help', [
                            'label' => 'Explain RFID match section',
                            'text' => 'A nearby RFID scan can be linked to the same vehicle log when the station, time, and registry details line up.',
                        ])
                    </div>
                </div>
            </div>

            @if ($vehicleEvent->rfidScanLog)
                    <div class="detail-list">
                        <div><span>RFID Log</span><strong>#{{ $vehicleEvent->rfidScanLog->id }}</strong></div>
                        <div><span>Tag UID</span><strong>{{ $vehicleEvent->rfidScanLog->tag_uid }}</strong></div>
                        <div><span>Result</span><strong>{{ $vehicleEvent->rfidScanLog->verificationLabel }}</strong></div>
                        <div><span>Station</span><strong>{{ $vehicleEvent->rfidScanLog->scanLocationLabel }} • {{ $vehicleEvent->rfidScanLog->scanDirectionLabel }}</strong></div>
                        <div><span>Event Type</span><strong>{{ $vehicleEvent->rfidScanLog->resolvedEventTypeLabel }}</strong></div>
                        <div><span>Current State</span><strong>{{ $vehicleEvent->rfidScanLog->resultingStateLabel }}</strong></div>
                        <div><span>Reader</span><strong>{{ $vehicleEvent->rfidScanLog->reader_name }}</strong></div>
                        <div><span>Time</span><strong>{{ $vehicleEvent->rfidScanLog->scan_time->format('M d, Y h:i A') }}</strong></div>
                    </div>

                @if ($vehicleEvent->rfidScanLog->notes)
                    <div class="mini-note">
                        <strong>RFID remarks</strong>
                        <p>{{ $vehicleEvent->rfidScanLog->notes }}</p>
                    </div>
                @endif
            @else
                <div class="empty-state">
                    <h4>No RFID match linked</h4>
                    <p>This record currently stands on its own.</p>
                </div>
            @endif
        </section>
    </div>

    @if ($vehicleEvent->event_status === 'pending_details' && auth()->user()?->isAdmin())
        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>Complete Incomplete Record</h3>
                        @include('layouts.partials.help', [
                            'label' => 'Explain complete record form',
                            'text' => 'Use this form to finish incomplete camera-supported records. ENTRY records open sessions and EXIT records run the matching workflow after save.',
                        ])
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('vehicle-events.complete', $vehicleEvent) }}" enctype="multipart/form-data" class="stack-form">
                @csrf
                @method('PUT')

                <div class="form-grid">
                    <div class="field">
                        <label for="plate_text">Plate</label>
                        <input id="plate_text" type="text" name="plate_text" value="{{ old('plate_text', $vehicleEvent->plate_text) }}" placeholder="ABC-1234" required>
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
                        <label for="vehicle_color">Vehicle Color</label>
                        <select id="vehicle_color" name="vehicle_color" required>
                            <option value="">Select color</option>
                            @foreach ($vehicleColors as $vehicleColor)
                                <option value="{{ $vehicleColor }}" @selected(old('vehicle_color', $vehicleEvent->vehicle_color) === $vehicleColor)>{{ $vehicleColor }}</option>
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
                    <a href="{{ route('vehicle-registry.index') }}" class="button button-secondary">Vehicle Registry</a>
                </div>
            </form>
        </section>
    @endif

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>Entry Match</h3>
                    @include('layouts.partials.help', [
                        'label' => 'Explain entry match section',
                        'text' => 'EXIT records can point to the entry that best matches the same vehicle movement.',
                    ])
                </div>
            </div>
        </div>

        @if ($vehicleEvent->matchedEntry)
            <div class="detail-list">
                <div><span>Entry Log</span><strong>#{{ $vehicleEvent->matchedEntry->id }}</strong></div>
                <div><span>Plate</span><strong>{{ $vehicleEvent->matchedEntry->plate_text }}</strong></div>
                <div><span>Vehicle</span><strong>{{ $vehicleEvent->matchedEntry->vehicle_color }} {{ $vehicleEvent->matchedEntry->display_vehicle_type }}</strong></div>
                <div><span>Camera</span><strong>{{ $vehicleEvent->matchedEntry->camera?->camera_name ?? 'N/A' }}</strong></div>
                <div><span>Time</span><strong>{{ $vehicleEvent->matchedEntry->event_time->format('M d, Y h:i A') }}</strong></div>
            </div>
        @else
            <div class="empty-state">
                <h4>No entry match linked</h4>
                <p>{{ $vehicleEvent->event_status === 'pending_details' ? 'Matching begins after this record is completed.' : 'This log does not currently point to an entry record.' }}</p>
            </div>
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
                    <div><span>Camera Source</span><strong>{{ $vehicleEvent->camera?->source_type ?? 'N/A' }} | {{ $vehicleEvent->camera?->source_value ?? 'N/A' }}</strong></div>
                    <div><span>Station / ROI</span><strong>{{ $vehicleEvent->roi_name ?: 'N/A' }}</strong></div>
                    <div><span>External Event Key</span><strong>{{ $vehicleEvent->external_event_key ?: 'Manual or RFID record' }}</strong></div>
                    <div><span>Details Completed</span><strong>{{ $vehicleEvent->details_completed_at?->format('M d, Y h:i A') ?? 'Not completed yet' }}</strong></div>
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
