{{-- B1 (Settings › General): gate names and simple options only. --}}
<section class="panel">
    <form method="POST" action="{{ route('settings.update') }}" class="stack-form">
        @csrf
        @method('PUT')
        <input type="hidden" name="section" value="general">

        <div class="panel-title-row">
            <h2 class="panel-title">Gate names</h2>
            <p class="field-help">Shown on the Gate Monitor, the kiosks, the logs and the reports.</p>
        </div>
        @error('gates')<span class="field-error">{{ $message }}</span>@enderror

        <div class="general-gates">
            @foreach ($gates as $gate)
                @php($active = (string) old("gates.{$gate->code}.is_active", $gate->is_active ? '1' : '0') === '1')
                <div class="general-gate">
                    <div class="field">
                        <label for="gate_{{ $gate->code }}_name">Name</label>
                        <input id="gate_{{ $gate->code }}_name" type="text" name="gates[{{ $gate->code }}][name]" value="{{ old("gates.{$gate->code}.name", $gate->name) }}" required maxlength="100">
                        @error("gates.{$gate->code}.name")<span class="field-error">{{ $message }}</span>@enderror
                    </div>
                    <label class="checkbox-row switch-row">
                        <input type="hidden" name="gates[{{ $gate->code }}][is_active]" value="0">
                        <input type="checkbox" name="gates[{{ $gate->code }}][is_active]" value="1" @checked($active)>
                        In use (shown on the Gate Monitor and counted)
                    </label>
                </div>
            @endforeach
        </div>

        <div class="button-row button-row-end">
            <button type="submit" class="button button-primary">Save</button>
        </div>
    </form>
</section>
