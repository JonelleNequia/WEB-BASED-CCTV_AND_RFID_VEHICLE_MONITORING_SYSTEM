{{--
    ROI work, Phase 2: passes the camera could not tell IN from OUT
    (registered and unregistered). They are not counted as IN or OUT, and a
    registered vehicle's state is not changed, until they are set here.
--}}
<x-table title="Direction unknown" :empty="$review->isEmpty()" empty-title="Nothing to review" empty-text="Every recorded vehicle has a direction.">
    <x-slot:filters>
        <p class="field-help">The camera saw these vehicles but could not tell whether they went IN or OUT. They are not counted until you set them. Look at the snapshot and the time, then press Set IN or Set OUT.</p>
    </x-slot:filters>
    <thead>
        <tr>
            <th>Snapshot</th>
            <th>Plate</th>
            <th>Gate</th>
            <th>Time</th>
            <th>Why</th>
            <th><span class="sr-only">Set direction</span></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($review as $item)
            <tr id="review-{{ $item['key'] }}">
                <td>
                    @if ($item['snapshot_url'])
                        <button type="button" class="thumb-button" data-zoom="{{ $item['snapshot_url'] }}" data-zoom-label="Snapshot of {{ $item['plate'] }}">
                            <img src="{{ $item['snapshot_url'] }}" alt="Snapshot of {{ $item['plate'] }}" class="thumb thumb-sm" loading="lazy">
                        </button>
                    @else
                        <span class="table-subtext">No snapshot</span>
                    @endif
                </td>
                <td>
                    <div class="plate-cell">
                        <strong>{{ $item['plate'] }}</strong>
                        @if ($item['plate_image_url'])
                            <button type="button" class="thumb-button" data-zoom="{{ $item['plate_image_url'] }}" data-zoom-label="Plate image">
                                <img src="{{ $item['plate_image_url'] }}" alt="Plate image" class="thumb thumb-plate" loading="lazy">
                            </button>
                        @endif
                    </div>
                    <div class="table-subtext">{{ $item['who'] }}</div>
                </td>
                <td class="nowrap">{{ \App\Models\Gate::labelFor($item['gate']) }}</td>
                <td><x-datetime :value="$item['time']" /></td>
                <td class="table-subtext">{{ $item['reason'] ?: 'Direction unknown' }}</td>
                <td class="row-actions"><x-set-direction :url="$item['url']" /></td>
            </tr>
        @endforeach
    </tbody>
</x-table>
