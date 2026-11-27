{{-- Phase 2: one-time move of the reader's address with the module maker's
     Windows tool (also in DEPLOYMENT.md). Values come from this PC's network. --}}
@php
    $guideNetwork = collect($gateCards ?? [])->pluck('reader.network')->filter()->first();
    $pcNetwork = $guideNetwork['pc_networks'][0] ?? null;
@endphp
<div class="netmodule-guide">
    <p>Use this when "Move reader to this network" cannot reach the reader. It is done once, on a Windows PC plugged into the same router or switch as the reader.</p>
    <ol class="guide-steps">
        <li><strong>Open NetModuleConfig</strong> (the network-module setup tool from the reader's maker) and click <em>Search Device</em>. The reader appears by its MAC{{ $guideNetwork ? ' '.$guideNetwork['mac'] : '' }} with its current address{{ $guideNetwork ? ' '.$guideNetwork['ip'] : '' }}.</li>
        <li><strong>Select the reader.</strong> Its settings load on the right.</li>
        <li><strong>Change only the network part:</strong>
            <ul>
                <li>IP type <em>DHCP</em>, or <em>Static</em> with a free address in the router's network{{ $pcNetwork ? ' ('.$pcNetwork.')' : '' }};</li>
                <li>subnet mask and gateway of that network (the gateway is the router).</li>
            </ul>
        </li>
        <li><strong>Do not change</strong> the work mode (<em>TCP Server</em>), the local port (e.g. 49152), or the serial settings (baud rate, data bits, parity, stop bits).</li>
        <li>Click <em>Set Device Para</em> (save), then <em>Reset Device</em>. The reader restarts in about 10 seconds.</li>
        <li>Back here, the reader card turns <em>Online</em> within a minute. The system finds the reader by its MAC; no terminal and no extra address are needed.</li>
    </ol>
    <p class="field-help">Button names differ a little between tool versions. If the tool asks for a login, the factory one is usually admin / admin.</p>
</div>
