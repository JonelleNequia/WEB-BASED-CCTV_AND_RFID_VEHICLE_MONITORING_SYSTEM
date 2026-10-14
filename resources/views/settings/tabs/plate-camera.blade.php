{{--
    Gate direction work, Phase B: how readable plates are at each gate (from
    the detector's plate measurements of recent unregistered passes) and the
    camera settings to use. The system never changes camera settings by itself.
--}}
<section class="card settings-section">
    <h3>Plate readability</h3>
    <p class="field-help">Measured on the last 50 unregistered vehicles of each gate: how wide the plate is in the camera's main stream, and how sharp. A plate about {{ \App\Services\PlateReadabilityService::GOOD_PLATE_WIDTH }} px wide and sharp is read best. Nothing here changes the camera: change it in the camera's own settings.</p>

    <div class="plate-report-grid">
        @foreach ($plateReport as $code => $gate)
            <article class="plate-report-card" data-plate-report="{{ $code }}">
                <h4>{{ $gate['name'] }}</h4>
                <dl class="plate-report-numbers">
                    <div><dt>Passes</dt><dd>{{ $gate['passes'] }}</dd></div>
                    <div><dt>Plate read</dt><dd>{{ $gate['read'] }}</dd></div>
                    <div><dt>Unreadable</dt><dd>{{ $gate['unreadable'] }}</dd></div>
                    <div><dt>Plate width</dt><dd>{{ $gate['median_width_px'] !== null ? round($gate['median_width_px']).' px' : '—' }}</dd></div>
                    <div><dt>Sharpness</dt><dd>{{ $gate['median_sharpness'] !== null ? round($gate['median_sharpness']) : '—' }}</dd></div>
                </dl>
                <ul class="plate-report-hints">
                    @foreach ($gate['hints'] as $hint)
                        <li>{{ $hint }}</li>
                    @endforeach
                </ul>
            </article>
        @endforeach
    </div>
</section>

<section class="card settings-section">
    <h3>Recommended camera settings (TP-Link VIGI)</h3>
    <p class="field-help">For clear plates on moving vehicles. Change them in the camera's web page or the VIGI app, then check the numbers above after a few vehicles.</p>
    <dl class="plate-report-settings">
        @foreach (\App\Services\PlateReadabilityService::RECOMMENDED as [$setting, $value])
            <div><dt>{{ $setting }}</dt><dd>{{ $value }}</dd></div>
        @endforeach
    </dl>
</section>
