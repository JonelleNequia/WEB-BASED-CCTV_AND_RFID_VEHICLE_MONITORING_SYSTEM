{{--
    B1 (Settings): three tabs. Gates (a card per gate), General (gate names),
    Advanced (technical sections, each with its own ?tab= so older links work).
--}}
@extends('layouts.app')

@section('title', 'Settings | PHILCST Vehicle Monitoring')

@section('content')
    @php($mainTab = \App\Http\Controllers\SettingsController::mainTab($tab))
    <x-page-header title="Settings" />

    <x-tabs :tabs="[
        'gates' => ['label' => 'Gates', 'href' => route('settings.index', ['tab' => 'gates'])],
        'general' => ['label' => 'General', 'href' => route('settings.index', ['tab' => 'general'])],
        'advanced' => ['label' => 'Advanced', 'href' => route('settings.index', ['tab' => 'status'])],
    ]" :active="$mainTab" />

    @if ($mainTab === 'advanced')
        <div class="advanced-layout">
            <nav class="advanced-nav" aria-label="Advanced settings">
                <p class="advanced-nav-note">For the admin or technician.</p>
                @foreach (\App\Http\Controllers\SettingsController::ADVANCED_SECTIONS as $key => $label)
                    <a href="{{ route('settings.index', ['tab' => $key]) }}" @class(['advanced-nav-link', 'is-active' => $tab === $key]) @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
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
@endsection
