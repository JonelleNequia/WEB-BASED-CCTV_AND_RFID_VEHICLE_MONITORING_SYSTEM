{{-- UI Phase 3: Registry › RFID Tags (full inventory, filters, bulk Register Tags, lost/disable). --}}
    {{-- UI Phase 3: counts across vehicle tags and guest passes (click to filter). --}}
    <x-stat-row>
        <x-stat label="Available" :value="$tagStats['available']" tone="success" :href="route('registry.index', ['tab' => 'tags', 'status' => 'available'])" :hint="$tagStats['vehicle_available'].' vehicle · '.$tagStats['pass_available'].' pass'" />
        <x-stat label="Assigned" :value="$tagStats['assigned']" :href="route('registry.index', ['tab' => 'tags', 'status' => 'assigned'])" hint="On a registered vehicle" />
        <x-stat label="Issued" :value="$tagStats['issued']" tone="brand" :href="route('registry.index', ['tab' => 'tags', 'status' => 'issued'])" hint="Guest pass with a guest" />
        <x-stat label="Lost" :value="$tagStats['lost']" tone="danger" :href="route('registry.index', ['tab' => 'tags', 'status' => 'lost'])" />
        <x-stat label="Disabled" :value="$tagStats['disabled']" :href="route('registry.index', ['tab' => 'tags', 'status' => 'disabled'])" />
    </x-stat-row>

    @include('registry.partials.register-tag-drawer', ['defaultTagType' => ($tagTypeFilter ?? 'vehicle') === 'guest_pass' ? 'guest_pass' : 'vehicle'])

    <x-table title="Tag Inventory" :empty="$rfidTagInventory->isEmpty()"
             :empty-title="($tagTypeFilter || $tagStatusFilter) ? 'No tags match these filters.' : 'No RFID tags registered yet.'"
             empty-text="Register tags by tapping them on the reader.">
        <x-slot:toolbar>
            <form method="GET" action="{{ route('registry.index') }}" class="toolbar-search">
                <input type="hidden" name="tab" value="tags">
                <label class="sr-only" for="tag_type_filter">Type</label>
                <select id="tag_type_filter" name="tag_type">
                    <option value="">All types</option>
                    <option value="vehicle" @selected($tagTypeFilter === 'vehicle')>Vehicle tags</option>
                    <option value="guest_pass" @selected($tagTypeFilter === 'guest_pass')>Guest passes</option>
                </select>
                <label class="sr-only" for="tag_status_filter">Status</label>
                <select id="tag_status_filter" name="status">
                    <option value="">All statuses</option>
                    @foreach (\App\Models\RfidTag::STATUSES as $status)
                        <option value="{{ $status }}" @selected($tagStatusFilter === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
                <button type="submit" class="button button-secondary button-sm">Filter</button>
                @if ($tagTypeFilter || $tagStatusFilter)
                    <a href="{{ route('registry.index', ['tab' => 'tags']) }}" class="button button-secondary button-sm">Reset</a>
                @endif
            </form>
            <span class="text-muted">{{ $rfidTagInventory->count() }} records</span>
        </x-slot:toolbar>
        <x-slot:emptyAction>
            <button type="button" class="button button-primary button-sm" data-drawer-open="register-tag-drawer">Register Tags</button>
        </x-slot:emptyAction>
                <thead>
                    <tr>
                        <th>Tag No.</th>
                        <th>Type</th>
                        <th>RFID UID</th>
                        <th>Status</th>
                        <th>Assigned To</th>
                        <th>Last Scan</th>
                        <th>Scans</th>
                        <th><span class="sr-only">Actions</span></th>
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
                                @elseif ($tag->vehicle && $tag->status !== 'assigned')
                                    <span class="table-subtext">Was on {{ $tag->vehicle->plate_number }}</span>
                                @elseif ($tag->vehicle)
                                    <strong>{{ $tag->vehicle->plate_number }}</strong>
                                    <div class="table-subtext">{{ $tag->vehicle->vehicle_owner_name ?: 'No owner' }}</div>
                                @else
                                    <span class="table-subtext">Available for assignment</span>
                                @endif
                            </td>
                            <td><x-datetime :value="$tag->last_scanned_at" fallback="No scan yet" /></td>
                            <td>{{ $tag->scan_logs_count }}</td>
                            <td class="row-actions">@include('registry.partials.tag-actions', ['tag' => $tag])</td>
                        </tr>
                    @endforeach
                </tbody>
    </x-table>


