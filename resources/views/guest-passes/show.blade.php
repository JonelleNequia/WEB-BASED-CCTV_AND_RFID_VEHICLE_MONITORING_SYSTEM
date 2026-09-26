{{-- Phase 4: one guest visit with snapshots and events. --}}
@extends('layouts.app')

@section('title', 'Guest Visit | PHILCST Vehicle Monitoring')
@section('page-title', 'Guest Visit')

@php
    $snapshotUrl = fn (?string $path): ?string => $path && \Illuminate\Support\Facades\Storage::disk('public')->exists($path)
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($path)
        : null;
    $entrySnapshot = $snapshotUrl($visit->entry_snapshot);
    $exitSnapshot = $snapshotUrl($visit->exit_snapshot);
@endphp

@section('content')
    <x-page-header :title="($visit->rfidTag?->label ?? 'Guest Pass').' · '.($visit->plate ?: 'No plate')" :back="route('guests.index')" back-label="Back to Guests">
        <x-slot:meta>
            <x-badge :status="$visit->status === 'lost_tag' ? 'lost' : $visit->status" :label="$visit->status === 'lost_tag' ? 'Lost pass' : ucfirst($visit->status)" />
            @if ($visit->valid_until)
                Valid until <x-datetime :value="$visit->valid_until" />
            @endif
        </x-slot:meta>
    </x-page-header>

    <div class="page-grid two-column">
        <section class="panel">
            <div class="panel-header"><h3>Visit Details</h3></div>
            <div class="detail-list">
                <div><span>Driver</span><strong>{{ $visit->driver_name ?: 'N/A' }}</strong></div>
                <div><span>Vehicle</span><strong>{{ trim(($visit->color ?: '').' '.($visit->vehicle_type ?: '')) ?: 'N/A' }}</strong></div>
                <div><span>Purpose</span><strong>{{ $visit->purpose ?: 'N/A' }}</strong></div>
                <div><span>Destination</span><strong>{{ $visit->destination ?: 'N/A' }}</strong></div>
                <div><span>ID Presented</span><strong>{{ $visit->id_presented ?: 'None' }}</strong></div>
                <div><span>Issued By</span><strong>{{ $visit->issuer?->name ?? 'Station' }}</strong></div>
                <div><span>Entry</span><strong><x-datetime :value="$visit->entry_at" /></strong></div>
                <div><span>Exit</span><strong><x-datetime :value="$visit->exit_at" fallback="Still inside" /></strong></div>
            </div>
            @if ($visit->notes)
                <div class="mini-note">
                    <strong>Notes</strong>
                    <p>{!! nl2br(e($visit->notes)) !!}</p>
                </div>
            @endif
        </section>

        <section class="panel">
            <div class="panel-header"><h3>Snapshots</h3></div>
            <div class="page-grid cards-2">
                <div>
                    <span class="table-subtext">Entry</span>
                    @if ($entrySnapshot)
                        <img src="{{ $entrySnapshot }}" alt="Entry snapshot" class="capture-preview">
                    @else
                        <div class="empty-state-inline">No entry snapshot</div>
                    @endif
                </div>
                <div>
                    <span class="table-subtext">Exit</span>
                    @if ($exitSnapshot)
                        <img src="{{ $exitSnapshot }}" alt="Exit snapshot" class="capture-preview">
                    @else
                        <div class="empty-state-inline">No exit snapshot</div>
                    @endif
                </div>
            </div>
        </section>
    </div>

    <section class="panel">
        <div class="panel-header"><h3>Events</h3></div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr><th>Time</th><th>Type</th><th>Source</th><th>Note</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($visit->vehicleEvents as $event)
                        <tr>
                            <td><x-datetime :value="$event->event_time" /></td>
                            <td><x-badge :status="strtolower($event->event_type)" :label="$event->event_type" /></td>
                            <td>{{ $event->roi_name }}</td>
                            <td>{{ $event->anomaly_reason ?: '' }}</td>
                            <td><a href="{{ route('vehicle-events.show', $event) }}" class="button button-secondary button-sm">Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="table-empty">No events.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
