

    <x-stat-row>
        <x-stat label="Detector Service" :value="($runtime['service_running'] ?? false) ? 'Running' : 'Not running'"
                :tone="($runtime['service_running'] ?? false) ? 'success' : 'warning'"
                :hint="$runtime['auto_start_message'] ?? $runtime['service_message']" />
        <x-stat label="Last Update" :value="\App\Support\DisplayTime::datetimeSeconds($runtime['updated_at'] ?? null, 'No update yet')" hint="Latest detector heartbeat" />
        @foreach (\App\Models\Gate::options() as $gateCode => $gateName)
            <x-stat :label="$gateName.' Crossings'" :value="$runtime['cameras'][$gateCode]['crossings_logged'] ?? 0" />
        @endforeach
    </x-stat-row>

    @include('settings.partials.pipeline-metrics')

    <div class="camera-grid">
        @foreach (\App\Models\Gate::options() as $role => $gateName)
            @php($cameraStatus = $runtime['cameras'][$role] ?? null)
            <article class="camera-card">
                <div class="camera-card-head">
                    <div>
                        <h4>{{ $gateName }} Camera</h4>
                        <p>{{ $cameraStatus['camera_name'] ?? $gateName.' Camera' }}</p>
                    </div>
                    <x-badge :status="($cameraStatus['camera_running'] ?? false) ? 'online' : 'standby'" :label="($cameraStatus['camera_running'] ?? false) ? 'Running' : 'Standby'" />
                </div>

                <div class="camera-detail-grid">
                    <div>
                        <span>Detection Ready</span>
                        <strong>{{ ($cameraStatus['detection_ready'] ?? false) ? 'Yes' : 'No' }}</strong>
                    </div>
                    <div>
                        <span>Calibration Ready</span>
                        <strong>{{ ($cameraStatus['calibration_ready'] ?? false) ? 'Yes' : 'No' }}</strong>
                    </div>
                    <div>
                        <span>Processed Frames</span>
                        <strong>{{ $cameraStatus['processed_frames'] ?? 0 }}</strong>
                    </div>
                    <div>
                        <span>Detections</span>
                        <strong>{{ $cameraStatus['detections_seen'] ?? 0 }}</strong>
                    </div>
                    <div>
                        <span>Retry Count</span>
                        <strong>{{ $cameraStatus['retry_count'] ?? 0 }}</strong>
                    </div>
                    <div>
                        <span>Last Capture</span>
                        <strong><x-datetime :value="$cameraStatus['last_capture_time'] ?? null" format="seconds" fallback="No capture yet" /></strong>
                    </div>
                    @php($detection = $cameraStatus['detection'] ?? [])
                    <div>
                        <span>Detection runs</span>
                        <strong>{{ isset($detection['detection_fps']) ? $detection['detection_fps'].' per second on '.($detection['device'] ?? '—') : 'Not running' }}</strong>
                    </div>
                    <div>
                        <span>Last run: raw / vehicles / in zone</span>
                        <strong>{{ $detection['last_raw_detections'] ?? '—' }} / {{ $detection['last_vehicles'] ?? '—' }} / {{ $detection['last_in_zone'] ?? '—' }}</strong>
                    </div>
                    <div>
                        <span>Line crossings</span>
                        <strong>{{ $detection['line_crossings'] ?? 0 }}</strong>
                    </div>
                    @if (! empty($detection['last_error']))
                        <div class="span-full">
                            <span>Last detection error</span>
                            <strong>{{ $detection['last_error'] }}</strong>
                        </div>
                    @endif
                    <div class="span-full">
                        <span>Message</span>
                        <strong>{{ $cameraStatus['last_error'] ?: 'No additional message.' }}</strong>
                    </div>
                </div>
            </article>
        @endforeach
    </div>

    {{-- Fresh start: removes activity data after a backup (same as `php artisan system:reset`). --}}
    <section class="panel reset-panel">
        <div class="panel-header panel-header-modern">
            <div>
                <h2 class="panel-title">Reset activity data</h2>
                <p class="field-help">Removes vehicle logs, RFID scans, visitor records, plate profiles, alerts, snapshots and logs, and sets every registered vehicle to Outside. Keeps users, settings, gates, calibration, cameras, devices, registered vehicles and RFID tags. A backup is saved first.</p>
            </div>
            <button type="button" class="button button-secondary button-sm" data-drawer-open="reset-activity-modal">Reset activity data</button>
        </div>
        @error('confirm')<p class="field-error">{{ $message }}</p>@enderror
    </section>

    <x-modal id="reset-activity-modal" title="Reset activity data" :open="$errors->has('confirm')">
        <form method="POST" action="{{ route('settings.system.reset-activity') }}" class="stack-form" data-reset-form>
            @csrf
            <p>This removes all activity (logs, scans, visitor records, snapshots) and cannot be undone here. The database and snapshots are backed up first to <code>storage/backups/reset-…</code>.</p>
            <div class="field">
                <label for="reset_confirm">Type <strong>RESET</strong> to confirm</label>
                <input id="reset_confirm" type="text" name="confirm" autocomplete="off" required pattern="RESET" data-reset-confirm>
                @error('confirm')<span class="field-error">{{ $message }}</span>@enderror
            </div>
            <div class="button-row">
                <button type="button" class="button button-secondary" data-drawer-close>Cancel</button>
                <button type="submit" class="button button-danger" data-reset-submit disabled>Back up and reset</button>
            </div>
        </form>
    </x-modal>

@push('scripts')
    <script>
        // The reset button only works once RESET is typed.
        document.querySelector('[data-reset-confirm]')?.addEventListener('input', function (event) {
            document.querySelector('[data-reset-submit]').disabled = event.target.value !== 'RESET';
        });
    </script>

    <script>
        // Refresh the measured pipeline every 3 seconds.
        (function () {
            const box = document.querySelector('[data-pipeline-metrics]');
            if (!box) {
                return;
            }
            const url = box.dataset.url;
            window.setInterval(async function () {
                if (document.hidden) {
                    return;
                }
                try {
                    const response = await fetch(url, { headers: { Accept: 'text/html' } });
                    if (response.ok) {
                        const current = document.querySelector('[data-pipeline-metrics]');
                        const holder = document.createElement('div');
                        holder.innerHTML = await response.text();
                        const fresh = holder.querySelector('[data-pipeline-metrics]');
                        if (current && fresh) {
                            current.replaceWith(fresh);
                        }
                    }
                } catch (error) {
                    // Keep the last numbers during short hiccups.
                }
            }, 3000);
        })();
    </script>
@endpush
