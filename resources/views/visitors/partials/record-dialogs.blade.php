{{-- UI Phase 4: the dialogs behind each visitor record's "⋯" menu (filled by public/js/visitors.js). --}}
<x-modal id="visitor-plate-modal" title="Correct plate" size="sm">
    <form method="POST" action="" class="stack-form" data-visitor-form="plate">
        @csrf
        @method('PATCH')
        <div class="field">
            <label for="visitor_plate_value">Plate number</label>
            <input id="visitor_plate_value" type="text" name="plate_number" placeholder="ABC 1234" maxlength="30" autocomplete="off" data-visitor-value>
            <span class="field-help">Every visit of this plate is kept on its plate profile.</span>
        </div>
        <div class="button-row button-row-end">
            <button type="submit" name="unreadable" value="1" class="button button-secondary">Plate unreadable</button>
            <button type="submit" class="button button-primary">Save plate</button>
        </div>
    </form>
</x-modal>

<x-modal id="visitor-note-modal" title="Add note" size="sm">
    <form method="POST" action="" class="stack-form" data-visitor-form="note">
        @csrf
        @method('PATCH')
        <div class="field">
            <label for="visitor_note_value">Note for <span data-visitor-label></span></label>
            <textarea id="visitor_note_value" name="note" rows="3" maxlength="1000" placeholder="e.g. School supplies delivery, every Monday" data-visitor-value></textarea>
            <span class="field-help">Saved on the plate profile, so it shows for every visit of this plate.</span>
        </div>
        <div class="button-row button-row-end">
            <button type="button" class="button button-secondary" data-drawer-close>Cancel</button>
            <button type="submit" class="button button-primary">Save note</button>
        </div>
    </form>
</x-modal>

<x-modal id="visitor-dismiss-modal" title="Dismiss record" size="sm">
    <form method="POST" action="" class="stack-form" data-visitor-form="dismiss">
        @csrf
        @method('PATCH')
        <div class="field">
            <label for="visitor_dismiss_reason">Reason</label>
            <input id="visitor_dismiss_reason" type="text" name="reason" placeholder="e.g. not a vehicle, registered car with its tag" maxlength="150" required data-visitor-value>
            <span class="field-help">A dismissed record is no longer counted. It stays under Show › Dismissed.</span>
        </div>
        <div class="button-row button-row-end">
            <button type="button" class="button button-secondary" data-drawer-close>Cancel</button>
            <button type="submit" class="button button-danger">Dismiss record</button>
        </div>
    </form>
</x-modal>

@once
    @push('scripts')
        <script src="{{ asset('js/visitors.js') }}"></script>
    @endpush
@endonce
