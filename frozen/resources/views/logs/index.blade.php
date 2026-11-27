{{-- UI Phase 2: Activity Logs (All Events | RFID Scans | Alerts). Replaces Event Logs, RFID Desk history and Guest Monitoring. --}}
@extends('layouts.app')

@section('title', 'Activity Logs | PHILCST Vehicle Monitoring')

@php
    $logTabs = \App\Http\Controllers\ActivityLogController::TABS;
    $logTabs['alerts'] = ['label' => 'Alerts', 'count' => $alertCounts['total'] ?? 0];
@endphp

@section('content')
    @include('partials.filter-errors')
    <x-page-header title="Activity Logs">
        @if ($tab === 'events')
            <x-slot:meta><span data-auto-live @if ((int) request('page', 1) !== 1) hidden @endif>@if ((int) request('page', 1) === 1)<x-live-indicator />@endif</span></x-slot:meta>
        @endif
        <x-slot:actions>
            @if ($tab === 'events')
                <a href="{{ route('vehicle-events.create') }}" class="button button-primary">Quick Manual Log</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <x-tabs :tabs="$logTabs" :active="$tab" />

    <div data-auto-list="logs-{{ $tab }}" data-auto-default-tab="events" @if ($tab === 'events') data-auto-poll="4000" @endif>
        @include('partials.auto-filter-status')
        @include('logs.tabs.'.$tab)
    </div>
    @include('partials.auto-filter-assets')
@endsection
