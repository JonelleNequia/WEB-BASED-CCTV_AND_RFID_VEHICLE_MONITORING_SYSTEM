{{-- UI Phase 2: Activity Logs (All Events | RFID Scans | Alerts). Replaces Event Logs, RFID Desk history and Guest Monitoring. --}}
@extends('layouts.app')

@section('title', 'Activity Logs | PHILCST Vehicle Monitoring')

@php
    $logTabs = \App\Http\Controllers\ActivityLogController::TABS;
    $logTabs['alerts'] = ['label' => 'Alerts', 'count' => $alertCounts['total'] ?? 0];
@endphp

@section('content')
    <x-page-header title="Activity Logs">
        <x-slot:actions>
            @if ($tab === 'events')
                <a href="{{ route('vehicle-events.create') }}" class="button button-primary">Quick Manual Log</a>
            @elseif ($tab === 'alerts')
                <button type="button" class="button button-primary" data-drawer-open="add-observation-drawer">Add Guest Observation</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <x-tabs :tabs="$logTabs" :active="$tab" />

    @include('logs.tabs.'.$tab)
@endsection
