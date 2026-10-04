{{--
    Plug-and-detect: live list of cameras and UHF readers on the network.
    Rendered by public/js/devices-panel.js from the same JSON the panel polls
    (settings.devices.index), so it updates by itself after a scan.
--}}
<section class="panel devices-panel" data-devices-panel
         data-index-url="{{ route('settings.devices.index') }}"
         data-scan-url="{{ route('settings.devices.scan') }}"
         data-identify-url="{{ route('settings.devices.identify') }}"
         data-find-url="{{ route('settings.devices.find') }}"
         data-unassign-url="{{ route('settings.devices.unassign') }}"
         data-acknowledge-url="{{ route('settings.devices.acknowledge') }}">
    <div class="panel-header panel-header-modern">
        <div>
            <h2 class="panel-title">Devices</h2>
            <p class="text-muted devices-intro">Cameras and UHF readers on this PC's network. No IP to type.</p>
        </div>
        <div class="button-row">
            <x-live-indicator />
            <button type="button" class="button button-primary button-sm" data-devices-find
                    title="Record the network, plug in the reader, and see what appears">Find my reader</button>
            <button type="button" class="button button-secondary button-sm" data-devices-identify
                    title="Listen to every device while you hold a UHF tag near the reader">Identify reader</button>
            <button type="button" class="button button-secondary button-sm" data-devices-scan>Scan again</button>
        </div>
    </div>

    {{-- UI Phase 4: one clear banner when there is no LAN (Wi-Fi only). --}}
    <div class="devices-banner" data-devices-banner role="status" hidden></div>

    {{-- Find my reader: before/after wizard. --}}
    <div class="devices-find" data-devices-find-box role="status" aria-live="polite" hidden></div>

    {{-- Identify reader: live progress and result. --}}
    <div class="devices-identify" data-devices-identify-box role="status" aria-live="polite" hidden></div>

    {{-- UI Phase 4: the device list first, then what each gate uses. --}}
    <div class="devices-list" data-devices-list></div>

    <h3 class="devices-subtitle">Assigned to gates</h3>
    <div class="devices-stations" data-devices-stations></div>

    <div class="devices-network" data-devices-network role="status" aria-live="polite"></div>

    {{-- Why a scan found nothing: interfaces scanned, what the OS sees, warnings. --}}
    <details class="advanced-section devices-diagnostics" data-devices-diagnostics-box>
        <summary>Diagnostics <span data-devices-diagnostics-count></span></summary>
        <div data-devices-diagnostics></div>
    </details>

    <details class="advanced-section devices-other">
        <summary>Other devices on the network (<span data-devices-other-count>0</span>)</summary>
        <label class="checkbox-row field-help">
            <input type="checkbox" data-devices-show-old> Also show devices from networks this PC is no longer on (<span data-devices-old-count>0</span>)
        </label>
        <div data-devices-other></div>
    </details>

    <p class="field-help devices-tip">
        Tip: set cameras and readers to <strong>DHCP (automatic IP)</strong>; this page follows them when their address changes.
    </p>

    <script id="devices-panel-data" type="application/json">{!! json_encode($devicesPayload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
</section>

<x-drawer id="device-drawer" title="Device" size="md">
    <div data-device-drawer-body></div>
</x-drawer>

@push('scripts')
    <script src="{{ asset('js/devices-panel.js') }}"></script>
@endpush
