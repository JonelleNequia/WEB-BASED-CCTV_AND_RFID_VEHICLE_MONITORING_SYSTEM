<section class="gate-name-settings">
    <div class="stack-form">
        <div class="panel-title-row">
            <h3 class="panel-title">Gate names and activation</h3>
            <p class="field-help">Names appear on monitoring, logs and reports. Inactive gates are retained and count toward the two-gate minimum. Save applies the name and activation controls for all gates.</p>
        </div>
        <div class="general-gates">
            @foreach ($managedGates ?? $gates as $gate)
                <div class="general-gate">
                    <div class="field">
                        <label for="gate_{{ $gate->code }}_name">{{ $gate->name }} name</label>
                        <input id="gate_{{ $gate->code }}_name" type="text" name="gates[{{ $gate->code }}][name]" value="{{ old("gates.{$gate->code}.name", $gate->name) }}" required maxlength="100" form="gate-settings-form" @error("gates.{$gate->code}.name") aria-invalid="true" aria-describedby="gate_{{ $gate->code }}_name_error" @enderror>
                        @error("gates.{$gate->code}.name")<span class="field-error" id="gate_{{ $gate->code }}_name_error">{{ $message }}</span>@enderror
                    </div>
                    <label class="checkbox-row switch-row">
                        <input type="hidden" name="gates[{{ $gate->code }}][is_active]" value="0" form="gate-settings-form">
                        <input type="checkbox" name="gates[{{ $gate->code }}][is_active]" value="1" form="gate-settings-form" @checked((string) old("gates.{$gate->code}.is_active", $gate->is_active ? '1' : '0') === '1')>
                        Active (shown on the Gate Monitor)
                    </label>
                </div>
            @endforeach
        </div>
        <div class="button-row button-row-end"><button type="submit" class="button button-primary" form="gate-settings-form">Save gate settings</button></div>
    </div>
</section>
