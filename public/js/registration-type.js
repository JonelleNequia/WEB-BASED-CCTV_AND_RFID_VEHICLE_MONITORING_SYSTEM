// Registry type specification; the detector's supported model classes are unchanged.
(() => {
    document.querySelectorAll('[data-other-select]').forEach(select => {
        const field = document.getElementById(select.dataset.otherTarget);
        if (!field?.hasAttribute('data-registration-type-other')) return;
        const box = field.closest('.field');
        const label = box.querySelector('[data-type-specify-label]');
        const error = box.querySelector('[data-type-specify-error]');
        let touched = Boolean(error?.textContent.trim());
        let composing = false;
        function sync() {
            const other = select.value === 'Others';
            field.hidden = !other;
            field.required = other;
            if (label) label.hidden = !other;
            const value = field.value.trim().replace(/\s+/gu, ' ');
            const unchanged = field.dataset.typeLegacy && field.value === field.dataset.typeLegacy;
            let message = '';
            if (other && !unchanged) {
                if (composing) message = 'Finish entering the vehicle type.';
                else if (!value) message = 'Specify the vehicle type when choosing Others.';
                else if ([...field.value].length > 50 || !/\p{L}/u.test(field.value)
                    || !/^[\p{L}\p{M}\p{N} .,'’()/&+\-]+$/u.test(field.value)
                    || ['others', 'unknown'].includes(value.toLowerCase())) {
                    message = 'Specify a vehicle type using letters, numbers, spaces or common punctuation (up to 50 characters).';
                }
            }
            field.setCustomValidity(message);
            const show = Boolean(message) && touched && !composing;
            field.setAttribute('aria-invalid', show ? 'true' : 'false');
            if (error) { error.textContent = show ? message : ''; error.hidden = !show; }
            field.form?.dispatchEvent(new Event('registration:validity'));
        }
        select.addEventListener('change', () => { touched = true; sync(); if (!field.hidden) field.focus(); });
        field.addEventListener('input', () => { if (!composing) { touched = true; sync(); } });
        field.addEventListener('blur', () => { touched = true; sync(); });
        field.addEventListener('compositionstart', () => { composing = true; sync(); });
        field.addEventListener('compositionend', () => { composing = false; touched = true; sync(); });
        field.addEventListener('type:sync', sync);
        field.form?.addEventListener('reset', () => { touched = false; composing = false; setTimeout(sync, 0); });
        sync();
    });
})();
