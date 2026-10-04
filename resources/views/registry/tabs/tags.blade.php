{{-- UI Phase 3: Registry › RFID Tags (full inventory, filters, bulk Register Tags, lost/disable). --}}
    {{-- UI Phase 3: tag counts (click to filter). Every tag is a vehicle tag since Phase 0. --}}
    <x-stat-row>
        <x-stat label="Available" :value="$tagStats['available']" tone="success" :href="route('registry.index', ['tab' => 'tags', 'status' => 'available'])" hint="Ready to assign" />
        <x-stat label="Assigned" :value="$tagStats['assigned']" :href="route('registry.index', ['tab' => 'tags', 'status' => 'assigned'])" hint="On a registered vehicle" />
        <x-stat label="Lost" :value="$tagStats['lost']" tone="danger" :href="route('registry.index', ['tab' => 'tags', 'status' => 'lost'])" />
        <x-stat label="Disabled" :value="$tagStats['disabled']" :href="route('registry.index', ['tab' => 'tags', 'status' => 'disabled'])" />
    </x-stat-row>

    @include('registry.partials.register-tag-drawer')

    <x-table title="Tag Inventory" :empty="$rfidTagInventory->isEmpty()"
             :empty-title="$tagStatusFilter ? 'No tags match this filter.' : 'No RFID tags registered yet.'"
             empty-text="Register tags by tapping them on the reader.">
        <x-slot:toolbar>
            <form method="GET" action="{{ route('registry.index') }}" class="toolbar-search">
                <input type="hidden" name="tab" value="tags">
                <label class="sr-only" for="tag_status_filter">Status</label>
                <select id="tag_status_filter" name="status">
                    <option value="">All statuses</option>
                    @foreach (\App\Models\RfidTag::STATUSES as $status)
                        <option value="{{ $status }}" @selected($tagStatusFilter === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
                <button type="submit" class="button button-secondary button-sm">Filter</button>
                @if ($tagStatusFilter)
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
                            <td><strong>{{ $tag->uid }}</strong></td>
                            <td>
                                <x-badge :status="$tag->status" />
                            </td>
                            <td>
                                @if ($tag->vehicle && $tag->status !== 'assigned')
                                    <span class="table-subtext">Was on {{ $tag->vehicle->plate_number }}</span>
                                @elseif ($tag->vehicle)
                                    <strong>{{ $tag->vehicle->plate_number }}</strong>
                                    <div class="table-subtext">{{ $tag->vehicle->vehicle_owner_name ?: 'No owner' }}</div>
                                @else
                                    <span class="table-subtext">{{ match ($tag->status) {
                                        'disabled' => 'Disabled: cannot be assigned',
                                        'lost' => 'Lost: scans are flagged',
                                        default => 'Available for assignment',
                                    } }}</span>
                                @endif
                            </td>
                            <td><x-datetime :value="$tag->last_scanned_at" fallback="No scan yet" /></td>
                            <td>{{ $tag->scan_logs_count }}</td>
                            <td class="row-actions">@include('registry.partials.tag-actions', ['tag' => $tag])</td>
                        </tr>
                    @endforeach
                </tbody>
    </x-table>


