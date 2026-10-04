{{-- Phase 5 (visitor model): one plate profile: visits, note, merge. --}}
@extends('layouts.app')

@section('title', $profile->plate_number.' | Visitors')

@section('content')
    <x-page-header :title="$profile->plate_number" :back="route('visitors.index', ['tab' => 'plates'])" back-label="Plate profiles" />

    <section class="panel">
        <div class="camera-detail-grid">
            <div><span>Visits</span><strong>{{ $profile->visit_count }}</strong></div>
            <div><span>First seen</span><strong><x-datetime :value="$profile->first_seen_at" /></strong></div>
            <div><span>Last seen</span><strong><x-datetime :value="$profile->last_seen_at" /></strong></div>
            <div><span>Vehicle</span><strong>{{ collect([$profile->vehicle_color, $profile->vehicle_type])->filter()->implode(' ') ?: '—' }}</strong></div>
            <div><span>Category</span><strong>{{ \App\Support\VehicleCategory::label(\App\Models\VisitorRecord::CATEGORY) }}</strong></div>
            <div><span>Registry</span><strong>@include('visitors.partials.registry-link', ['profile' => $profile])</strong></div>
            @if ($profile->aliases->isNotEmpty())
                <div><span>Merged plates</span><strong>{{ $profile->aliases->pluck('plate_number')->implode(', ') }}</strong></div>
            @endif
        </div>
    </section>

    <section class="panel">
        <form method="POST" action="{{ route('visitors.profiles.note', $profile) }}" class="stack-form">
            @csrf
            @method('PATCH')
            <div class="field">
                <label for="profile_note">Visitor note (optional)</label>
                <textarea id="profile_note" name="note" rows="3" maxlength="1000" placeholder="e.g. School supplies delivery, every Monday">{{ old('note', $profile->note) }}</textarea>
                @if ($profile->noteAuthor)
                    <span class="field-help">Last edited by {{ $profile->noteAuthor->name }}.</span>
                @endif
                @error('note')<span class="field-error">{{ $message }}</span>@enderror
            </div>
            <div class="button-row"><button type="submit" class="button button-primary">Save note</button></div>
        </form>
    </section>

    @if (auth()->user()?->isAdmin() && $mergeTargets->isNotEmpty())
        <section class="panel">
            <form method="POST" action="{{ route('visitors.profiles.merge', $profile) }}" class="stack-form">
                @csrf
                <div class="field">
                    <label for="merge_target">Merge this plate into</label>
                    <select id="merge_target" name="target_id" required>
                        <option value="">Choose the correct plate</option>
                        @foreach ($mergeTargets as $target)
                            <option value="{{ $target->id }}">{{ $target->plate_number }} ({{ $target->visit_count }} {{ \Illuminate\Support\Str::plural('visit', $target->visit_count) }})</option>
                        @endforeach
                    </select>
                    <span class="field-help">Use this when the camera misread a plate: all {{ $profile->visit_count }} visit(s) of {{ $profile->plate_number }} move to the chosen plate, and later reads of {{ $profile->plate_number }} go there too.</span>
                    @error('target')<span class="field-error">{{ $message }}</span>@enderror
                    @error('target_id')<span class="field-error">{{ $message }}</span>@enderror
                </div>
                <div class="button-row"><button type="submit" class="button button-secondary">Merge plates</button></div>
            </form>
        </section>
    @endif

    <x-table title="Visits" :paginator="$records" :empty="$records->isEmpty()" empty-title="No visits recorded for this plate.">
        @include('visitors.partials.records', ['records' => $records])
    </x-table>

    @include('visitors.partials.record-dialogs')
@endsection
