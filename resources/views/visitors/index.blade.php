{{-- Phase 5 (visitor model): Unregistered Visitor records and plate profiles. --}}
@extends('layouts.app')

@section('title', 'Visitors | PHILCST Vehicle Monitoring')

@section('content')
    <x-page-header title="Visitors">
        <x-slot:actions>
            <button type="button" class="button button-primary" data-drawer-open="add-visitor-drawer">Add visitor manually</button>
        </x-slot:actions>
    </x-page-header>

    <x-tabs :tabs="\App\Http\Controllers\VisitorController::TABS" :active="$tab" />

    @if ($tab === 'plates')
        @include('visitors.partials.plates')
    @else
        <x-table title="Unregistered Visitors" :paginator="$records" :empty="$records->isEmpty()" empty-title="No unregistered visitors yet" empty-text="Vehicles that cross a gate without a registered tag appear here.">
            <x-slot:filters>
                <form method="GET" action="{{ route('visitors.index') }}" class="log-filter-form">
                    <input type="hidden" name="tab" value="records">
                    <div class="field">
                        <label for="visitor_q">Plate</label>
                        <input id="visitor_q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ABC 1234">
                    </div>
                    <div class="field">
                        <label for="visitor_gate">Gate</label>
                        <select id="visitor_gate" name="gate">
                            <option value="">All</option>
                            @foreach ($gates as $code => $name)
                                <option value="{{ $code }}" @selected(($filters['gate'] ?? '') === $code)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="visitor_plate_status">Plate result</label>
                        <select id="visitor_plate_status" name="plate_status">
                            <option value="">All</option>
                            @foreach (['read' => 'Read by camera', 'corrected' => 'Corrected by guard', 'unreadable' => 'Plate unreadable', 'pending' => 'Still reading'] as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['plate_status'] ?? '') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="visitor_status">Show</label>
                        <select id="visitor_status" name="status">
                            @foreach (['active' => 'Counted records', 'duplicate' => 'Duplicates', 'dismissed' => 'Dismissed', 'all' => 'All'] as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['status'] ?? 'active') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="log-filter-actions">
                        <button type="submit" class="button button-secondary">Filter</button>
                        <a href="{{ route('visitors.index') }}" class="button button-secondary">Reset</a>
                    </div>
                </form>
            </x-slot:filters>
            @include('visitors.partials.records', ['records' => $records])
        </x-table>
    @endif

    @include('visitors.partials.record-dialogs')

    {{-- Phase 8 (visitor model): replaces "Add Guest Observation" (Activity Logs › Alerts). --}}
    <x-drawer id="add-visitor-drawer" title="Add visitor manually" :open="request()->boolean('add') || $errors->hasAny(['gate', 'direction', 'seen_at', 'plate_number', 'snapshot'])">
        <form method="POST" action="{{ route('visitors.records.store') }}" enctype="multipart/form-data" class="stack-form">
            @csrf
            <p class="field-help">For a vehicle without a registered tag that the camera did not record (camera offline, missed). It is counted as an Unregistered Visitor.</p>
            <div class="field">
                <label for="manual_gate">Gate</label>
                <select id="manual_gate" name="gate" required>
                    @foreach ($gates as $code => $name)
                        <option value="{{ $code }}" @selected(old('gate') === $code)>{{ $name }}</option>
                    @endforeach
                </select>
                @error('gate')<span class="field-error">{{ $message }}</span>@enderror
            </div>
            <div class="field">
                <label for="manual_direction">Direction</label>
                <select id="manual_direction" name="direction" required>
                    <option value="IN" @selected(old('direction', 'IN') === 'IN')>IN (coming into campus)</option>
                    <option value="OUT" @selected(old('direction') === 'OUT')>OUT (leaving campus)</option>
                </select>
                @error('direction')<span class="field-error">{{ $message }}</span>@enderror
            </div>
            <div class="field">
                <label for="manual_seen_at">Time</label>
                <input id="manual_seen_at" type="datetime-local" name="seen_at" value="{{ old('seen_at', now()->format('Y-m-d\TH:i')) }}" required>
                @error('seen_at')<span class="field-error">{{ $message }}</span>@enderror
            </div>
            <div class="field">
                <label for="manual_plate">Plate number (optional)</label>
                <input id="manual_plate" type="text" name="plate_number" value="{{ old('plate_number') }}" placeholder="ABC 1234" maxlength="30" autocomplete="off">
                @error('plate_number')<span class="field-error">{{ $message }}</span>@enderror
            </div>
            <div class="field">
                <label for="manual_type">Vehicle type (optional)</label>
                <input id="manual_type" type="text" name="vehicle_type" value="{{ old('vehicle_type') }}" placeholder="Car, Van, Motorcycle" maxlength="50">
            </div>
            <div class="field">
                <label for="manual_color">Color (optional)</label>
                <input id="manual_color" type="text" name="vehicle_color" value="{{ old('vehicle_color') }}" maxlength="30">
            </div>
            <div class="field">
                <label for="manual_note">Note (optional)</label>
                <input id="manual_note" type="text" name="note" value="{{ old('note') }}" maxlength="200" placeholder="e.g. Delivery, camera was offline">
            </div>
            <div class="field">
                <label for="manual_snapshot">Photo (optional)</label>
                <input id="manual_snapshot" type="file" name="snapshot" accept="image/jpeg,image/png">
                @error('snapshot')<span class="field-error">{{ $message }}</span>@enderror
            </div>
            <div class="button-row">
                <button type="button" class="button button-secondary" data-drawer-close>Cancel</button>
                <button type="submit" class="button button-primary">Save visitor</button>
            </div>
        </form>
    </x-drawer>
@endsection
