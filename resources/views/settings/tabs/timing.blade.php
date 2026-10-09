{{-- B3 (Settings › Advanced › Timing): moved from Gates & Readers. --}}
<section class="panel">
    <div class="panel-header panel-header-modern">
        <div>
            <h2 class="panel-title">Timing</h2>
            <p class="field-help">How the RFID reader and the camera are matched. The defaults suit most gates.</p>
        </div>
        @include('settings.partials.restore-defaults', ['section' => 'timing'])
    </div>
    <form method="POST" action="{{ route('settings.update') }}" class="stack-form">
        @csrf
        @method('PUT')
        <input type="hidden" name="section" value="timing">
        <div class="form-grid">
            <div class="field">
                <label for="rfid_cooldown_seconds">Same tag ignored for (seconds)</label>
                <input id="rfid_cooldown_seconds" type="number" name="rfid_cooldown_seconds" value="{{ old('rfid_cooldown_seconds', $settings['rfid_cooldown_seconds'] ?? 60) }}" min="10" max="3600">
                <span class="field-help">A reader sees a tag many times while the vehicle waits. Repeat reads at the same gate are ignored this long (at least 10 s). An unknown tag is reported once.</span>
                @error('rfid_cooldown_seconds')<span class="field-error">{{ $message }}</span>@enderror
            </div>
            <div class="field">
                <label for="rfid_lookback_seconds">Tag read before the crossing (seconds)</label>
                <input id="rfid_lookback_seconds" type="number" name="rfid_lookback_seconds" value="{{ old('rfid_lookback_seconds', $settings['rfid_lookback_seconds'] ?? 10) }}" min="1" max="15">
                <span class="field-help">The reader reads the tag while the vehicle approaches. A read this long before the camera sees it cross still belongs to it.</span>
                @error('rfid_lookback_seconds')<span class="field-error">{{ $message }}</span>@enderror
            </div>
            <div class="field">
                <label for="rfid_lookahead_seconds">Tag read after the crossing (seconds)</label>
                <input id="rfid_lookahead_seconds" type="number" name="rfid_lookahead_seconds" value="{{ old('rfid_lookahead_seconds', $settings['rfid_lookahead_seconds'] ?? 2) }}" min="1" max="10">
                <span class="field-help">How long the camera waits for a tag after the crossing before it records an Unregistered Visitor.</span>
                @error('rfid_lookahead_seconds')<span class="field-error">{{ $message }}</span>@enderror
            </div>
            <div class="field">
                <label for="rfid_stationary_seconds">Parked tag after (seconds)</label>
                <input id="rfid_stationary_seconds" type="number" name="rfid_stationary_seconds" value="{{ old('rfid_stationary_seconds', $settings['rfid_stationary_seconds'] ?? 60) }}" min="10" max="3600">
                <span class="field-help">A tag the reader keeps reading this long is a vehicle parked near the gate. It is not given to a passing vehicle until it has been gone for a few seconds.</span>
                @error('rfid_stationary_seconds')<span class="field-error">{{ $message }}</span>@enderror
            </div>
            <div class="field">
                <label for="rfid_offline_fallback">When the camera is offline</label>
                <input type="hidden" name="rfid_offline_fallback" value="0">
                <label class="checkbox-row">
                    <input id="rfid_offline_fallback" type="checkbox" name="rfid_offline_fallback" value="1" @checked(old('rfid_offline_fallback', $settings['rfid_offline_fallback'] ?? '1') === '1')>
                    Record registered tags anyway ("RFID only")
                </label>
                <span class="field-help">Tags are normally recorded only when the camera sees a vehicle. With this on, a registered tag is still recorded when the camera is offline: IN or OUT from the vehicle's last state, marked "camera offline". Unknown tags are not recorded.</span>
                @error('rfid_offline_fallback')<span class="field-error">{{ $message }}</span>@enderror
            </div>
            <div class="field">
                <label for="rfid_offline_grace_seconds">Camera offline for at least (seconds)</label>
                <input id="rfid_offline_grace_seconds" type="number" name="rfid_offline_grace_seconds" value="{{ old('rfid_offline_grace_seconds', $settings['rfid_offline_grace_seconds'] ?? 10) }}" min="0" max="300">
                <span class="field-help">A short camera hiccup does not switch to "RFID only".</span>
                @error('rfid_offline_grace_seconds')<span class="field-error">{{ $message }}</span>@enderror
            </div>
        </div>
        <div class="button-row button-row-end">
            <button type="submit" class="button button-primary">Save</button>
        </div>
    </form>
</section>
