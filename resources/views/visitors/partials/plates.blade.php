{{-- Phase 5: plate profiles (every visit of one plate). UI Phase 4: most entries first, "Register this vehicle" and "Merge". --}}
@php($ranked = ($filters['sort'] ?? '') !== 'recent')
<x-table title="Plate Profiles" :paginator="$profiles" :empty="$profiles->isEmpty()" empty-title="No plate profiles yet" empty-text="A profile starts the first time a visitor's plate is read or typed.">
    <x-slot:filters>
        <form method="GET" action="{{ route('visitors.index') }}" class="log-filter-form">
            <input type="hidden" name="tab" value="plates">
            <div class="field">
                <label for="plate_q">Plate</label>
                <input id="plate_q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ABC 1234">
            </div>
            <div class="field">
                <label for="plate_sort">Order</label>
                <select id="plate_sort" name="sort">
                    <option value="">Most entries</option>
                    <option value="entries" @selected(($filters['sort'] ?? '') === 'entries')>Most entries, not registered</option>
                    <option value="recent" @selected(($filters['sort'] ?? '') === 'recent')>Last seen first</option>
                </select>
            </div>
            <div class="log-filter-actions">
                <button type="submit" class="button button-secondary">Search</button>
            </div>
        </form>
    </x-slot:filters>
    <thead>
        <tr>
            @if ($ranked)<th>Rank</th>@endif
            <th>Plate</th>
            <th>Entries</th>
            <th>Seen</th>
            <th>Last seen</th>
            <th>Vehicle</th>
            <th>Note</th>
            <th><span class="sr-only">Actions</span></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($profiles as $profile)
            <tr>
                @if ($ranked)<td><strong>#{{ $profiles->firstItem() + $loop->index }}</strong></td>@endif
                <td><a href="{{ route('visitors.profiles.show', $profile) }}"><strong>{{ $profile->plate_number }}</strong></a></td>
                <td><strong>{{ $profile->entries_count }}</strong></td>
                <td>{{ $profile->visit_count }}</td>
                <td><x-datetime :value="$profile->last_seen_at" /></td>
                <td>{{ collect([$profile->vehicle_color, $profile->vehicle_type])->filter()->implode(' ') ?: '—' }}</td>
                <td>{{ \Illuminate\Support\Str::limit((string) $profile->note, 40) ?: '—' }}</td>
                <td class="row-actions">
                    <div class="row-actions-inline">
                        @include('visitors.partials.registry-link', ['profile' => $profile])
                        <details class="menu row-menu">
                            <summary class="button button-secondary button-sm" aria-label="Actions for {{ $profile->plate_number }}">⋯</summary>
                            <div class="menu-panel" role="menu">
                                <button type="button" role="menuitem" data-visitor-action="note"
                                        data-action-url="{{ route('visitors.profiles.note', $profile) }}"
                                        data-value="{{ $profile->note }}" data-label="{{ $profile->plate_number }}">Add note</button>
                                @if ($mergeTargets->count() > 1)
                                    <button type="button" role="menuitem" data-visitor-action="merge"
                                            data-action-url="{{ route('visitors.profiles.merge', $profile) }}"
                                            data-profile-id="{{ $profile->id }}" data-label="{{ $profile->plate_number }}">Merge into another plate…</button>
                                @endif
                                <a role="menuitem" class="menu-link" href="{{ route('visitors.profiles.show', $profile) }}">Open profile</a>
                            </div>
                        </details>
                    </div>
                </td>
            </tr>
        @endforeach
    </tbody>
</x-table>

@if ($mergeTargets->count() > 1)
    <x-modal id="visitor-merge-modal" title="Merge plates" size="sm">
        <form method="POST" action="" class="stack-form" data-visitor-form="merge">
            @csrf
            <div class="field">
                <label for="visitor_merge_target">Merge <strong data-visitor-label></strong> into</label>
                <select id="visitor_merge_target" name="target_id" required>
                    <option value="">Choose the correct plate</option>
                    @foreach ($mergeTargets as $target)
                        <option value="{{ $target->id }}" data-profile-id="{{ $target->id }}">{{ $target->plate_number }} ({{ $target->visit_count }} {{ \Illuminate\Support\Str::plural('visit', $target->visit_count) }})</option>
                    @endforeach
                </select>
                <span class="field-help">For a plate the camera misread: its visits move to the chosen plate, and later reads of it go there too.</span>
            </div>
            <div class="button-row button-row-end">
                <button type="button" class="button button-secondary" data-drawer-close>Cancel</button>
                <button type="submit" class="button button-primary">Merge plates</button>
            </div>
        </form>
    </x-modal>
@endif
