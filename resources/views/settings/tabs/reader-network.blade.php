{{-- Phase 2 (Settings › Advanced › RFID reader network): the reader's
     address against this PC's network, the move, the NetModuleConfig guide
     and the temporary workaround. --}}
@php($readers = collect($gateCards)->filter(fn ($gate) => $gate['reader']['network'] ?? null))
@php($workaroundEnabled = app(\App\Services\ReaderNetworkService::class)->workaroundEnabled())
<section class="panel">
    <div class="panel-header panel-header-modern">
        <div>
            <h2 class="panel-title">RFID reader network</h2>
            <p class="field-help">A reader must have an address in this PC's network (the router's network). Then the system connects to it by itself after every restart.</p>
        </div>
    </div>

    @if ($readers->isEmpty())
        <x-empty-state title="No reader added" text="Add a reader to a gate in Settings › Gates first." />
    @else
        <div class="table-responsive">
            <table>
                <thead>
                    <tr><th>Gate</th><th>Reader address</th><th>This PC's network</th><th>State</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($readers as $gate)
                        @php($network = $gate['reader']['network'])
                        @php($workaround = $network['workaround'] ?? [])
                        <tr data-reader-network-row="{{ $gate['code'] }}">
                            <td>{{ $gate['name'] }}<div class="table-subtext">MAC {{ $network['mac'] }}</div></td>
                            <td>{{ $network['ip'] ?? '—' }}</td>
                            <td>{{ implode(', ', $network['pc_networks']) ?: '—' }}</td>
                            <td>
                                @if (! $network['outside'])
                                    <x-badge tone="success" label="In this network" />
                                @else
                                    <x-badge tone="warning" label="Other network" />
                                    <div class="table-subtext">
                                        @switch($workaround['state'] ?? null)
                                            @case('active') Temporary workaround active: extra address {{ $workaround['ip'] }} on {{ $workaround['interface'] }}. @break
                                            @case('needs_permission') Temporary workaround waiting for permission (see below). @break
                                            @case('failed') Temporary workaround failed: {{ $workaround['message'] ?? '' }} @break
                                            @case('not_found') The reader's network module does not answer (check its LAN cable). @break
                                            @case('off') Temporary workaround is off. @break
                                            @default Checking…
                                        @endswitch
                                    </div>
                                @endif
                            </td>
                            <td>
                                @if ($network['outside'])
                                    <button type="button" class="button button-primary button-sm" data-reader-move data-label="{{ $gate['name'] }}"
                                            data-status-url="{{ route('settings.gate.reader.network', $gate['code']) }}"
                                            data-read-url="{{ route('settings.gate.reader.network.read', $gate['code']) }}"
                                            data-apply-url="{{ route('settings.gate.reader.network.apply', $gate['code']) }}">Move reader to this network</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<section class="panel">
    <div class="panel-header panel-header-modern">
        <h2 class="panel-title">If the move does not work: NetModuleConfig</h2>
    </div>
    @include('settings.partials.netmodule-guide')
</section>

<section class="panel" data-reader-workaround>
    <div class="panel-header panel-header-modern">
        <div>
            <h2 class="panel-title">Temporary workaround <x-badge tone="warning" label="Development only" /></h2>
            <p class="field-help">While a reader is still on another network, the system adds an extra address in that network on this PC's network card by itself, so the reader works without a terminal. It is removed when the reader is moved.</p>
        </div>
    </div>
    <p>It needs a permission, set up once:</p>
    <ul class="guide-steps">
        <li><strong>Windows:</strong> start the system with the startup task (<code>tools\start\install-windows-autostart.ps1</code>, run as administrator). The address lasts until Windows restarts and is added again at start.</li>
        <li><strong>Mac:</strong> run <code>sudo tools/start/allow-reader-workaround-mac.sh</code> once in Terminal (remove it with <code>--remove</code>).</li>
    </ul>
    <form method="POST" action="{{ route('settings.reader-workaround') }}" class="button-row">
        @csrf
        <input type="hidden" name="enabled" value="{{ $workaroundEnabled ? '0' : '1' }}">
        <span>The workaround is <strong>{{ $workaroundEnabled ? 'on' : 'off' }}</strong>.</span>
        <button type="submit" class="button button-secondary">{{ $workaroundEnabled ? 'Turn it off' : 'Turn it on' }}</button>
    </form>
</section>

<x-modal id="reader-move" title="Move the reader to this network" size="md">
    <p class="field-help">Reader of <strong data-reader-move-gate></strong></p>
    <div data-reader-move-body aria-live="polite"></div>
</x-modal>

@push('scripts')
    <script src="{{ asset('js/reader-network.js') }}"></script>
@endpush
