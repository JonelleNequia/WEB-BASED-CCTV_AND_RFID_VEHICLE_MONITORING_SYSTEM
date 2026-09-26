{{-- UI Phase 2: one form per Settings tab, each with its own Save button. --}}
<section class="panel">
    <form method="POST" action="{{ route('settings.update') }}" class="stack-form" id="settings-form">
        @csrf
        @method('PUT')
        <input type="hidden" name="section" value="guest-pass">

            <section class="subpanel">
                <div class="panel-title-row">
                    <h4>Guest Pass</h4>
<p class="field-help">Default validity is prefilled on the Issue Guest Pass form. A visit becomes Overstay once it passes its valid-until time plus the grace period.</p>                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="guest_pass_validity_minutes">Default Validity (minutes)</label>
                        <input id="guest_pass_validity_minutes" type="number" name="guest_pass_validity_minutes" value="{{ old('guest_pass_validity_minutes', $settings['guest_pass_validity_minutes'] ?? 240) }}" min="15" max="1440">
                        @error('guest_pass_validity_minutes')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="guest_pass_overstay_grace_minutes">Overstay Threshold (grace minutes)</label>
                        <input id="guest_pass_overstay_grace_minutes" type="number" name="guest_pass_overstay_grace_minutes" value="{{ old('guest_pass_overstay_grace_minutes', $settings['guest_pass_overstay_grace_minutes'] ?? 0) }}" min="0" max="720">
                        @error('guest_pass_overstay_grace_minutes')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="guest_pass_require_id">Require ID Before Issuing</label>
                        <input type="hidden" name="guest_pass_require_id" value="0">
                        <label class="checkbox-row">
                            <input id="guest_pass_require_id" type="checkbox" name="guest_pass_require_id" value="1" @checked(old('guest_pass_require_id', $settings['guest_pass_require_id'] ?? '1') === '1')>
                            <span>Guard must record the ID left at the gate</span>
                        </label>
                    </div>
                </div>
            </section>

        <div class="button-row button-row-end">
            <button type="submit" class="button button-primary">Save Guest Pass Rules</button>
        </div>
    </form>
</section>
