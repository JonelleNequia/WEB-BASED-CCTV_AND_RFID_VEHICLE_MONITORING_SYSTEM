

    <x-stat-row>
        <x-stat label="Detector Service" :value="($runtime['service_running'] ?? false) ? 'Running' : 'Not running'"
                :tone="($runtime['service_running'] ?? false) ? 'success' : 'warning'"
                :hint="$runtime['auto_start_message'] ?? $runtime['service_message']" />
        <x-stat label="Last Update" :value="\App\Support\DisplayTime::datetimeSeconds($runtime['updated_at'] ?? null, 'No update yet')" hint="Latest detector heartbeat" />
        <x-stat label="Entrance Crossings" :value="$runtime['cameras']['entrance']['crossings_logged'] ?? 0" />
        <x-stat label="Exit Crossings" :value="$runtime['cameras']['exit']['crossings_logged'] ?? 0" />
    </x-stat-row>

    @include('settings.partials.pipeline-metrics')

    <div class="camera-grid">
        @foreach (['entrance', 'exit'] as $role)
            @php($cameraStatus = $runtime['cameras'][$role] ?? null)
            <article class="camera-card">
                <div class="camera-card-head">
                    <div>
                        <h4>{{ ucfirst($role) }} Camera</h4>
                        <p>{{ $cameraStatus['camera_name'] ?? ucfirst($role).' Camera' }}</p>
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
                    <div class="span-full">
                        <span>Message</span>
                        <strong>{{ $cameraStatus['last_error'] ?: 'No additional message.' }}</strong>
                    </div>
                </div>
            </article>
        @endforeach
    </div>

@push('scripts')
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
