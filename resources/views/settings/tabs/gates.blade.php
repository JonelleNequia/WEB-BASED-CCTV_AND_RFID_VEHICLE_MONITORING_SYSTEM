{{--
    B1 (Settings › Gates): one card per gate with its Camera, RFID Reader and
    Detection zone. Plain words, a status dot and one line each; the "⋯"
    menus rename, test or remove. Technical settings are in Advanced.
--}}
<div class="gate-setup-grid">
    @foreach ($gateCards as $gate)
        <article class="gate-setup-card" data-gate-card="{{ $gate['code'] }}">
            <header class="gate-setup-head">
                <h2>{{ $gate['name'] }}</h2>
                @unless ($gate['active'])
                    <x-badge tone="neutral" label="Inactive" />
                @endunless
                <a href="{{ $gate['kiosk_url'] }}" target="_blank" rel="noopener" class="gate-setup-kiosk">Open kiosk</a>
            </header>

            {{-- Camera --}}
            <section class="setup-part" data-part="camera">
                <h3 class="setup-part-title">Camera</h3>
                @if (! $gate['camera'])
                    <a href="{{ route('settings.index', ['tab' => 'devices', 'gate' => $gate['code'], 'role' => 'camera']) }}" class="add-device-button" data-add-device="camera" data-gate="{{ $gate['code'] }}">+ Add camera</a>
                @else
                    @php($camera = $gate['camera'])
                    <div class="setup-device">
                        <div class="setup-preview">
                            @if ($camera['online'] && $camera['preview_url'])
                                <img src="{{ $camera['preview_url'] }}" alt="{{ $gate['name'] }} camera" loading="lazy" data-setup-preview>
                            @else
                                <span class="setup-preview-off" aria-hidden="true">
                                    <svg viewBox="0 0 24 24"><path d="M3.3 2.3a1 1 0 0 0-1.4 1.4L4.2 6H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h11.2l4.5 4.5a1 1 0 0 0 1.4-1.4zM16 15.2 6.8 6H14a2 2 0 0 1 2 2zm2-1.4V9.6l3.4-2.3A1 1 0 0 1 23 8.1v7.8a1 1 0 0 1-1.6.8z"/></svg>
                                </span>
                            @endif
                        </div>
                        <div class="setup-device-text">
                            <strong>{{ $camera['name'] }}</strong>
                            <span class="status-line"><span @class(['status-dot', 'is-online' => $camera['state'] === 'online', 'is-warning' => $camera['state'] === 'starting']) aria-hidden="true"></span>{{ $camera['line'] }}</span>
                            @if ($camera['next_step'] !== '')
                                <span class="next-step">→ {{ $camera['next_step'] }}</span>
                            @endif
                            <small class="text-muted">{{ $camera['source'] }}</small>
                        </div>
                        <details class="menu row-menu">
                            <summary class="button button-secondary" aria-label="Camera actions for {{ $gate['name'] }}">⋯</summary>
                            <div class="menu-panel" role="menu">
                                <button type="button" role="menuitem" data-setup-dialog="camera-name" data-action-url="{{ route('settings.gate.camera.name', $gate['code']) }}" data-value="{{ $camera['name'] }}">Rename</button>
                                <button type="button" role="menuitem" data-setup-dialog="camera-login" data-action-url="{{ route('settings.gate.camera.login', $gate['code']) }}" data-value="{{ $camera['username'] }}">Change login</button>
                                @if ($camera['can_test'])
                                    <button type="button" role="menuitem" data-camera-test="{{ route('settings.gate.camera.test', $gate['code']) }}">Test</button>
                                @endif
                                <form method="POST" action="{{ route('settings.gate.camera.remove', $gate['code']) }}" data-confirm-title="Remove the camera?" data-confirm-label="Remove camera" data-confirm="Remove the camera from {{ $gate['name'] }}? Vehicles at this gate are not detected until you add a camera again. The detection zone is kept.">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" role="menuitem" class="menu-item-danger">Remove…</button>
                                </form>
                            </div>
                        </details>
                    </div>
                @endif
            </section>

            {{-- RFID reader --}}
            <section class="setup-part" data-part="reader">
                <h3 class="setup-part-title">RFID Reader</h3>
                @if (! $gate['reader'])
                    <a href="{{ route('settings.index', ['tab' => 'devices', 'gate' => $gate['code'], 'role' => 'reader']) }}" class="add-device-button" data-add-device="reader" data-gate="{{ $gate['code'] }}">+ Add reader</a>
                @else
                    @php($reader = $gate['reader'])
                    <div class="setup-device">
                        <div class="setup-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M5 5a1 1 0 0 1 1 1v12a1 1 0 1 1-2 0V6a1 1 0 0 1 1-1m4 2a1 1 0 0 1 1 1v8a1 1 0 1 1-2 0V8a1 1 0 0 1 1-1m4-2a1 1 0 0 1 1 1v12a1 1 0 1 1-2 0V6a1 1 0 0 1 1-1m4 3a1 1 0 0 1 1 1v6a1 1 0 1 1-2 0V9a1 1 0 0 1 1-1"/></svg>
                        </div>
                        <div class="setup-device-text">
                            <strong>{{ $reader['name'] }}</strong>
                            <span class="status-line"><span @class(['status-dot', 'is-online' => $reader['state'] === 'online', 'is-warning' => $reader['state'] === 'starting']) aria-hidden="true"></span>{{ $reader['line'] }}</span>
                            @if ($reader['next_step'] !== '')
                                <span class="next-step">→ {{ $reader['next_step'] }}</span>
                            @endif
                            @if ($reader['rfid_only'])
                                <span class="next-step" data-rfid-only>RFID only (camera offline): registered tags are recorded without the camera, IN/OUT from the vehicle's state.</span>
                            @endif
                            <small class="text-muted">{{ $reader['last_tag'] ? 'Last tag '.$reader['last_tag'].' at '.$reader['last_tag_time'] : 'No tag read yet' }}{{ $reader['manual'] ? ' · added by hand (Advanced)' : '' }}</small>
                        </div>
                        <details class="menu row-menu">
                            <summary class="button button-secondary" aria-label="Reader actions for {{ $gate['name'] }}">⋯</summary>
                            <div class="menu-panel" role="menu">
                                <button type="button" role="menuitem" data-setup-dialog="reader-name" data-action-url="{{ route('settings.gate.reader.name', $gate['code']) }}" data-value="{{ $reader['name'] }}">Rename</button>
                                <button type="button" role="menuitem" data-reader-test="{{ $gate['code'] }}" data-label="{{ $gate['name'] }}">Test</button>
                                <form method="POST" action="{{ route('settings.gate.reader.remove', $gate['code']) }}" data-confirm-title="Remove the reader?" data-confirm-label="Remove reader" data-confirm="Remove the RFID reader from {{ $gate['name'] }}? Tags at this gate are not read until you add a reader again.">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" role="menuitem" class="menu-item-danger">Remove…</button>
                                </form>
                            </div>
                        </details>
                    </div>
                @endif
            </section>

            {{-- Detection zone --}}
            <section class="setup-part" data-part="zone">
                <h3 class="setup-part-title">Detection zone</h3>
                <div class="setup-device">
                    <div class="setup-preview zone-preview">
                        @if ($gate['zone']['ready'])
                            <img src="{{ $gate['zone']['frame_url'] }}" alt="" loading="lazy" onerror="this.remove()">
                            <svg viewBox="0 0 1 1" preserveAspectRatio="none" aria-hidden="true">
                                <polygon points="{{ $gate['zone']['points'] }}" class="zone-area" />
                                <line x1="{{ $gate['zone']['line']['x1'] ?? 0 }}" y1="{{ $gate['zone']['line']['y1'] ?? 0 }}" x2="{{ $gate['zone']['line']['x2'] ?? 0 }}" y2="{{ $gate['zone']['line']['y2'] ?? 0 }}" class="zone-line" />
                            </svg>
                        @else
                            <span class="setup-preview-off" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M6 4a2 2 0 0 0-2 2v3a1 1 0 1 0 2 0V6h3a1 1 0 1 0 0-2zm9 0a1 1 0 1 0 0 2h3v3a1 1 0 1 0 2 0V6a2 2 0 0 0-2-2zm4 11a1 1 0 0 0-1 1v3h-3a1 1 0 1 0 0 2h3a2 2 0 0 0 2-2v-3a1 1 0 0 0-1-1M5 15a1 1 0 0 0-1 1v3a2 2 0 0 0 2 2h3a1 1 0 1 0 0-2H6v-3a1 1 0 0 0-1-1"/></svg>
                            </span>
                        @endif
                    </div>
                    <div class="setup-device-text">
                        <strong>{{ $gate['zone']['ready'] ? 'Zone and line set' : 'Not set up yet' }}</strong>
                        <span class="status-line"><span @class(['status-dot', 'is-online' => $gate['zone']['ready'], 'is-warning' => ! $gate['zone']['ready']]) aria-hidden="true"></span>{{ $gate['zone']['ready'] ? 'Vehicles are counted when they cross the line.' : 'Vehicles are not counted yet.' }}</span>
                        @unless ($gate['zone']['ready'])
                            <span class="next-step">→ Draw where vehicles pass and the line they cross.</span>
                        @endunless
                    </div>
                    <a href="{{ $gate['zone']['setup_url'] }}" class="button {{ $gate['zone']['ready'] ? 'button-secondary' : 'button-primary' }}">{{ $gate['zone']['ready'] ? 'Edit' : 'Set up' }}</a>
                </div>
            </section>
        </article>
    @endforeach

    {{-- Phase 1: another gate gets a kiosk, a camera slot and a reader slot. --}}
    <form method="POST" action="{{ route('settings.gates.store') }}" class="gate-setup-add">
        @csrf
        <label for="new_gate_name" class="sr-only">New gate name</label>
        <input id="new_gate_name" type="text" name="name" placeholder="Gate {{ count($gateCards) + 1 }}" maxlength="100">
        <button type="submit" class="add-device-button">+ Add gate</button>
    </form>
