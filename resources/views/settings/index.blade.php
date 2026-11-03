{{--
    Settings: Gates (names, activation and a card per gate),
    Advanced (technical sections, each with its own ?tab= so older links work).
--}}
@extends('layouts.app')

@section('title', 'Settings | PHILCST Vehicle Monitoring')

@section('content')
    @php($mainTab = \App\Http\Controllers\SettingsController::mainTab($tab))
    <x-page-header title="Settings">
        <x-slot:meta>{{ $tab === 'calibration' ? 'Detection zone calibration' : ($mainTab === 'advanced' ? \App\Http\Controllers\SettingsController::ADVANCED_SECTIONS[$tab] : 'Gate setup and devices') }}</x-slot:meta>
        @if ($tab === 'gates')
            <x-slot:actions><a href="#add-gate" class="button button-primary">+ Add gate</a></x-slot:actions>
        @endif
    </x-page-header>

    <x-tabs :tabs="[
        'gates' => ['label' => 'Gates', 'href' => route('settings.index', ['tab' => 'gates'])],
        'advanced' => ['label' => 'Advanced', 'href' => route('settings.index', ['tab' => 'status'])],
    ]" :active="$mainTab" />

    {{-- B4: larger buttons and fields for a touchscreen. --}}
    <div class="settings-page" data-settings-page="{{ $tab }}">
    @if ($mainTab === 'advanced')
        <div class="advanced-layout">
            <nav class="advanced-nav" aria-label="Advanced settings">
                <p class="advanced-nav-note">Advanced settings</p>
                @foreach (['System & devices' => ['status', 'devices'], 'Configuration' => ['timing', 'manual', 'reader-network'], 'Checks & diagnostics' => ['test-scan', 'plate-camera']] as $group => $sections)
                    <div class="advanced-nav-group">
                        <h2>{{ $group }}</h2>
                        @foreach ($sections as $key)
                            <a href="{{ route('settings.index', ['tab' => $key]) }}" @class(['advanced-nav-link', 'is-active' => $tab === $key]) @if ($tab === $key) aria-current="page" @endif>{{ \App\Http\Controllers\SettingsController::ADVANCED_SECTIONS[$key] }}</a>
                        @endforeach
                    </div>
                @endforeach
            </nav>
            <div class="advanced-content">
                @include('settings.tabs.'.$tab)
            </div>
        </div>
    @elseif ($tab === 'calibration')
        <p class="settings-back"><a href="{{ route('settings.index', ['tab' => 'gates']) }}">&larr; Back to Gates</a></p>
        @include('settings.tabs.calibration')
    @else
        @include('settings.tabs.'.$tab)
    @endif
    </div>
@endsection
