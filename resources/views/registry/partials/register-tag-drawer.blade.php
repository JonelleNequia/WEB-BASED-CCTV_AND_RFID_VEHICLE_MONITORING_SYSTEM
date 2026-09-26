{{--
    UI Phase 3: "Register Tags" bulk scanning (Registry › RFID Tags and › Guest Passes).
    Each tap on the reader adds one tag; numbers (and G-xx labels) are assigned
    automatically. Nothing else on the page listens to the reader.
--}}
<x-drawer id="register-tag-drawer" title="Register Tags">
    <div class="stack-form" data-bulk-register data-store-url="{{ route('rfid-inventory.store') }}">
        <div class="field">
            <label for="bulk_tag_type">Tag type</label>
            <select id="bulk_tag_type" data-bulk-type>
                <option value="guest_pass" @selected($defaultTagType === 'guest_pass')>Guest pass (gets the next G-xx number)</option>
                <option value="vehicle" @selected($defaultTagType === 'vehicle')>Vehicle tag</option>
            </select>
        </div>

        <div class="field">
            <label for="bulk_scan">Scan tags one after another</label>
            <input id="bulk_scan" type="text" autocomplete="off" placeholder="Focus here and tap each tag" data-bulk-scan autofocus>
            <span class="field-help">Tag numbers are assigned automatically, starting at #<span data-bulk-next>{{ $nextTagNumber }}</span>.</span>
        </div>

        <p class="text-muted" data-bulk-summary role="status" aria-live="polite">No tags added yet.</p>
        <ul class="bulk-scan-list" data-bulk-list></ul>

        <div class="button-row">
            <button type="button" class="button button-secondary" data-drawer-close>Close</button>
            <button type="button" class="button button-primary" data-bulk-done>Done</button>
        </div>
    </div>
</x-drawer>

@once
    @push('scripts')
        <script src="{{ asset('js/registry-tags.js') }}"></script>
    @endpush
@endonce
