{{--
    UI Phase 3: read a tag with the UHF reader, type or paste its UID, or
    pick an available one. A new UID is added to the inventory on save.
    Phase 3 (visitor model): "Register this tag" (an unknown tag read at a
    gate) opens Add Vehicle with that UID filled in ($prefillUid).
--}}
@php($useOld = $useOld ?? false)
@php($prefillUid = $useOld ? null : ($prefillUid ?? null))
<fieldset class="tag-picker" data-tag-picker @if($prefix === 'add') data-tag-optional @endif @if ($prefillUid) data-prefill="1" @endif data-lookup-url="{{ route('registry.tags.lookup') }}" @isset($vehicleId) data-vehicle-id="{{ $vehicleId }}" @endisset>
    <legend>{{ $legend ?? 'RFID Tag' }}</legend>
    @if($prefix === 'add')<p class="field-help">Leave RFID blank to register by plate. You can assign an available tag later.</p>@endif

    @if ($prefix === 'add')
        <div class="field">
            <label for="add_tag_number">Tag number</label>
            <input id="add_tag_number" name="display_number" type="text" inputmode="numeric" autocomplete="off"
                   value="{{ $useOld ? old('display_number') : '' }}" data-registration-tag-number
                   data-number-max-length="{{ \App\Rules\RfidTagNumber::MAX_LENGTH }}" data-number-max-value="{{ \App\Rules\RfidTagNumber::MAX_NUMBER }}"
                   data-number-error="{{ \App\Rules\RfidTagNumber::MESSAGE }}"
                   aria-describedby="add_tag_number_help add_tag_number_error" aria-invalid="{{ $useOld && $errors->has('display_number') ? 'true' : 'false' }}">
            <span id="add_tag_number_help" class="field-help">Required when assigning an RFID tag. Enter the printed number using digits from 1 to 999999; leading zeros are kept (up to 20 digits). For an existing tag, enter its saved number exactly.</span>
            <span id="add_tag_number_error" class="field-error" aria-live="polite" aria-atomic="true" @unless($useOld && $errors->has('display_number')) hidden @endunless>{{ $useOld ? $errors->first('display_number') : '' }}</span>
        </div>
    @endif

    <div class="field">
        <label for="{{ $prefix }}_scan">Scan tag</label>
        <input id="{{ $prefix }}_scan" name="rfid_scan_uid" type="text" autocomplete="off" placeholder="Type or paste the tag UID, or use the UHF reader" data-tag-scan autofocus value="{{ $useOld ? old('rfid_uid') : ($prefillUid ?? '') }}">
        <input type="hidden" name="rfid_uid" value="{{ $useOld ? old('rfid_uid') : '' }}" data-tag-uid>
        <div class="tag-picker-uhf">
            <button type="button" class="button button-secondary button-sm" data-uhf-read="{{ route('registry.tags.uhf-reads') }}">Read with UHF reader</button>
            <span class="field-help">Press the button, then hold the tag near the UHF reader.</span>
        </div>
        <p class="tag-picker-result" data-tag-result role="status" aria-live="polite">{{ $prefix === 'add' ? 'No RFID assigned. Registration will use the plate.' : 'Waiting for a tag…' }}</p>
        @if ($useOld)
            @error('rfid_uid')<span class="field-error">{{ $message }}</span>@enderror
            @error('rfid_tag_uid')<span class="field-error">{{ $message }}</span>@enderror
        @endif
    </div>

    <div class="field">
        <label for="{{ $prefix }}_available">Or choose an available tag</label>
        <select id="{{ $prefix }}_available" name="rfid_tag_id" data-tag-select>
            <option value="">{{ $prefix === 'add' ? 'No RFID assigned' : ($availableTags->isEmpty() ? 'No available tags — scan a new one' : 'Choose a tag') }}</option>
            @foreach ($availableTags as $tag)
                <option value="{{ $tag->id }}" data-number-label="{{ $tag->number_label }}" @selected($useOld && (string) old('rfid_tag_id') === (string) $tag->id)>#{{ $tag->number_label ?: 'N/A' }} · {{ $tag->uid }}</option>
            @endforeach
        </select>
        @if ($useOld)
            @error('rfid_tag_id')<span class="field-error">{{ $message }}</span>@enderror
        @endif
    </div>
</fieldset>
