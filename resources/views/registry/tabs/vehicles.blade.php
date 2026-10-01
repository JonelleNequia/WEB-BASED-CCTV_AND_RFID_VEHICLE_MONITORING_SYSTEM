{{--
    UI Phase 3: Registry › Vehicles. Search/filter toolbar, one-flow Add Vehicle
    (scan tag → details → save), row side panel with the last 10 movements,
    Edit, Replace Tag and Deactivate.
--}}
@php
    $failedForm = old('_form');
    $failedVehicleId = old('_vehicle_id');
    $categoryLabel = fn (?string $category): string => ucfirst(str_replace('_', ' ', (string) $category));
@endphp

<x-stat-row>
    <x-stat label="Registered Vehicles" :value="$rfidStats['registered_vehicles'] ?? 0" />
    {{-- Phase 1: shared inside count (VehicleOccupancyService) --}}
    <x-stat label="Inside Campus" :value="$rfidStats['vehicles_inside'] ?? 0"
            hint="Registered vehicles" />
    <x-stat label="Available RFID Tags" :value="$rfidStats['available_tags'] ?? 0" :href="route('registry.index', ['tab' => 'tags', 'status' => 'available'])" />
</x-stat-row>

<x-table :empty="$vehicles->isEmpty()"
         :empty-title="array_filter($filters ?? []) ? 'No vehicles match these filters.' : 'No registered vehicles yet.'"
         empty-text="Add a vehicle by scanning its RFID tag.">
    <x-slot:toolbar>
        <form method="GET" action="{{ route('registry.index') }}" class="toolbar-search" role="search">
            <input type="hidden" name="tab" value="vehicles">
            <label class="sr-only" for="vehicle_q">Search vehicles</label>
            <input id="vehicle_q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Plate, owner or tag">
            <label class="sr-only" for="vehicle_category_filter">Category</label>
            <select id="vehicle_category_filter" name="category">
                <option value="">All categories</option>
                @foreach ($vehicleCategories as $category)
                    <option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>{{ $categoryLabel($category) }}</option>
                @endforeach
            </select>
            <label class="sr-only" for="vehicle_state_filter">State</label>
            <select id="vehicle_state_filter" name="state">
                <option value="">Inside + outside</option>
                <option value="inside" @selected(($filters['state'] ?? '') === 'inside')>Inside</option>
                <option value="outside" @selected(($filters['state'] ?? '') === 'outside')>Outside</option>
            </select>
            <label class="sr-only" for="vehicle_status_filter">Status</label>
            <select id="vehicle_status_filter" name="status">
                <option value="">Active + inactive</option>
                <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
            </select>
            <button type="submit" class="button button-secondary button-sm">Filter</button>
            @if (array_filter($filters ?? []))
                <a href="{{ route('registry.index') }}" class="button button-secondary button-sm">Reset</a>
            @endif
        </form>
    </x-slot:toolbar>
    <x-slot:emptyAction>
        <button type="button" class="button button-primary button-sm" data-drawer-open="add-vehicle-drawer">Add Vehicle</button>
    </x-slot:emptyAction>

    <thead>
        <tr>
            <th>Plate</th>
            <th>Owner</th>
            <th>Category</th>
            <th>Type</th>
            <th>Tag No.</th>
            <th>State</th>
            <th>Last Seen</th>
            <th><span class="sr-only">Actions</span></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($vehicles as $vehicle)
            <tr class="is-clickable" tabindex="0" data-vehicle-row data-vehicle-url="{{ route('registry.vehicles.show', $vehicle) }}" aria-label="Open details for {{ $vehicle->plate_number }}">
                <td><strong>{{ $vehicle->plate_number }}</strong></td>
                <td>{{ $vehicle->vehicle_owner_name ?: '—' }}</td>
                <td>{{ $categoryLabel($vehicle->category) }}</td>
                <td>{{ $vehicle->vehicle_type }}</td>
                <td>
                    @if ($vehicle->rfidTag)
                        #{{ $vehicle->rfidTag->tag_number ?: '—' }}
                        @if ($vehicle->rfidTag->status !== 'assigned')
                            <x-badge :status="$vehicle->rfidTag->status" />
                        @endif
                    @else
                        <x-badge status="no_tag" />
                    @endif
                </td>
                <td>
                    <x-badge :status="strtolower($vehicle->current_state ?? 'outside')" />
                    @if ($vehicle->status !== 'active')
                        <x-badge :status="$vehicle->status" />
                    @endif
                </td>
                <td><x-datetime :value="$vehicle->last_seen_at" fallback="Never" /></td>
                <td class="row-actions">
                    <button type="button" class="button button-secondary button-sm" data-vehicle-action="edit" data-vehicle-url="{{ route('registry.vehicles.show', $vehicle) }}">Edit</button>
                    <button type="button" class="button button-secondary button-sm" data-vehicle-action="replace" data-vehicle-url="{{ route('registry.vehicles.show', $vehicle) }}">{{ $vehicle->rfidTag ? 'Replace Tag' : 'Assign Tag' }}</button>
                    <form method="POST" action="{{ route('registry.vehicles.status', $vehicle) }}"
                          data-confirm="{{ $vehicle->status === 'active' ? 'Deactivate '.$vehicle->plate_number.'? Scans of its tag will be flagged.' : 'Activate '.$vehicle->plate_number.' again?' }}">
                        @csrf
                        <input type="hidden" name="status" value="{{ $vehicle->status === 'active' ? 'inactive' : 'active' }}">
                        <button type="submit" class="button {{ $vehicle->status === 'active' ? 'button-subtle-danger' : 'button-secondary' }} button-sm">
                            {{ $vehicle->status === 'active' ? 'Deactivate' : 'Activate' }}
                        </button>
                    </form>
                </td>
            </tr>
        @endforeach
    </tbody>
