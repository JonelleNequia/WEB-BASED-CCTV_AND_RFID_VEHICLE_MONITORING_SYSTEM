@extends('layouts.app')

@section('title', 'Settings | PHILCST Vehicle Monitoring')
@section('page-title', 'Settings')

@section('content')
    <x-page-header title="Settings">
        <x-slot:actions>
            <button type="submit" form="settings-form" class="button button-primary">Save Settings</button>
        </x-slot:actions>
    </x-page-header>

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>Camera and Integration Settings</h3>
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('settings.update') }}" class="stack-form" id="settings-form">
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

                </div>
            </section>

            {{-- Phase 4: Reader Configuration per station (replaces the "Reader Name (Simulated)" fields). --}}
            <section class="subpanel">
                <div class="panel-title-row">
                    <h4>Reader Configuration</h4>
<p class="field-help">NFC readers type the tag into the Station page. UHF Ethernet readers are configured here; the network listener is added in a later update. Simulated uses the RFID Desk.</p>                </div>

                <div class="camera-grid">
                    @foreach (['entrance' => 'Entrance', 'exit' => 'Exit'] as $station => $stationLabel)
                        @php($readerType = old("{$station}_reader_type", $settings["{$station}_reader_type"] ?? 'nfc'))
                        @php($readerIp = old("{$station}_reader_ip", $settings["{$station}_reader_ip"] ?? ''))
                        <article class="camera-card">
                            <div class="camera-card-head">
                                <div>
                                    <h4>{{ $stationLabel }} Reader</h4>
                                    <p>{{ $settings["{$station}_rfid_reader_name"] ?? $stationLabel.' Reader' }}</p>
                                </div>
                                <span class="chip {{ $readerType === 'uhf_ethernet' ? 'chip-soft' : 'chip-brand' }}">
                                    @switch($readerType)
                                        @case('uhf_ethernet')
                                            {{ $readerIp ? 'Not connected (listener pending)' : 'Set IP and port' }}
                                            @break
                                        @case('simulated')
                                            RFID Desk simulation
                                            @break
                                        @default
                                            Ready on Station page
                                    @endswitch
                                </span>
                            </div>

                            <div class="form-grid">
                                <div class="field">
                                    <label for="{{ $station }}_reader_type">Reader Type</label>
                                    <select id="{{ $station }}_reader_type" name="{{ $station }}_reader_type">
                                        <option value="nfc" @selected($readerType === 'nfc')>NFC (USB, Station page)</option>
                                        <option value="uhf_ethernet" @selected($readerType === 'uhf_ethernet')>UHF Ethernet (TCP/IP)</option>
                                        <option value="simulated" @selected($readerType === 'simulated')>Simulated (RFID Desk)</option>
                                    </select>
                                </div>

                                <div class="field">
                                    <label for="{{ $station }}_reader_ip">Reader IP</label>
                                    <input id="{{ $station }}_reader_ip" type="text" name="{{ $station }}_reader_ip" value="{{ $readerIp }}" placeholder="192.168.100.50">
                                    @error("{$station}_reader_ip")
                                        <span class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="field">
                                    <label for="{{ $station }}_reader_port">Port</label>
                                    <input id="{{ $station }}_reader_port" type="number" name="{{ $station }}_reader_port" value="{{ old("{$station}_reader_port", $settings["{$station}_reader_port"] ?? '') }}" min="1" max="65535" placeholder="6000">
                                    @error("{$station}_reader_port")
                                        <span class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="rfid_cooldown_seconds">Same-tag Cooldown (seconds)</label>
                        <input id="rfid_cooldown_seconds" type="number" name="rfid_cooldown_seconds" value="{{ old('rfid_cooldown_seconds', $settings['rfid_cooldown_seconds'] ?? 60) }}" min="0" max="3600">
                        <span class="field-help">Repeat reads of the same tag at the same station are ignored for this long.</span>
                        @error('rfid_cooldown_seconds')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>
                </div>
            </section>

            {{-- Phase 4: Guest Pass rules. --}}
            <section class="subpanel">
                <div class="panel-title-row">
                    <h4>Guest Pass</h4>
