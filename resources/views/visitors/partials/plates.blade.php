{{-- Phase 5: plate profiles (every visit of one plate). --}}
<x-table title="Plate Profiles" :paginator="$profiles" :empty="$profiles->isEmpty()" empty-title="No plate profiles yet." empty-text="A profile is created the first time a plate is read or typed for an unregistered visitor.">
    <x-slot:filters>
        <form method="GET" action="{{ route('visitors.index') }}" class="form-grid filter-grid">
            <input type="hidden" name="tab" value="plates">
            <div class="field">
                <label for="plate_q">Plate</label>
                <input id="plate_q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ABC 1234">
            </div>
            <div class="field">
                <label for="plate_sort">Order</label>
                <select id="plate_sort" name="sort">
                    <option value="">Last seen first</option>
                    <option value="entries" @selected(($filters['sort'] ?? '') === 'entries')>Visitor Ranking (most entries, not registered)</option>
                </select>
            </div>
            <div class="field field-actions">
                <div class="button-row">
                    <button type="submit" class="button button-secondary">Search</button>
                </div>
            </div>
        </form>
    </x-slot:filters>
    <thead>
        <tr>
            @if (($filters['sort'] ?? '') === 'entries')<th>Rank</th>@endif
            <th>Plate</th>
            <th>Entries</th>
            <th>Seen</th>
            <th>First seen</th>
            <th>Last seen</th>
            <th>Vehicle</th>
            <th>Note</th>
            <th>Registry</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($profiles as $profile)
            <tr>
                @if (($filters['sort'] ?? '') === 'entries')<td><strong>#{{ $profiles->firstItem() + $loop->index }}</strong></td>@endif
                <td><a href="{{ route('visitors.profiles.show', $profile) }}"><strong>{{ $profile->plate_number }}</strong></a></td>
                <td>{{ $profile->entries_count }}</td>
                <td>{{ $profile->visit_count }}</td>
                <td><x-datetime :value="$profile->first_seen_at" /></td>
                <td><x-datetime :value="$profile->last_seen_at" /></td>
                <td>{{ collect([$profile->vehicle_color, $profile->vehicle_type])->filter()->implode(' ') ?: '—' }}</td>
                <td>{{ \Illuminate\Support\Str::limit((string) $profile->note, 60) ?: '—' }}</td>
                <td>@include('visitors.partials.registry-link', ['profile' => $profile])</td>
            </tr>
        @endforeach
    </tbody>
</x-table>
