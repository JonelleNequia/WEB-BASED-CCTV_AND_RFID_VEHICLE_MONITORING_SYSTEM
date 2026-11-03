/* Required staff attribution for new registrations only. No background writes. */
(function () {
    const requiredMessage = 'Enter the name of the staff member processing this registration.';
    const formatMessage = 'Use letters, spaces, apostrophes, hyphens and periods. Digits, emojis, control characters and punctuation-only names are not allowed.';
    const normalize = (value) => value.replace(/\p{Zs}+/gu, ' ').replace(/^ +| +$/g, '');

    function messageFor(raw) {
        const value = normalize(raw);
        if (!value) return requiredMessage;
        if ([...value].length > 100) return 'Registered by must not exceed 100 characters.';
        const letters = value.replace(/['’ʻʼ]/gu, '');
        if (!/\p{L}/u.test(letters) || /[^\p{L}\p{M}\p{Zs} .'‘’‐‑-]/u.test(value)) return formatMessage;
        return '';
    }

    document.querySelectorAll('[data-registration-staff]').forEach((input) => {
        const form = input.form;
        const error = document.getElementById('add_registered_by_error');
        if (!form || !error) return;
        let touched = input.getAttribute('aria-invalid') === 'true';
        let composing = false;
        const disabledHere = new Set();

        function validate() {
            if (composing) return;
            const message = messageFor(input.value);
            const knownInvalid = !!message && (touched || input.value !== '');
            input.setCustomValidity(message);
            input.setAttribute('aria-invalid', knownInvalid ? 'true' : 'false');
            error.textContent = knownInvalid ? message : '';
            error.hidden = !knownInvalid;
            syncButtons(knownInvalid);
        }

        function syncButtons(invalid) {
            if (form.getAttribute('aria-busy') === 'true') return;
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => {
                if (invalid && !button.disabled) {
                    button.disabled = true;
                    disabledHere.add(button);
                } else if (!invalid && disabledHere.has(button)) {
                    button.disabled = false;
                    disabledHere.delete(button);
                }
            });
        }

        input.addEventListener('input', () => {
            if (!composing) { touched = true; validate(); }
        });
        // Browsers may report autofill through change rather than input.
        input.addEventListener('change', () => { touched = true; validate(); });
        input.addEventListener('focus', validate);
        input.addEventListener('blur', () => { touched = true; validate(); });
        input.addEventListener('invalid', (event) => {
            event.preventDefault(); // Accessible inline feedback instead of a validation popup.
            touched = true;
            validate();
            input.focus();
        });
        input.addEventListener('compositionstart', () => { composing = true; syncButtons(true); });
        input.addEventListener('compositionend', () => { composing = false; touched = true; validate(); });
        form.addEventListener('submit', (event) => {
            touched = true;
            validate();
            if (composing || messageFor(input.value)) {
                event.preventDefault();
                input.focus();
                return;
            }
            // Only normalize the visible value at submission, never while typing.
            input.value = normalize(input.value);
        }, true);
        form.addEventListener('reset', () => {
            touched = false;
            window.setTimeout(validate, 0);
        });
        window.addEventListener('pageshow', () => window.setTimeout(validate, 0));
        validate();
    });
})();
