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
                    <h4>Reader Configuration</h4>
<p class="field-help">NFC readers type the tag into the Station page. UHF Ethernet readers are configured here; the network listener is added in a later update. Simulated uses Settings › Test Scan.</p>                </div>

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
                                            Test Scan simulation
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
                                        <option value="simulated" @selected($readerType === 'simulated')>Simulated (Test Scan)</option>
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
                            <span class="field-help">Used by the Python detector and RFID readers. Change it in .env, then restart the server.</span>
                        </div>
                </div>
            </section>

        <div class="button-row button-row-end">
            <button type="submit" class="button button-primary">Save Stations & Readers</button>
        </div>
    </form>
</section>
