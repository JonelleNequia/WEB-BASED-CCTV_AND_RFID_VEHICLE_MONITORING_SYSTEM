{{-- Phase 2 (Settings › Advanced › RFID reader network): the reader's
     address against this PC's network, the move and the NetModuleConfig
     guide. --}}
@php($readers = collect($gateCards)->filter(fn ($gate) => $gate['reader']['network'] ?? null))
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
                        <tr data-reader-network-row="{{ $gate['code'] }}">
                            <td>{{ $gate['name'] }}<div class="table-subtext">MAC {{ $network['mac'] }}</div></td>
                            <td>{{ $network['ip'] ?? '—' }}</td>
                            <td>{{ implode(', ', $network['pc_networks']) ?: '—' }}</td>
                            <td>
                                @if (! $network['outside'])
                                    <x-badge tone="success" label="In this network" />
                                @else
                                    <x-badge tone="warning" label="Other network" />
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

<x-modal id="reader-move" title="Move the reader to this network" size="md">
    <p class="field-help">Reader of <strong data-reader-move-gate></strong></p>
    <div data-reader-move-body aria-live="polite"></div>
</x-modal>

@push('scripts')
    <script src="{{ asset('js/reader-network.js') }}"></script>
@endpush
