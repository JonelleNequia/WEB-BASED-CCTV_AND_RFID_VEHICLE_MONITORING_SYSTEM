@php
    $failedTagForm = old('_form') === 'add-tag';
    $tagOldValue = fn ($field) => $failedTagForm && is_scalar(old($field)) ? (string) old($field) : '';
@endphp
<x-drawer id="add-tag-drawer" title="Add RFID Tag" :open="$failedTagForm && $errors->any()">
    <form method="POST" action="{{ route('rfid-inventory.store') }}" class="stack-form" data-manual-register
          data-number-error="{{ \App\Rules\RfidTagNumber::MESSAGE }}" data-number-max-length="{{ \App\Rules\RfidTagNumber::MAX_LENGTH }}" data-number-max-value="{{ \App\Rules\RfidTagNumber::MAX_NUMBER }}">
        @csrf
        <input type="hidden" name="_form" value="add-tag">
        <div class="field">
            <label for="sticker_number">Tag number</label>
            <input id="sticker_number" name="display_number" type="text" inputmode="numeric" autocomplete="off" required
                   value="{{ $tagOldValue('display_number') }}" data-sticker-number
                   aria-describedby="sticker_number_help sticker_number_error" aria-invalid="{{ $failedTagForm && $errors->has('display_number') ? 'true' : 'false' }}">
            <span id="sticker_number_help" class="field-help">Enter the number printed on the sticker. Use digits from 1 to 999999; leading zeros are kept (up to 20 digits).</span>
            <span id="sticker_number_error" class="field-error" aria-live="polite" aria-atomic="true" @unless($failedTagForm && $errors->has('display_number')) hidden @endunless>{{ $failedTagForm ? $errors->first('display_number') : '' }}</span>
        </div>
        <div class="field">
            <label for="sticker_uid">Electronic RFID UID</label>
            <input id="sticker_uid" name="uid" type="text" autocomplete="off" required value="{{ $tagOldValue('uid') }}"
                   data-sticker-uid aria-describedby="sticker_uid_help sticker_uid_error" aria-invalid="{{ $failedTagForm && $errors->has('uid') ? 'true' : 'false' }}">
            <span id="sticker_uid_help" class="field-help">Scan or paste the UID read by the reader. This is separate from the printed tag number.</span>
            <span id="sticker_uid_error" class="field-error" aria-live="polite" aria-atomic="true" @unless($failedTagForm && $errors->has('uid')) hidden @endunless>{{ $failedTagForm ? $errors->first('uid') : '' }}</span>
        </div>
        <div class="tag-picker-uhf">
            <button type="button" class="button button-secondary button-sm" data-sticker-uhf="{{ route('registry.tags.uhf-reads') }}" aria-pressed="false">Read with UHF reader</button>
            <span class="field-help" data-sticker-reader-status role="status" aria-live="polite">Reading a UID does not save the tag. Check both fields, then save.</span>
        </div>
        <div class="button-row">
            <button type="button" class="button button-secondary" data-drawer-close>Cancel</button>
            <button type="submit" class="button button-primary" data-sticker-save>Save RFID Tag</button>
        </div>
    </form>
</x-drawer>

@once
    @push('scripts')
        <script src="{{ asset('js/uhf-tag-reader.js') }}"></script>
        <script src="{{ asset('js/registry-tags.js') }}?v={{ filemtime(public_path('js/registry-tags.js')) }}"></script>
    @endpush
@endonce