</x-table>

{{-- Add Vehicle: 1. scan tag, 2. details, 3. save. --}}
<x-drawer id="add-vehicle-drawer" title="Add Vehicle" :open="$failedForm === 'add'">
    <form method="POST" action="{{ route('vehicle-registry.store') }}" class="stack-form" data-vehicle-form="add">
        @csrf
        <input type="hidden" name="_form" value="add">
        <input type="hidden" name="auto_register_tag" value="1">

        @error('vehicle')
            <p class="field-error">{{ $message }}</p>
        @enderror

        <span class="step-label">1 · Tag</span>
        @include('registry.partials.tag-picker', ['prefix' => 'add', 'useOld' => $failedForm === 'add', 'legend' => 'RFID Tag'])

        <span class="step-label">2 · Vehicle details</span>
        @include('registry.partials.vehicle-fields', ['prefix' => 'add', 'useOld' => $failedForm === 'add'])

        <div class="button-row">
            <button type="button" class="button button-secondary" data-drawer-close>Cancel</button>
            <button type="submit" class="button button-primary">Save Vehicle</button>
        </div>
    </form>
</x-drawer>

{{-- Side panel: filled from registry.vehicles.show. --}}
<x-drawer id="vehicle-panel" title="Vehicle" size="lg">
    <div class="vehicle-panel" data-vehicle-panel>
        <p class="text-muted" data-panel-loading>Loading…</p>
    </div>
</x-drawer>

{{-- Edit Vehicle (tag changes go through Replace Tag). --}}
@php($editVehicle = $failedForm === 'edit' ? $vehicles->firstWhere('id', (int) $failedVehicleId) : null)
<x-drawer id="edit-vehicle-drawer" title="Edit Vehicle" :open="(bool) $editVehicle">
    <form method="POST" action="{{ $editVehicle ? route('vehicle-registry.update', $editVehicle) : '#' }}" class="stack-form" data-vehicle-form="edit">
        @csrf
        @method('PUT')
        <input type="hidden" name="_form" value="edit">
        <input type="hidden" name="_vehicle_id" value="{{ $editVehicle?->id }}" data-field="id">
        <input type="hidden" name="rfid_tag_id" value="{{ $editVehicle ? old('rfid_tag_id') : '' }}" data-field="tag_id">

        <p class="field-help" data-edit-tag-note>The RFID tag is changed with Replace Tag.</p>
        @error('rfid_tag_id')
            <p class="field-error">{{ $message }}</p>
        @enderror

        @include('registry.partials.vehicle-fields', ['prefix' => 'edit', 'useOld' => (bool) $editVehicle])

        <div class="button-row">
            <button type="button" class="button button-secondary" data-drawer-close>Cancel</button>
            <button type="submit" class="button button-primary">Save Changes</button>
        </div>
    </form>
</x-drawer>

{{-- Replace Tag: old tag → lost/disabled, new tag assigned. --}}
@php($replaceVehicle = $failedForm === 'replace' ? $vehicles->firstWhere('id', (int) $failedVehicleId) : null)
<x-drawer id="replace-tag-drawer" title="Replace Tag" :open="(bool) $replaceVehicle">
    <form method="POST" action="{{ $replaceVehicle ? route('registry.vehicles.replace-tag', $replaceVehicle) : '#' }}" class="stack-form" data-vehicle-form="replace">
        @csrf
        <input type="hidden" name="_form" value="replace">
        <input type="hidden" name="_vehicle_id" value="{{ $replaceVehicle?->id }}" data-field="id">

        <p class="replace-current" data-replace-current>
            @if ($replaceVehicle)
                {{ $replaceVehicle->plate_number }} · current tag {{ $replaceVehicle->rfidTag ? '#'.$replaceVehicle->rfidTag->tag_number.' · '.$replaceVehicle->rfidTag->uid : 'none' }}
            @endif
        </p>

        @include('registry.partials.tag-picker', ['prefix' => 'replace', 'useOld' => (bool) $replaceVehicle, 'legend' => 'New tag', 'vehicleId' => $replaceVehicle?->id])

        <fieldset class="field" data-old-tag-fieldset>
            <legend>What happened to the old tag?</legend>
            <label class="checkbox-row"><input type="radio" name="old_tag_status" value="lost" @checked(old('old_tag_status', 'lost') === 'lost')> Lost (scans of it raise an alert)</label>
            <label class="checkbox-row"><input type="radio" name="old_tag_status" value="disabled" @checked(old('old_tag_status') === 'disabled')> Damaged / disabled</label>
            @error('old_tag_status')<span class="field-error">{{ $message }}</span>@enderror
        </fieldset>

        <div class="button-row">
            <button type="button" class="button button-secondary" data-drawer-close>Cancel</button>
            <button type="submit" class="button button-primary">Replace Tag</button>
        </div>
    </form>
</x-drawer>

<script id="registry-vehicle-data" type="application/json">{!! json_encode([
    'categories' => $vehicleCategories,
    'vehicleTypes' => $vehicleTypes,
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>

@push('scripts')
    <script src="{{ asset('js/uhf-tag-reader.js') }}"></script>
    <script src="{{ asset('js/registry.js') }}"></script>
@endpush