</div>

{{-- B2: the add-device wizard ("+ Add camera" / "+ Add reader"), drawn by public/js/add-device.js. --}}
<x-modal id="add-device-modal" title="Add device" size="md">
    <div class="add-device" data-add-device-box aria-live="polite"></div>
</x-modal>
<script id="add-device-data" type="application/json">{!! json_encode([
    'indexUrl' => route('settings.devices.index'),
    'scanUrl' => route('settings.devices.scan'),
    'uhfStatusUrl' => route('devices.uhf-status'),
    'gatesStateUrl' => route('gates.state'),
    'manualUrl' => route('settings.index', ['tab' => 'manual']),
    'gates' => collect($gateCards)->mapWithKeys(fn (array $gate): array => [$gate['code'] => ['name' => $gate['name'], 'stream_url' => $gate['stream_url']]]),
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>

{{-- Dialogs behind the "⋯" menus (filled by public/js/gate-setup.js). --}}
<x-modal id="setup-camera-name" title="Rename camera" size="sm">
    <form method="POST" action="" class="stack-form" data-setup-form>
        @csrf
        @method('PATCH')
        <div class="field">
            <label for="setup_camera_name">Camera name</label>
            <input id="setup_camera_name" type="text" name="camera_name" maxlength="100" required data-setup-value>
        </div>
        <div class="button-row button-row-end">
            <button type="button" class="button button-secondary" data-drawer-close>Cancel</button>
            <button type="submit" class="button button-primary">Save</button>
        </div>
    </form>
</x-modal>

<x-modal id="setup-camera-login" title="Change camera login" size="sm">
    <form method="POST" action="" class="stack-form" data-setup-form autocomplete="off">
        @csrf
        @method('PATCH')
        <p class="field-help">The username and password of the camera itself (printed on it or set when it was installed). The password is saved encrypted and never shown again.</p>
        <div class="field">
            <label for="setup_camera_username">Username</label>
            <input id="setup_camera_username" type="text" name="source_username" maxlength="255" required data-setup-value autocomplete="off">
        </div>
        <div class="field">
            <label for="setup_camera_password">Password</label>
            <input id="setup_camera_password" type="password" name="source_password" maxlength="255" autocomplete="new-password" placeholder="Leave blank to keep the saved one">
        </div>
        <div class="button-row button-row-end">
            <button type="button" class="button button-secondary" data-drawer-close>Cancel</button>
            <button type="submit" class="button button-primary">Save and test</button>
        </div>
    </form>
</x-modal>

<x-modal id="setup-reader-name" title="Rename reader" size="sm">
    <form method="POST" action="" class="stack-form" data-setup-form>
        @csrf
        @method('PATCH')
        <div class="field">
            <label for="setup_reader_name">Reader name</label>
            <input id="setup_reader_name" type="text" name="reader_name" maxlength="100" required data-setup-value>
        </div>
        <div class="button-row button-row-end">
            <button type="button" class="button button-secondary" data-drawer-close>Cancel</button>
            <button type="submit" class="button button-primary">Save</button>
        </div>
    </form>
</x-modal>

<x-modal id="setup-reader-test" title="Test the RFID reader" size="sm">
    <div class="reader-test" data-reader-test-box data-status-url="{{ route('devices.uhf-status') }}">
        <p class="reader-test-step"><strong>Hold an RFID tag near the reader of <span data-reader-test-gate></span>.</strong></p>
        <p class="reader-test-result" data-reader-test-result role="status" aria-live="polite">Waiting for a tag…</p>
    </div>
    <div class="button-row button-row-end">
        <button type="button" class="button button-secondary" data-drawer-close>Close</button>
    </div>
</x-modal>

@push('scripts')
    <script src="{{ asset('js/gate-setup.js') }}"></script>
    <script src="{{ asset('js/add-device.js') }}"></script>
@endpush
