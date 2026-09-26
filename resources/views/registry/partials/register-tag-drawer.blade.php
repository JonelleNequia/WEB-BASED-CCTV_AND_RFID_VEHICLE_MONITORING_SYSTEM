{{-- UI Phase 2: shared by Registry › RFID Tags and › Guest Passes. The reader fills the UID only while the drawer is open. --}}
<x-drawer id="register-tag-drawer" title="Register RFID Tag" :open="$errors->any()">
    <form method="POST" action="{{ route('rfid-inventory.store') }}" class="stack-form" data-rfid-inventory-form>
        @csrf

        {{-- Phase 4: Vehicle tag or reusable Guest Pass (gets the next G-xx number). --}}
        <div class="field">
            <label for="inventory_tag_type">Tag Type</label>
            <select id="inventory_tag_type" name="tag_type">
                <option value="vehicle" @selected(old('tag_type', $defaultTagType) === 'vehicle')>Vehicle tag</option>
                <option value="guest_pass" @selected(old('tag_type', $defaultTagType) === 'guest_pass')>Guest Pass</option>
            </select>
            @error('tag_type')
                <span class="field-error">{{ $message }}</span>
            @enderror
        </div>

        <div class="field">
            <label for="inventory_tag_number">RFID Tag No.</label>
            <input
                id="inventory_tag_number"
                type="number"
                name="tag_number"
                value="{{ old('tag_number') }}"
                min="1"
                step="1"
                placeholder="1"
                required
                data-rfid-tag-number-input
            >
            @error('tag_number')
                <span class="field-error">{{ $message }}</span>
            @enderror
        </div>

        <div class="field">
            <label for="inventory_uid">RFID UID</label>
            <input
                id="inventory_uid"
                type="text"
                name="uid"
                value="{{ old('uid') }}"
                autocomplete="off"
                inputmode="none"
                placeholder="Focus and scan RFID card"
                readonly
                required
                data-rfid-inventory-input
            >
            <div class="table-subtext" data-rfid-scan-message>Enter the tag number, then tap the RFID card. Manual typing is disabled for UID.</div>
            @error('uid')
                <span class="field-error">{{ $message }}</span>
            @enderror
        </div>

        <div class="button-row">
            <button type="button" class="button button-secondary" data-drawer-close>Cancel</button>
            <button type="submit" class="button button-primary">Register RFID Tag</button>
        </div>
    </form>
</x-drawer>

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const inventoryInput = document.querySelector('[data-rfid-inventory-input]');
            const tagNumberInput = document.querySelector('[data-rfid-tag-number-input]');
            let scanBuffer = '';
            let firstKeyAt = 0;
            let lastKeyAt = 0;
            let idleTimer = null;

            const normalizeUid = (value) => String(value || '').replace(/\s+/g, '').trim().toUpperCase();

            const setScanMessage = (message, isError = false) => {
                const messageNode = inventoryInput?.closest('.field')?.querySelector('[data-rfid-scan-message]');

                if (!messageNode) {
                    return;
                }

                messageNode.textContent = message;
                messageNode.classList.toggle('field-error', isError);
            };

            const acceptScannedUid = (rawUid) => {
                const uid = normalizeUid(rawUid);

                if (!uid || !inventoryInput) {
                    return;
                }

                inventoryInput.value = uid;
                setScanMessage(`${uid} captured from scanner.`);
            };

            const resetScanBuffer = () => {
                scanBuffer = '';
                firstKeyAt = 0;
                lastKeyAt = 0;
                window.clearTimeout(idleTimer);
            };

            const maybeCommitScan = () => {
                if (!inventoryInput || inventoryInput.disabled) {
                    resetScanBuffer();
                    return;
                }

                const elapsed = lastKeyAt - firstKeyAt;
                const averageGap = scanBuffer.length > 1 ? elapsed / (scanBuffer.length - 1) : elapsed;

                if (scanBuffer.length >= 4 && elapsed <= 900 && averageGap <= 80) {
                    acceptScannedUid(scanBuffer);
                }

                resetScanBuffer();
            };

            if (inventoryInput) {
                inventoryInput.addEventListener('focus', resetScanBuffer);
                inventoryInput.addEventListener('paste', (event) => event.preventDefault());
                inventoryInput.addEventListener('drop', (event) => event.preventDefault());
            }

            tagNumberInput?.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && inventoryInput && !inventoryInput.value) {
                    event.preventDefault();
                    inventoryInput.focus({ preventScroll: true });
                }
            });

            document.addEventListener('keydown', (event) => {
                // UI Phase 1: only capture scans while the Register drawer is open.
                if (!inventoryInput || inventoryInput.disabled || inventoryInput.offsetParent === null || event.ctrlKey || event.metaKey || event.altKey) {
                    return;
                }

                const target = event.target;
                const typingIntoFormField = target instanceof HTMLInputElement
                    || target instanceof HTMLTextAreaElement
                    || target instanceof HTMLSelectElement;

                if (typingIntoFormField && target !== inventoryInput) {
                    return;
                }

                if (event.key === 'Enter') {
                    event.preventDefault();
                    maybeCommitScan();
                    return;
                }

                if (event.key.length !== 1) {
                    return;
                }

                event.preventDefault();

                const now = Date.now();
                if (!scanBuffer || now - lastKeyAt > 120) {
                    scanBuffer = '';
                    firstKeyAt = now;
                }

                scanBuffer += event.key;
                lastKeyAt = now;
                window.clearTimeout(idleTimer);
                idleTimer = window.setTimeout(maybeCommitScan, 140);
            });

            document.getElementById('register-tag-drawer')?.addEventListener('drawer:open', () => {
                (tagNumberInput?.value ? inventoryInput : tagNumberInput)?.focus({ preventScroll: true });
            });
        });
    </script>
@endpush
