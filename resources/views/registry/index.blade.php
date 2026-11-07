{{-- UI Phase 2: Registry (Vehicles | RFID Tags). Replaces Vehicle Registry and RFID Tags. --}}
@extends('layouts.app')

@section('title', 'Registry | PHILCST Vehicle Monitoring')

@section('content')
    @include('partials.filter-errors')
    <x-page-header title="Registry">
        <x-slot:actions>
            @switch($tab)
                @case('tags')
                    <button type="button" class="button button-secondary" data-drawer-open="register-tag-drawer">Register Tags</button>
                    <button type="button" class="button button-primary" data-drawer-open="add-tag-drawer">Add RFID Tag</button>
                    @break
                @default
                    <button type="button" class="button button-primary" data-drawer-open="add-vehicle-drawer">Add Vehicle</button>
            @endswitch
        </x-slot:actions>
    </x-page-header>

    <x-tabs :tabs="\App\Http\Controllers\RegistryController::TABS" :active="$tab" />

    <div data-auto-list="registry-{{ $tab }}" data-auto-default-tab="vehicles">
        @include('partials.auto-filter-status')
        @include('registry.tabs.'.$tab)
    </div>
    @include('partials.auto-filter-assets')
@endsection
