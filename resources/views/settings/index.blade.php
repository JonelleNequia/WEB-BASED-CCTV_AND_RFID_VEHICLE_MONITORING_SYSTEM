{{-- UI Phase 2: Settings tabs. Camera Calibration, System Status and the RFID Desk simulation live here now. --}}
@extends('layouts.app')

@section('title', 'Settings | PHILCST Vehicle Monitoring')

@section('content')
    <x-page-header title="Settings" />

    <x-tabs :tabs="\App\Http\Controllers\SettingsController::TABS" :active="$tab" />

    @include('settings.tabs.'.$tab)
@endsection
