{{--
    Activity Logs › Alerts: what needs a look. Anomalies (direction does not
    fit, lost or disabled tag, inactive vehicle...) and unknown tags.
    Unregistered visitors are normal traffic: see the Visitors page.
--}}
<x-stat-row>
    <x-stat label="Anomalies Today" :value="$alertCounts['anomalies'] ?? 0" tone="danger" hint="Direction does not fit, lost or disabled tag" />
    <x-stat label="Unknown Tags Today" :value="$alertCounts['unknown_tags'] ?? 0" hint="Tags that are not in the Registry" />
</x-stat-row>

<x-table title="Alerts" :paginator="$alerts" :empty="$alerts->isEmpty()" empty-title="No alerts" empty-text="Anomalies and unknown tags appear here.">
    <x-slot:filters>
        <form method="GET" action="{{ route('logs.index') }}" class="form-grid filter-grid">
            <input type="hidden" name="tab" value="alerts">
            <div class="field">
                <label for="alert_type">Show</label>
                <select id="alert_type" name="type">
                    <option value="">All alerts</option>
                    <option value="anomaly" @selected(($filters['type'] ?? '') === 'anomaly')>Anomalies</option>
                    <option value="unknown_tag" @selected(($filters['type'] ?? '') === 'unknown_tag')>Unknown tags</option>
                </select>
            </div>
            <div class="field">
                <label for="alert_q">Plate or tag</label>
                <input id="alert_q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ABC 1234 or tag UID">
            </div>
            <div class="field field-actions">
                <div class="button-row">
                    <button type="submit" class="button button-secondary">Filter</button>
                    <a href="{{ route('logs.index', ['tab' => 'alerts']) }}" class="button button-secondary">Reset</a>
                </div>
            </div>
        </form>
    </x-slot:filters>
    <thead>
        <tr>
            <th>Time</th>
            <th>Tag / Vehicle</th>
            <th>Gate</th>
            <th>Alert</th>
            <th>Reason</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($alerts as $scan)
            <tr>
                <td><x-datetime :value="$scan->scan_time" /></td>
                <td>
                    <strong>{{ $scan->vehicle?->plate_number ?? ($scan->isUnknownTag() ? 'Unknown tag' : ($scan->vehicleRfidTag?->label ?? $scan->tag_uid)) }}</strong>
                    <div class="table-subtext">{{ $scan->tag_uid }}</div>
                </td>
                <td>{{ \App\Models\Gate::labelFor($scan->scan_location) }}</td>
                <td><x-badge :tone="$scan->isUnknownTag() ? 'warning' : 'critical'" :label="$scan->isUnknownTag() ? 'Unknown tag' : 'Anomaly'" /></td>
                <td>
                    {{ $scan->anomaly_reason ?: $scan->verificationLabel }}
                    @if ($scan->isUnknownTag())
                        <div class="table-subtext"><a href="{{ route('registry.index', ['tab' => 'vehicles', 'register_tag' => $scan->tag_uid]) }}">Register this tag</a></div>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</x-table>
