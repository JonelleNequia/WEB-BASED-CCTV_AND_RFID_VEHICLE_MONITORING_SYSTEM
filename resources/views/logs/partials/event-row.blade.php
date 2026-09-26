{{-- UI Phase 4: one Activity Logs row (public/js/activity-logs.js builds the same markup for live rows). --}}
<tr @class(['is-alert-row' => $log['is_alert'] ?? false])>
    <td class="nowrap">{{ $log['display_time'] }}</td>
    <td>
        @if ($log['image_url'])
            <button type="button" class="thumb-button" data-snapshot="{{ $log['image_url'] }}" aria-label="Enlarge snapshot of {{ $log['plate_number'] }}">
                <img src="{{ $log['image_url'] }}" alt="" class="thumb thumb-xs" loading="lazy">
            </button>
        @else
            <span class="thumb thumb-xs thumb-empty" aria-hidden="true"></span>
        @endif
    </td>
    <td>
        <strong>{{ $log['plate_number'] ?: '—' }}</strong>
        @if (($log['owner_name'] ?? 'N/A') !== 'N/A')
            <div class="table-subtext">{{ $log['owner_name'] }}</div>
        @endif
    </td>
    <td>{{ $log['vehicle_type'] }}@if (($log['vehicle_color'] ?? 'N/A') !== 'N/A') · {{ $log['vehicle_color'] }}@endif</td>
    <td><x-badge :status="strtolower($log['event_type'])" :label="$log['event_type']" /></td>
    <td>
        <span @class(['log-type', 'log-type-alert' => $log['is_alert'] ?? false])>{{ $log['log_type_label'] ?? '' }}</span>
        @if (($log['is_alert'] ?? false) && ($log['alert_reason'] ?? null))
            <div class="table-subtext">{{ \Illuminate\Support\Str::limit($log['alert_reason'], 60) }}</div>
        @endif
    </td>
    <td>{{ $log['station_label'] }}</td>
    <td><span class="badge badge-{{ $log['status_badge_class'] }}">{{ $log['status_label'] }}</span></td>
    <td class="row-actions"><button type="button" class="button button-secondary button-sm" data-event-log-view="{{ $index }}">Details</button></td>
</tr>
