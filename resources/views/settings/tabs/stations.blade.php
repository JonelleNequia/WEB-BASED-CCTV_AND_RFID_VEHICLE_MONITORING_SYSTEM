{{--
    Plug-and-detect: the Devices panel comes first (cameras and UHF readers
    found on the network, assigned to a gate with one click). The manual
    reader address moved to the collapsed "Advanced" section.
--}}
@include('settings.partials.devices-panel')

{{-- UI Phase 2: one form per Settings tab, each with its own Save button. --}}
<section class="panel">
    <form method="POST" action="{{ route('settings.update') }}" class="stack-form" id="settings-form">
        @csrf
        @method('PUT')
        <input type="hidden" name="section" value="stations">

            {{-- Phase 1: gates instead of Entrance/Exit stations. Every gate records IN and OUT. --}}
            <section class="subpanel">
                <div class="panel-title-row">
                    <h4>Gates</h4>
                    <p class="field-help">Every gate records vehicles going IN and OUT. Each gate has its own camera, reader and calibration. Pick the camera and the UHF reader of each gate in Devices above (no IP to type).</p>
                </div>
                @error('gates')<span class="field-error">{{ $message }}</span>@enderror

                <div class="camera-grid">
                    @foreach ($gates as $gate)
                        @php
                            $field = fn (string $name) => "gates[{$gate->code}][{$name}]";
                            $old = fn (string $name, $default) => old("gates.{$gate->code}.{$name}", $default);
                            $readerType = $old('reader_type', $gate->reader_type);
                            $assignedReader = $devicesPayload['stations'][$gate->code]['reader'] ?? null;
                            $manual = (string) $old('reader_manual', $gate->reader_manual ? '1' : '0') === '1';
                            $transport = $old('reader_transport', $gate->reader_transport ?: 'tcp');
                            $active = (string) $old('is_active', $gate->is_active ? '1' : '0') === '1';
                        @endphp
                        <article class="camera-card" data-gate-card="{{ $gate->code }}">
                            <div class="camera-card-head">
                                <div>
                                    <h4>{{ $gate->name }}</h4>
                                    <p>{{ $gate->readerDisplayName() }} · <a href="{{ route('gates.kiosk', $gate->code) }}" target="_blank" rel="noopener">Open kiosk</a></p>
                                </div>
                                @if (! $gate->is_active)
                                    <x-badge tone="neutral" label="Inactive" />
                                @else
                                    @switch($readerType)
                                        @case('uhf_ethernet')
                                            @if ($manual)
                                                <x-badge tone="warning" label="Manual address" />
                                            @elseif ($assignedReader)
                                                <x-badge tone="success" :label="'Reader · '.$assignedReader['ip']" />
                                            @else
                                                <x-badge tone="warning" label="Pick a reader in Devices" />
                                            @endif
                                            @break
                                        @default
                                            <x-badge tone="neutral" label="Test Scan simulation" />
                                    @endswitch
                                @endif
                            </div>

                            <div class="form-grid">
                                <div class="field">
                                    <label for="gate_{{ $gate->code }}_name">Gate name</label>
                                    <input id="gate_{{ $gate->code }}_name" type="text" name="{{ $field('name') }}" value="{{ $old('name', $gate->name) }}" required maxlength="100">
                                    @error("gates.{$gate->code}.name")<span class="field-error">{{ $message }}</span>@enderror
                                </div>
                                <div class="field">
                                    <label for="gate_{{ $gate->code }}_reader_type">Reader type</label>
                                    <select id="gate_{{ $gate->code }}_reader_type" name="{{ $field('reader_type') }}">
                                        @foreach (\App\Models\Gate::READER_TYPES as $value => $text)
                                            <option value="{{ $value }}" @selected($readerType === $value)>{{ $text }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <label class="checkbox-row">
                                <input type="hidden" name="{{ $field('is_active') }}" value="0">
                                <input type="checkbox" name="{{ $field('is_active') }}" value="1" @checked($active)>
                                Active (shown on the Gate Monitor, recorded in logs)
                            </label>

                            {{-- Plug-and-detect: manual override, only when detection cannot be used. --}}
                            <details class="advanced-section" @if ($manual || $errors->hasAny(["gates.{$gate->code}.reader_ip", "gates.{$gate->code}.reader_port"])) open @endif>
                                <summary>Advanced: manual reader address</summary>
                                <p class="field-help">Use only if the reader cannot be detected. The assigned reader from Devices is used otherwise.</p>
                                <label class="checkbox-row">
                                    <input type="hidden" name="{{ $field('reader_manual') }}" value="0">
                                    <input type="checkbox" name="{{ $field('reader_manual') }}" value="1" @checked($manual)>
                                    Use this manual address instead
                                </label>
                                <div class="form-grid">
                                    <div class="field">
                                        <label for="gate_{{ $gate->code }}_reader_ip">Reader IP</label>
                                        <input id="gate_{{ $gate->code }}_reader_ip" type="text" name="{{ $field('reader_ip') }}" value="{{ $old('reader_ip', $gate->reader_ip) }}" inputmode="decimal" autocomplete="off">
                                        @error("gates.{$gate->code}.reader_ip")<span class="field-error">{{ $message }}</span>@enderror
                                    </div>
                                    <div class="field">
                                        <label for="gate_{{ $gate->code }}_reader_port">Port</label>
                                        <input id="gate_{{ $gate->code }}_reader_port" type="number" name="{{ $field('reader_port') }}" value="{{ $old('reader_port', $gate->reader_port) }}" min="1" max="65535">
                                        @error("gates.{$gate->code}.reader_port")<span class="field-error">{{ $message }}</span>@enderror
                                    </div>
                                    <div class="field">
                                        <label for="gate_{{ $gate->code }}_reader_transport">Protocol</label>
                                        <select id="gate_{{ $gate->code }}_reader_transport" name="{{ $field('reader_transport') }}">
                                            <option value="tcp" @selected($transport === 'tcp')>TCP</option>
                                            <option value="udp" @selected($transport === 'udp')>UDP</option>
                                        </select>
                                    </div>
                                </div>
                            </details>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="subpanel">
                <div class="form-grid">
                    <div class="field">
                        <label for="rfid_cooldown_seconds">Same-tag Cooldown (seconds)</label>
                        <input id="rfid_cooldown_seconds" type="number" name="rfid_cooldown_seconds" value="{{ old('rfid_cooldown_seconds', $settings['rfid_cooldown_seconds'] ?? 60) }}" min="10" max="3600">
                        <span class="field-help">Repeat reads of the same tag at the same gate are ignored for this long (at least 10 s). An unknown tag is reported once per cooldown.</span>
                        @error('rfid_cooldown_seconds')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>
                    {{-- Phase 3: RFID + camera fusion window. --}}
                    <div class="field">
                        <label for="rfid_lookback_seconds">Tag read before the crossing (seconds)</label>
                        <input id="rfid_lookback_seconds" type="number" name="rfid_lookback_seconds" value="{{ old('rfid_lookback_seconds', $settings['rfid_lookback_seconds'] ?? 10) }}" min="1" max="15">
                        <span class="field-help">The UHF reader reads the tag while the vehicle approaches. A read this long before the camera sees the vehicle cross still belongs to it.</span>
                        @error('rfid_lookback_seconds')<span class="field-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="field">
                        <label for="rfid_lookahead_seconds">Tag read after the crossing (seconds)</label>
                        <input id="rfid_lookahead_seconds" type="number" name="rfid_lookahead_seconds" value="{{ old('rfid_lookahead_seconds', $settings['rfid_lookahead_seconds'] ?? 4) }}" min="1" max="10">
                        <span class="field-help">How long the camera waits for a tag read after the crossing before it records the vehicle as an Unregistered Visitor.</span>
                        @error('rfid_lookahead_seconds')<span class="field-error">{{ $message }}</span>@enderror
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
            <button type="submit" class="button button-primary">Save Gates & Readers</button>
        </div>
    </form>
</section>

{{-- Phase 1: add a gate (e.g. Gate 2's hardware at deployment, or a third gate). --}}
<section class="panel">
    <form method="POST" action="{{ route('settings.gates.store') }}" class="inline-form add-gate-form">
        @csrf
        <div class="field">
            <label for="new_gate_name">Add a gate</label>
            <input id="new_gate_name" type="text" name="name" placeholder="Gate {{ $gates->count() + 1 }}" maxlength="100">
        </div>
        <button type="submit" class="button button-secondary">Add gate</button>
        <p class="field-help">The new gate gets a kiosk, a camera slot and a reader slot. Assign its devices in Devices, then draw its zone and line in Calibration.</p>
    </form>
</section>
