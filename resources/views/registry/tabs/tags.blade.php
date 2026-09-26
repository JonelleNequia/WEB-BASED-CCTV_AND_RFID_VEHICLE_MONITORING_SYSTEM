    {{-- Phase 4: separate stats for vehicle tags and guest passes. --}}
    <x-stat-row>
        <x-stat label="Vehicle Tags Available" :value="$tagStats['vehicle_available']" tone="success" />
        <x-stat label="Vehicle Tags Assigned" :value="$tagStats['vehicle_assigned']" :hint="$tagStats['vehicle_total'].' in total'" />
        <x-stat label="Guest Passes Available" :value="$tagStats['pass_available']" :hint="$tagStats['pass_total'].' in total'" />
        <x-stat label="Guest Passes Issued" :value="$tagStats['pass_issued']" tone="brand" />
        <x-stat label="Guest Passes Lost" :value="$tagStats['pass_lost']" tone="danger" />
    </x-stat-row>

    @include('registry.partials.register-tag-drawer', ['defaultTagType' => $tagTypeFilter ?? 'vehicle'])

    <x-table title="Tag Inventory" :empty="$rfidTagInventory->isEmpty()" empty-title="No RFID tags registered yet." empty-text="Register a tag by tapping it on the reader.">
        <x-slot:toolbar>
            {{-- Phase 4: filter by tag type. --}}
            @foreach (['' => 'All', 'vehicle' => 'Vehicle tags', 'guest_pass' => 'Guest passes'] as $value => $label)
                <a href="{{ route('registry.index', array_filter(['tab' => 'tags', 'tag_type' => $value])) }}"
                   class="chip {{ (string) ($tagTypeFilter ?? '') === (string) $value ? 'chip-brand' : 'chip-soft' }}">{{ $label }}</a>
            @endforeach
            <span class="text-muted">{{ $rfidTagInventory->count() }} records</span>
        </x-slot:toolbar>
        <x-slot:emptyAction>
            <button type="button" class="button button-primary button-sm" data-drawer-open="register-tag-drawer">Register RFID Tag</button>
        </x-slot:emptyAction>
                <thead>
                    <tr>
                        <th>RFID No.</th>
                        <th>Type</th>
                        <th>RFID UID</th>
                        <th>Status</th>
                        <th>Assigned Vehicle</th>
                        <th>Last Scan</th>
                        <th>Scans</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rfidTagInventory as $tag)
                        <tr>
                            <td><strong>#{{ $tag->tag_number ?: 'N/A' }}</strong></td>
                            <td>
                                @if ($tag->isGuestPass())
                                    <x-badge tone="brand">Guest Pass {{ $tag->display_number }}</x-badge>
                                @else
                                    <x-badge tone="neutral">Vehicle</x-badge>
                                @endif
                            </td>
                            <td><strong>{{ $tag->uid }}</strong></td>
                            <td>
                                <x-badge :status="$tag->status" />
                            </td>
                            <td>
                                @if ($tag->isGuestPass())
                                    <span class="table-subtext">{{ $tag->status === 'issued' ? 'With a guest' : 'Guest pass pool' }}</span>
                                @elseif ($tag->vehicle)
                                    <strong>{{ $tag->vehicle->plate_number }}</strong>
                                    <div class="table-subtext">{{ $tag->vehicle->vehicle_owner_name ?: 'No owner' }}</div>
                                @else
                                    <span class="table-subtext">Available for assignment</span>
                                @endif
                            </td>
                            <td><x-datetime :value="$tag->last_scanned_at" fallback="No scan yet" /></td>
                            <td>{{ $tag->scan_logs_count }}</td>
                        </tr>
                    @endforeach
                </tbody>
    </x-table>


