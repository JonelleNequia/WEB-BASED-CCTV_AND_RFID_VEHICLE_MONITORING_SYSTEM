{{--
    Plug-and-detect: live list of cameras and UHF readers on the network.
    Rendered by public/js/devices-panel.js from the same JSON the panel polls
    (settings.devices.index), so it updates by itself after a scan.
--}}
<section class="panel devices-panel" data-devices-panel
         data-index-url="{{ route('settings.devices.index') }}"
         data-scan-url="{{ route('settings.devices.scan') }}"
         data-unassign-url="{{ route('settings.devices.unassign') }}"
         data-acknowledge-url="{{ route('settings.devices.acknowledge') }}">
    <div class="panel-header panel-header-modern">
        <div>
            <h2 class="panel-title">Devices</h2>
            <p class="text-muted devices-intro">Cameras and UHF readers found on this network. Plug in the LAN cable and they appear here. No IP to type.</p>
        </div>
        <div class="button-row">
            <x-live-indicator />
            <button type="button" class="button button-secondary button-sm" data-devices-scan>Scan again</button>
        </div>
    </div>

    <div class="devices-network" data-devices-network role="status" aria-live="polite"></div>

    <div class="devices-stations" data-devices-stations></div>

    <div class="devices-list" data-devices-list></div>

    {{-- Why a scan found nothing: interfaces scanned, what the OS sees, warnings. --}}
    <details class="advanced-section devices-diagnostics" data-devices-diagnostics-box>
        <summary>Diagnostics <span data-devices-diagnostics-count></span></summary>
        <div data-devices-diagnostics></div>
    </details>

    <details class="advanced-section devices-other">
        <summary>Other devices on the network (<span data-devices-other-count>0</span>)</summary>
        <div data-devices-other></div>
    </details>

    <p class="field-help devices-tip">
        Tip: set the cameras and readers to <strong>DHCP (automatic IP)</strong> in their own settings. They then join any network
        (school LAN, DITO, Globe or your own router) and this page follows them even when their address changes.
    </p>

    <script id="devices-panel-data" type="application/json">{!! json_encode($devicesPayload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
</section>

<x-drawer id="device-drawer" title="Device" size="md">
    <div data-device-drawer-body></div>
</x-drawer>

@push('scripts')
    <script src="{{ asset('js/devices-panel.js') }}"></script>
@endpush
