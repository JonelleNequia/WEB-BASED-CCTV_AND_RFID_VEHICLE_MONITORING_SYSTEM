/* New registrations: owner and staff stay distinct; one validity state owns Save. */
(function () {
    const staffRequired = 'Enter the name of the staff member processing this registration.';
    const formatMessage = 'Use letters, spaces, apostrophes, hyphens and periods. Digits, emojis, control characters and punctuation-only names are not allowed.';
    const normalizeStaff = (value) => value.replace(/\p{Zs}+/gu, ' ').replace(/^ +| +$/g, '');
    const normalizeOwner = normalizeStaff;

    document.querySelectorAll('form[data-vehicle-form="add"]').forEach((form) => {
        const fields = [
            { input: form.querySelector('[data-registration-staff]'), error: document.getElementById('add_registered_by_error'),
                required: staffRequired, label: 'Registered by', normalize: normalizeStaff, owner: false },
            { input: form.querySelector('[data-registration-owner]'), error: document.getElementById('add_owner_error'),
                required: 'Enter the vehicle owner’s name.', label: 'Owner Name', normalize: normalizeOwner, owner: true },
        ].filter((field) => field.input && field.error);
        const composing = new Set();
        fields.forEach((field) => { field.touched = field.input.getAttribute('aria-invalid') === 'true'; });

        function messageFor(field) {
            const value = field.normalize(field.input.value);
            if (!value) return field.required;
            if ([...value].length > 100) return `${field.label} must not exceed 100 characters.`;
            const letters = value.replace(/['’ʻʼ]/gu, '');
            const allowed = field.owner ? /^[\p{L}\p{M}\p{Zs} .'’‐‑-]+$/u : /^[\p{L}\p{M}\p{Zs} .'‘’‐‑-]+$/u;
            if (!/\p{L}/u.test(letters) || !allowed.test(value)) return formatMessage;
            return '';
        }

        function syncButtons() {
            if (form.getAttribute('aria-busy') === 'true') return;
            const invalid = composing.size > 0 || Array.from(form.elements).some((input) => input.willValidate && !input.validity.valid);
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => { button.disabled = invalid; });
        }

        function validate(field) {
            if (composing.has(field.input)) return;
            const message = messageFor(field);
            const show = !!message && (field.touched || field.input.value !== '');
            field.input.setCustomValidity(message);
            field.input.setAttribute('aria-invalid', show ? 'true' : 'false');
            field.error.textContent = show ? message : '';
            field.error.hidden = !show;
        }

        fields.forEach((field) => {
            const input = field.input;
            input.addEventListener('input', (event) => {
                if (!composing.has(input) && !event.isComposing) { field.touched = true; validate(field); }
            });
            input.addEventListener('change', () => { field.touched = true; validate(field); });
            input.addEventListener('focus', () => { validate(field); syncButtons(); });
            input.addEventListener('blur', () => { field.touched = true; validate(field); syncButtons(); });
            input.addEventListener('invalid', (event) => {
                event.preventDefault();
                field.touched = true;
                validate(field);
                syncButtons();
                if (!form.querySelector(':invalid:focus')) input.focus();
            });
            input.addEventListener('compositionstart', () => { composing.add(input); syncButtons(); });
            input.addEventListener('compositionend', () => { composing.delete(input); field.touched = true; validate(field); syncButtons(); });
        });
        form.addEventListener('input', syncButtons);
        form.addEventListener('change', syncButtons);
        form.addEventListener('plate:validity', syncButtons);
        form.addEventListener('submit', (event) => {
            fields.forEach((field) => { field.touched = true; validate(field); });
            syncButtons();
            if (composing.size || fields.some((field) => messageFor(field))) {
                event.preventDefault();
                fields.find((field) => messageFor(field))?.input.focus();
                return;
            }
            fields.forEach((field) => { field.input.value = field.normalize(field.input.value); });
        }, true);
        const refresh = () => { fields.forEach(validate); syncButtons(); };
        form.addEventListener('reset', () => {
            composing.clear();
            fields.forEach((field) => { field.touched = false; });
            window.setTimeout(refresh, 0);
        });
        window.addEventListener('pageshow', () => window.setTimeout(refresh, 0));
        refresh();
    });
})();
