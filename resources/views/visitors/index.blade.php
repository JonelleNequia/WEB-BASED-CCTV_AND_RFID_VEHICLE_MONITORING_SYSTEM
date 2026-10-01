{{-- Phase 5 (visitor model): Unregistered Visitor records and plate profiles. --}}
@extends('layouts.app')

@section('title', 'Visitors | PHILCST Vehicle Monitoring')

@section('content')
    <x-page-header title="Visitors" />

    <p class="field-help">Unregistered Visitors are vehicles the camera saw cross a gate without a registered RFID tag. They are counted IN and OUT, never as "inside". Correct a wrong or unreadable plate here; the plate profile keeps every visit of that plate.</p>

    <x-tabs :tabs="\App\Http\Controllers\VisitorController::TABS" :active="$tab" />

    @if ($tab === 'plates')
        @include('visitors.partials.plates')
    @else
        <x-table title="Unregistered Visitors" :paginator="$records" :empty="$records->isEmpty()" empty-title="No unregistered visitors yet." empty-text="A vehicle that crosses a gate with no registered tag read appears here.">
            <x-slot:filters>
                <form method="GET" action="{{ route('visitors.index') }}" class="form-grid filter-grid">
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
                    <div class="field field-actions">
                        <div class="button-row">
                            <button type="submit" class="button button-secondary">Filter</button>
                            <a href="{{ route('visitors.index') }}" class="button button-secondary">Reset</a>
                        </div>
                    </div>
                </form>
            </x-slot:filters>
            @include('visitors.partials.records', ['records' => $records])
        </x-table>
    @endif
@endsection
