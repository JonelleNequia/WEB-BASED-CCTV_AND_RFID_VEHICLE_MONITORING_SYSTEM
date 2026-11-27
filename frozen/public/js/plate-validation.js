/* Field validation only: matching, eligibility and Review decisions stay server-side. */
(function () {
    const rules = globalThis.PhilcstPlate;
    const selector = '[data-normalize-plate]';
    const states = new WeakMap();

    function state(input) {
        if (states.has(input)) return states.get(input);
        const review = input.matches('[data-review-plate-input]');
        const correction = input.form?.matches('[data-visitor-form="plate"]');
        const container = review ? input.closest('.review-inline') : input.closest('.field') || input.parentElement;
        const error = container.querySelector('.field-error:not([data-plate-error])') || document.createElement('span');
        error.classList.add('field-error');
        error.dataset.plateError = '';
        error.id ||= (input.id || input.name) + '-plate-error';
        error.setAttribute('aria-live', 'polite');
        if (!error.isConnected) {
            error.hidden = true;
            if (review) container.after(error);
            else container.append(error);
        }
        input.setAttribute('aria-describedby', [...new Set(((input.getAttribute('aria-describedby') || '') + ' ' + error.id).trim().split(/\s+/))].join(' '));
        const value = { error, review, correction, composing: false, touched: false, initialValue: input.value, serverError: !error.hidden && error.textContent.trim() };
        states.set(input, value);
        return value;
    }

    function message(input) {
        const value = rules.normalize(input.value);
        const info = state(input);
        if (!value) return input.required || info.review || info.correction ? 'Enter a plate containing letters or digits.' : '';
        if (input.dataset.plateLegacy && value === rules.normalize(input.dataset.plateLegacy)) return '';
        const key = rules.key(value);
        if (!key) return 'Use letters, digits, spaces or hyphens only. Unsupported symbols and punctuation-only plates are not allowed.';
        if (key.length > 20) return 'The plate may contain at most 20 letters and digits, excluding separators.';
        if (rules.display(value).length > 30) return 'The formatted plate must not exceed 30 characters.';
        const maximum = input.maxLength > 0 ? input.maxLength : 50;
        if (value.length > maximum) return `The plate must not exceed ${maximum} characters.`;
        return '';
    }

    function validate(input) {
        const info = state(input);
        const problem = message(input);
        // Multi-action Review/correction forms must retain their existing other actions.
        input.setCustomValidity(info.review || info.correction ? '' : info.composing ? 'Finish entering the plate.' : problem);
        const visible = info.serverError || (info.touched ? problem : '');
        info.error.textContent = visible || '';
        info.error.hidden = !visible;
        input.setAttribute('aria-invalid', visible ? 'true' : 'false');
        if (info.review || info.correction) {
            const button = info.review ? input.form.querySelector('[data-review-enter]') : input.form.querySelector('button[type="submit"]:not([name="unreadable"])');
            if (button && input.form.getAttribute('aria-busy') !== 'true') button.disabled = !!problem || info.composing;
        }
        input.form?.dispatchEvent(new Event('plate:validity'));
        return !problem;
    }

    document.addEventListener('compositionstart', event => {
        if (event.target.matches?.(selector)) { state(event.target).composing = true; validate(event.target); }
    }, true);
    document.addEventListener('compositionend', event => {
        if (event.target.matches?.(selector)) {
            const info = state(event.target); info.composing = false; info.touched = true; info.serverError = ''; validate(event.target);
        }
    }, true);
    for (const name of ['input', 'change', 'blur']) {
        document.addEventListener(name, event => {
            const input = event.target;
            if (!input.matches?.(selector) || state(input).composing || event.isComposing) return;
            const info = state(input); info.touched = true; info.serverError = ''; validate(input);
        }, true);
    }
    document.addEventListener('invalid', event => {
        if (!event.target.matches?.(selector)) return;
        event.preventDefault(); state(event.target).touched = true; validate(event.target);
    }, true);
    document.addEventListener('drawer:open', event => event.target.querySelectorAll(selector).forEach(input => {
        const info = state(input);
        if (info.initialValue !== input.value) { info.touched = false; info.serverError = ''; }
        info.initialValue = input.value; info.composing = false; validate(input);
    }));
    document.addEventListener('submit', event => {
        const inputs = [...event.target.querySelectorAll(selector)];
        for (const input of inputs) {
            const info = state(input);
            if (info.review && event.submitter?.value !== 'correct_plate') continue;
            if (info.correction && event.submitter?.name === 'unreadable') continue;
            info.touched = true; info.serverError = '';
            if (!validate(input) || info.composing) {
                event.preventDefault(); input.focus();
            }
        }
    }, true);
    document.querySelectorAll(selector).forEach(validate);
})();
