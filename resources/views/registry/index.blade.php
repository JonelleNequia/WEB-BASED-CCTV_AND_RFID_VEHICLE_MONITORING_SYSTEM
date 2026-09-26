{{-- UI Phase 2: Registry (Vehicles | RFID Tags | Guest Passes). Replaces Vehicle Registry and RFID Tags. --}}
@extends('layouts.app')

@section('title', 'Registry | PHILCST Vehicle Monitoring')

@section('content')
    <x-page-header title="Registry">
        <x-slot:actions>
            @switch($tab)
                @case('tags')
                    <button type="button" class="button button-primary" data-drawer-open="register-tag-drawer">Register RFID Tag</button>
                    @break
                @case('passes')
                    <button type="button" class="button button-primary" data-drawer-open="register-tag-drawer">Register Guest Pass</button>
                    @break
                @default
                    <button type="button" class="button button-primary" data-drawer-open="add-vehicle-drawer">Add Vehicle</button>
            @endswitch
        </x-slot:actions>
    </x-page-header>

    <x-tabs :tabs="\App\Http\Controllers\RegistryController::TABS" :active="$tab" />

    @include('registry.tabs.'.$tab)
@endsection