<p class="field-help">Default validity is prefilled on the Issue Guest Pass form. A visit becomes Overstay once it passes its valid-until time plus the grace period.</p>                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="guest_pass_validity_minutes">Default Validity (minutes)</label>
                        <input id="guest_pass_validity_minutes" type="number" name="guest_pass_validity_minutes" value="{{ old('guest_pass_validity_minutes', $settings['guest_pass_validity_minutes'] ?? 240) }}" min="15" max="1440">
                        @error('guest_pass_validity_minutes')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="guest_pass_overstay_grace_minutes">Overstay Threshold (grace minutes)</label>
                        <input id="guest_pass_overstay_grace_minutes" type="number" name="guest_pass_overstay_grace_minutes" value="{{ old('guest_pass_overstay_grace_minutes', $settings['guest_pass_overstay_grace_minutes'] ?? 0) }}" min="0" max="720">
                        @error('guest_pass_overstay_grace_minutes')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="guest_pass_require_id">Require ID Before Issuing</label>
                        <input type="hidden" name="guest_pass_require_id" value="0">
                        <label class="checkbox-row">
                            <input id="guest_pass_require_id" type="checkbox" name="guest_pass_require_id" value="1" @checked(old('guest_pass_require_id', $settings['guest_pass_require_id'] ?? '1') === '1')>
                            <span>Guard must record the ID left at the gate</span>
                        </label>
                    </div>
                </div>
            </section>

            <section class="subpanel">
                <div class="panel-title-row">
                    <h4>Camera Sources</h4>
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
                                    @error("camera_configs.$role.camera_name")
                                        <span class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="field">
                                    <label for="{{ $role }}_source_type">Source Type</label>
                                    <select id="{{ $role }}_source_type" name="camera_configs[{{ $role }}][source_type]" required>
                                        <option value="webcam" @selected(old("camera_configs.$role.source_type", $camera['source_type']) === 'webcam')>Webcam</option>
                                        <option value="rtsp" @selected(old("camera_configs.$role.source_type", $camera['source_type']) === 'rtsp')>RTSP</option>
                                        <option value="url" @selected(old("camera_configs.$role.source_type", $camera['source_type']) === 'url')>URL</option>
                                    </select>
                                    @error("camera_configs.$role.source_type")
                                        <span class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="field">
                                    <label for="{{ $role }}_source_value">Source Value</label>
                                    <input
                                        id="{{ $role }}_source_value"
                                        type="text"
                                        name="camera_configs[{{ $role }}][source_value]"
                                        value="{{ old("camera_configs.$role.source_value", $camera['source_value']) }}"
                                        placeholder="rtsp://192.168.1.50:554/stream1"
                                        required
                                    >
                                    <span class="field-help">Webcam uses a number like 0. RTSP must use the full rtsp:// camera URL.</span>
                                    @error("camera_configs.$role.source_value")
                                        <span class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="field">
                                    <label for="{{ $role }}_source_username">Username</label>
                                    <input id="{{ $role }}_source_username" type="text" name="camera_configs[{{ $role }}][source_username]" value="{{ old("camera_configs.$role.source_username", $camera['source_username']) }}">
                                    @error("camera_configs.$role.source_username")
                                        <span class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="field">
                                    <label for="{{ $role }}_source_password">Password</label>
                                    <input id="{{ $role }}_source_password" type="password" name="camera_configs[{{ $role }}][source_password]" value="{{ old("camera_configs.$role.source_password", $camera['source_password']) }}">
                                    @error("camera_configs.$role.source_password")
                                        <span class="field-error">{{ $message }}</span>
                                    @enderror
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
                            {{-- Phase 6: the key lives in .env, never shown or edited here. --}}
                            <label for="python_api_key">Shared Integration Key</label>
                            <input id="python_api_key" type="text" value="{{ $detectorKeySet ? 'Set in .env (DETECTOR_API_KEY)' : 'Not set: add DETECTOR_API_KEY to .env' }}" readonly>
                            <span class="field-help">Used by the Python detector and RFID readers. Change it in .env, then restart the server.</span>
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
