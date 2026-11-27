{{--
    B3 (Settings › Advanced › All network devices): every device the device
    service found (duplicates of one device merged), with MAC, maker and ports.
    "+ Add camera / reader" on the Gates tab opens this page until the
    add-device wizard (B2) replaces it.
--}}
@php($forGate = \App\Models\Gate::resolveCode((string) request('gate')))
@php($forRole = request('role') === 'reader' ? 'reader' : (request('role') === 'camera' ? 'camera' : null))
@if ($forGate && $forRole)
    <div class="devices-banner devices-hint" role="status">
        <strong>Add a {{ $forRole === 'camera' ? 'camera' : 'RFID reader' }} to {{ \App\Models\Gate::labelFor($forGate) }}</strong>
        <span>Click the {{ $forRole === 'camera' ? 'camera' : 'reader' }} in the list below, choose {{ \App\Models\Gate::labelFor($forGate) }} as {{ $forRole === 'camera' ? 'Camera' : 'UHF Reader' }}, then Assign. <a href="{{ route('settings.index', ['tab' => 'gates']) }}">Back to Gates</a></span>
    </div>
@endif
@include('settings.partials.devices-panel')
