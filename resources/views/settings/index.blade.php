@extends('layouts.app')

@section('title', 'Settings | PHILCST Parking Monitoring')
@section('page-title', 'Settings')
@section('page-description', 'Adjust local parking workflow, station labels, camera sources, and optional advanced settings.')

@section('content')
    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">System Settings</span>
            <h3>Local deployment configuration</h3>
            <div class="inline-status-list">
                <span class="chip chip-brand">Offline Local</span>
                <span class="chip chip-soft">Parking-focused RFID workflow</span>
            </div>
        </div>

        <div class="hero-panel-actions">
            <a href="{{ route('rfid-scans.index') }}" class="button button-secondary">RFID Desk</a>
            <a href="{{ route('guest-observations.index') }}" class="button button-secondary">Guest Monitoring</a>
            <a href="{{ route('monitoring.index') }}" class="button button-secondary">Camera Monitoring</a>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>Camera and Integration Settings</h3>
                    @include('layouts.partials.help', [
                        'label' => 'Explain operational settings',
                        'text' => 'Camera sources and station labels stay editable while operational controls are temporarily hidden.',
                    ])
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('settings.update') }}" class="stack-form">
            @csrf
            @method('PUT')

            <input type="hidden" name="deployment_mode" value="{{ old('deployment_mode', $settings['deployment_mode']) }}">
            <input type="hidden" name="operating_mode" value="{{ old('operating_mode', $settings['operating_mode']) }}">
            <input type="hidden" name="rfid_simulation_mode" value="{{ old('rfid_simulation_mode', $settings['rfid_simulation_mode']) }}">
            <input type="hidden" name="cctv_simulation_mode" value="{{ old('cctv_simulation_mode', $settings['cctv_simulation_mode']) }}">
            <input type="hidden" name="matching_threshold_matched" value="{{ old('matching_threshold_matched', $settings['matching_threshold_matched']) }}">
            <input type="hidden" name="matching_threshold_manual_review" value="{{ old('matching_threshold_manual_review', $settings['matching_threshold_manual_review']) }}">
            <input type="hidden" name="retention_days" value="{{ old('retention_days', $settings['retention_days']) }}">

            <section class="subpanel">
                <div class="panel-title-row">
                    <h4>Station Labels</h4>
                    @include('layouts.partials.help', [
                        'label' => 'Explain station labels',
                        'text' => 'These labels appear on the entrance and exit station screens and on RFID reader references.',
                    ])
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="entrance_portal_label">Entrance Label</label>
                        <input id="entrance_portal_label" type="text" name="entrance_portal_label" value="{{ old('entrance_portal_label', $settings['entrance_portal_label']) }}" required>
                    </div>

                    <div class="field">
                        <label for="exit_portal_label">Exit Label</label>
                        <input id="exit_portal_label" type="text" name="exit_portal_label" value="{{ old('exit_portal_label', $settings['exit_portal_label']) }}" required>
                    </div>

                    <div class="field">
                        <label for="entrance_rfid_reader_name">Entrance Reader Name</label>
                        <input id="entrance_rfid_reader_name" type="text" name="entrance_rfid_reader_name" value="{{ old('entrance_rfid_reader_name', $settings['entrance_rfid_reader_name']) }}" required>
                    </div>

                    <div class="field">
                        <label for="exit_rfid_reader_name">Exit Reader Name</label>
                        <input id="exit_rfid_reader_name" type="text" name="exit_rfid_reader_name" value="{{ old('exit_rfid_reader_name', $settings['exit_rfid_reader_name']) }}" required>
                    </div>
                </div>
            </section>

            <section class="subpanel">
                <div class="panel-title-row">
                    <h4>Camera Sources</h4>
                    @include('layouts.partials.help', [
                        'label' => 'Explain camera sources',
                        'text' => 'These sources support parking observation and guest monitoring. They do not replace RFID as the main identifier for recurring vehicles.',
                    ])
                </div>

                <div class="camera-grid">
                    @foreach (['entrance' => 'Entrance Camera', 'exit' => 'Exit Camera'] as $role => $label)
                        @php($camera = $cameraConfigs[$role])
                        <article class="camera-card">
                            <div class="camera-card-head">
                                <div>
                            <h4>{{ $label }}</h4>
                            <p>{{ ucfirst($role) }} monitoring feed</p>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="field">
                                    <label for="{{ $role }}_camera_name">Camera Label</label>
                                    <input id="{{ $role }}_camera_name" type="text" name="camera_configs[{{ $role }}][camera_name]" value="{{ old("camera_configs.$role.camera_name", $camera['camera_name']) }}" required>
                                </div>

                                <div class="field">
                                    <label for="{{ $role }}_source_type">Source Type</label>
                                    <select id="{{ $role }}_source_type" name="camera_configs[{{ $role }}][source_type]" required>
                                        <option value="webcam" @selected(old("camera_configs.$role.source_type", $camera['source_type']) === 'webcam')>Webcam</option>
                                        <option value="rtsp" @selected(old("camera_configs.$role.source_type", $camera['source_type']) === 'rtsp')>RTSP</option>
                                        <option value="url" @selected(old("camera_configs.$role.source_type", $camera['source_type']) === 'url')>URL</option>
                                    </select>
                                </div>

                                <div class="field">
                                    <label for="{{ $role }}_source_value">Source Value</label>
                                    <input id="{{ $role }}_source_value" type="text" name="camera_configs[{{ $role }}][source_value]" value="{{ old("camera_configs.$role.source_value", $camera['source_value']) }}" required>
                                </div>

                                <div class="field">
                                    <label for="{{ $role }}_source_username">Username</label>
                                    <input id="{{ $role }}_source_username" type="text" name="camera_configs[{{ $role }}][source_username]" value="{{ old("camera_configs.$role.source_username", $camera['source_username']) }}">
                                </div>

                                <div class="field">
                                    <label for="{{ $role }}_source_password">Password</label>
                                    <input id="{{ $role }}_source_password" type="password" name="camera_configs[{{ $role }}][source_password]" value="{{ old("camera_configs.$role.source_password", $camera['source_password']) }}">
                                </div>

                                <div class="field span-full">
                                    <label>Saved Browser Device</label>
                                    <input type="text" value="{{ $camera['browser_label'] ?: 'No saved browser device yet.' }}" readonly>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            <details class="details-card">
                <summary>
                    <span>Advanced Integration / Optional</span>
                    <span class="chip chip-soft">Support only</span>
                </summary>

                <div class="details-card-body">
                    <div class="form-grid">
                        <div class="field">
                            <label for="python_api_key">Shared Integration Key</label>
                            <input id="python_api_key" type="text" name="python_api_key" value="{{ old('python_api_key', $settings['python_api_key']) }}" placeholder="Optional">
                        </div>

                        <div class="field">
                            <label for="camera_source_placeholder">Future Camera Placeholder</label>
                            <input id="camera_source_placeholder" type="text" name="camera_source_placeholder" value="{{ old('camera_source_placeholder', $settings['camera_source_placeholder']) }}">
                        </div>
                    </div>

                    <div class="mini-note">
                        <strong>Local evidence storage is active.</strong>
                        <p>Snapshots and scan evidence are saved locally and can be viewed or downloaded from records.</p>
                    </div>
                </div>
            </details>

            <div class="button-row">
                <button type="submit" class="button button-primary">Save Settings</button>
            </div>
        </form>
    </section>
@endsection
