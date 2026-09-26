{{-- UI Phase 2/3: Registry › Guest Passes (the physical G-xx cards). Visits are on the Guests page. --}}
<x-stat-row>
    <x-stat label="Available" :value="$passStats['available']" tone="success" :hint="$passStats['total'].' in total'" />
    <x-stat label="Issued" :value="$passStats['issued']" tone="brand" :href="route('guests.index')" hint="With a guest inside" />
    <x-stat label="Lost" :value="$passStats['lost']" tone="danger" />
    <x-stat label="Disabled" :value="$passStats['disabled']" />
</x-stat-row>

@include('registry.partials.register-tag-drawer', ['defaultTagType' => 'guest_pass'])

<x-table title="Guest Passes" :empty="$passes->isEmpty()" empty-title="No guest passes yet." empty-text="Register reusable RFID cards as guest passes (G-01, G-02 ...).">
    <x-slot:emptyAction>
        <button type="button" class="button button-primary button-sm" data-drawer-open="register-tag-drawer">Register Guest Pass</button>
    </x-slot:emptyAction>
    <thead>
        <tr>
            <th>Pass</th>
            <th>Status</th>
            <th>Current Holder</th>
            <th>Last Used</th>
            <th>RFID UID</th>
            <th><span class="sr-only">Actions</span></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($passes as $pass)
            @php($visit = $pass->activeGuestVisit)
            <tr>
                <td><strong>{{ $pass->display_number ?: $pass->label }}</strong></td>
                <td><x-badge :status="$pass->status" /></td>
                <td>
                    @if ($visit)
                        <a href="{{ route('guest-passes.visits.show', $visit) }}"><strong>{{ $visit->plate ?: 'No plate' }}</strong></a>
                        <div class="table-subtext">{{ $visit->driver_name ?: 'Guest' }} · since <x-datetime :value="$visit->entry_at" format="time" /></div>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td><x-datetime :value="$pass->last_scanned_at" fallback="Never" /></td>
                <td class="text-muted">{{ $pass->uid }}</td>
                <td class="row-actions">
                    @if ($visit)
                        {{-- A pass with a guest is marked lost from the visit (keeps the visit record right). --}}
                        <a href="{{ route('guest-passes.visits.show', $visit) }}" class="button button-secondary button-sm">Open visit</a>
                    @else
                        @include('registry.partials.tag-actions', ['tag' => $pass])
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</x-table>
