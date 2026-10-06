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
                <input id="rfid_lookahead_seconds" type="number" name="rfid_lookahead_seconds" value="{{ old('rfid_lookahead_seconds', $settings['rfid_lookahead_seconds'] ?? 4) }}" min="1" max="10">
                <span class="field-help">How long the camera waits for a tag after the crossing before it records an Unregistered Visitor.</span>
                @error('rfid_lookahead_seconds')<span class="field-error">{{ $message }}</span>@enderror
            </div>
        </div>
        <div class="button-row button-row-end">
            <button type="submit" class="button button-primary">Save</button>
        </div>
    </form>
</section>
