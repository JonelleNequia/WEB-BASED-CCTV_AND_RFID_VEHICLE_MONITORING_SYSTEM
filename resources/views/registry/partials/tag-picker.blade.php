{{--
    UI Phase 3: read a tag with the UHF reader, type or paste its UID, or
    pick an available one. A new UID is added to the inventory on save.
    Phase 3 (visitor model): "Register this tag" (an unknown tag read at a
    gate) opens Add Vehicle with that UID filled in ($prefillUid).
--}}
@php($useOld = $useOld ?? false)
@php($prefillUid = $useOld ? null : ($prefillUid ?? null))
<fieldset class="tag-picker" data-tag-picker @if ($prefillUid) data-prefill="1" @endif data-lookup-url="{{ route('registry.tags.lookup') }}" @isset($vehicleId) data-vehicle-id="{{ $vehicleId }}" @endisset>
    <legend>{{ $legend ?? 'RFID Tag' }}</legend>

    <div class="field">
        <label for="{{ $prefix }}_scan">Scan tag</label>
        <input id="{{ $prefix }}_scan" type="text" autocomplete="off" placeholder="Type or paste the tag UID, or use the UHF reader" data-tag-scan autofocus value="{{ $useOld ? old('rfid_uid') : ($prefillUid ?? '') }}">
        <input type="hidden" name="rfid_uid" value="{{ $useOld ? old('rfid_uid') : '' }}" data-tag-uid>
        <div class="tag-picker-uhf">
            <button type="button" class="button button-secondary button-sm" data-uhf-read="{{ route('registry.tags.uhf-reads') }}">Read with UHF reader</button>
            <span class="field-help">Press the button, then hold the tag near the UHF reader.</span>
        </div>
        <p class="tag-picker-result" data-tag-result role="status" aria-live="polite">Waiting for a tag…</p>
        @if ($useOld)
            @error('rfid_uid')<span class="field-error">{{ $message }}</span>@enderror
            @error('rfid_tag_uid')<span class="field-error">{{ $message }}</span>@enderror
        @endif
    </div>

    <div class="field">
        <label for="{{ $prefix }}_available">Or choose an available tag</label>
        <select id="{{ $prefix }}_available" name="rfid_tag_id" data-tag-select>
            <option value="">{{ $availableTags->isEmpty() ? 'No available tags — scan a new one' : 'Choose a tag' }}</option>
            @foreach ($availableTags as $tag)
                <option value="{{ $tag->id }}" @selected($useOld && (string) old('rfid_tag_id') === (string) $tag->id)>#{{ $tag->tag_number ?: 'N/A' }} · {{ $tag->uid }}</option>
            @endforeach
        </select>
        @if ($useOld)
            @error('rfid_tag_id')<span class="field-error">{{ $message }}</span>@enderror
        @endif
    </div>
</fieldset>
