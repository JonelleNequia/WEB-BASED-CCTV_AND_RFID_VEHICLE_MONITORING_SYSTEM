{{--
    Plug-and-detect: the Devices panel comes first (cameras and UHF readers
    found on the network, assigned to a station with one click). The manual
    reader address moved to the collapsed "Advanced" section.
--}}
@include('settings.partials.devices-panel')

{{-- UI Phase 2: one form per Settings tab, each with its own Save button. --}}
<section class="panel">
    <form method="POST" action="{{ route('settings.update') }}" class="stack-form" id="settings-form">
        @csrf
        @method('PUT')
        <input type="hidden" name="section" value="stations">

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

            <section class="subpanel">
                <div class="panel-title-row">
                    <h4>Reader Type</h4>
                    <p class="field-help">NFC readers type the tag into the Station page. A UHF reader is picked in Devices above (no IP to type). Simulated uses Settings › Test Scan.</p>
                </div>

                <div class="camera-grid">
                    @foreach (['entrance' => 'Entrance', 'exit' => 'Exit'] as $station => $stationLabel)
                        @php
                            $readerType = old("{$station}_reader_type", $settings["{$station}_reader_type"] ?? 'nfc');
                            $assignedReader = $devicesPayload['stations'][$station]['reader'] ?? null;
                            $manual = old("{$station}_reader_manual", $settings["{$station}_reader_manual"] ?? '0') === '1';
                            $transport = old("{$station}_reader_transport", $settings["{$station}_reader_transport"] ?? 'tcp');
                        @endphp
                        <article class="camera-card">
                            <div class="camera-card-head">
                                <div>
                                    <h4>{{ $stationLabel }} Reader</h4>
                                    <p>{{ $settings["{$station}_rfid_reader_name"] ?? $stationLabel.' Reader' }}</p>
                                </div>
                                @switch($readerType)
                                    @case('uhf_ethernet')
                                        @if ($manual)
                                            <x-badge tone="warning" label="Manual address" />
                                        @elseif ($assignedReader)
                                            <x-badge tone="success" :label="'Assigned · '.$assignedReader['ip']" />
                                        @else
                                            <x-badge tone="warning" label="Pick a reader in Devices" />
                                        @endif
                                        @break
                                    @case('simulated')
                                        <x-badge tone="neutral" label="Test Scan simulation" />
                                        @break
                                    @default
                                        <x-badge tone="success" label="Ready on Station page" />
                                @endswitch
                            </div>

                            <div class="form-grid">
                                <div class="field">
                                    <label for="{{ $station }}_reader_type">Reader Type</label>
                                    <select id="{{ $station }}_reader_type" name="{{ $station }}_reader_type">
                                        <option value="nfc" @selected($readerType === 'nfc')>NFC (USB, Station page)</option>
                                        <option value="uhf_ethernet" @selected($readerType === 'uhf_ethernet')>UHF (network reader)</option>
                                        <option value="simulated" @selected($readerType === 'simulated')>Simulated (Test Scan)</option>
                                    </select>
                                </div>
                            </div>

                            {{-- Plug-and-detect: manual override, only when detection cannot be used. --}}
                            <details class="advanced-section" @if ($manual || $errors->hasAny(["{$station}_reader_ip", "{$station}_reader_port"])) open @endif>
                                <summary>Advanced: manual reader address</summary>
                                <p class="field-help">Use only if the reader cannot be detected. The assigned reader from Devices is used otherwise.</p>
                                <label class="checkbox-row">
                                    <input type="hidden" name="{{ $station }}_reader_manual" value="0">
                                    <input type="checkbox" name="{{ $station }}_reader_manual" value="1" @checked($manual)>
                                    Use this manual address instead
                                </label>
                                <div class="form-grid">
                                    <div class="field">
                                        <label for="{{ $station }}_reader_ip">Reader IP</label>
                                        <input id="{{ $station }}_reader_ip" type="text" name="{{ $station }}_reader_ip" value="{{ old("{$station}_reader_ip", $settings["{$station}_reader_ip"] ?? '') }}" inputmode="decimal" autocomplete="off">
                                        @error("{$station}_reader_ip")
                                            <span class="field-error">{{ $message }}</span>
                                        @enderror
                                    </div>

                                    <div class="field">
                                        <label for="{{ $station }}_reader_port">Port</label>
                                        <input id="{{ $station }}_reader_port" type="number" name="{{ $station }}_reader_port" value="{{ old("{$station}_reader_port", $settings["{$station}_reader_port"] ?? '') }}" min="1" max="65535">
                                        @error("{$station}_reader_port")
                                            <span class="field-error">{{ $message }}</span>
                                        @enderror
                                    </div>

                                    <div class="field">
                                        <label for="{{ $station }}_reader_transport">Protocol</label>
                                        <select id="{{ $station }}_reader_transport" name="{{ $station }}_reader_transport">
                                            <option value="tcp" @selected($transport === 'tcp')>TCP</option>
                                            <option value="udp" @selected($transport === 'udp')>UDP</option>
                                        </select>
                                    </div>
                                </div>
                            </details>
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

            {{-- Phase 6: used by the Python detector and UHF/RFID adapters; lives in .env. --}}
            <section class="subpanel">
                <div class="panel-title-row">
                    <h4>Integration Key</h4>
                </div>
                <div class="form-grid">
                        <div class="field">
                            {{-- Phase 6: the key lives in .env, never shown or edited here. --}}
                            <label for="python_api_key">Shared Integration Key</label>
                            <input id="python_api_key" type="text" value="{{ $detectorKeySet ? 'Set in .env (DETECTOR_API_KEY)' : 'Not set: add DETECTOR_API_KEY to .env' }}" readonly>
                            <span class="field-help">Used by the Python detector, the device service and RFID readers. Change it in .env, then restart the server.</span>
                        </div>
                </div>
            </section>

        <div class="button-row button-row-end">
            <button type="submit" class="button button-primary">Save Stations & Readers</button>
        </div>
    </form>
</section>
