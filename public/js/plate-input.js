/* Mirrors PlateNumber::normalize. Display grouping happens on the server. */
(function () {
    const selector = '[data-normalize-plate], [data-normalize-plate-search]';
    const composing = new WeakSet();
    function spaces(value) {
        return value.replace(/[a-z]/g, (letter) => letter.toUpperCase()).replace(/\p{Zs}+/gu, ' ');
    }
    function normalize(value) {
        return spaces(value).replace(/^ +| +$/g, '');
    }
    function key(value) {
        const text = normalize(value);
        return text && !/[^A-Z0-9 -]/.test(text) ? text.replace(/[ -]/g, '') || null : null;
    }
    function display(value) {
        const compact = key(value);
        const grouped = compact?.match(/^([A-Z]{2,3})([0-9]{3,5})$/);
        return grouped ? grouped[1] + ' ' + grouped[2] : normalize(value);
    }
    globalThis.PhilcstPlate = { normalize, key, display };
    function apply(input, final = false) {
        if (!(input instanceof HTMLInputElement) || !input.matches(selector) || composing.has(input)) return;
        const start = input.selectionStart;
        const end = input.selectionEnd;
        const before = input.value;
        const next = final ? normalize(before) : spaces(before);
        if (next === before) return;
        const direction = input.selectionDirection;
        input.value = next;
        if (!final && start !== null) input.setSelectionRange(spaces(before.slice(0, start)).length,
            spaces(before.slice(0, end)).length, direction);
    }
    document.addEventListener('compositionstart', (event) => composing.add(event.target), true);
    document.addEventListener('compositionend', (event) => { composing.delete(event.target); apply(event.target); }, true);
    document.addEventListener('input', (event) => { if (!event.isComposing) apply(event.target); }, true);
    document.addEventListener('change', (event) => apply(event.target, true), true);
    document.addEventListener('blur', (event) => apply(event.target, true), true);
    document.addEventListener('submit', (event) => {
        const inputs = [...event.target.querySelectorAll(selector)].filter(input => {
            if (input.matches('[data-review-plate-input]') && event.submitter?.value !== 'correct_plate') return false;
            if (input.form?.matches('[data-visitor-form="plate"]') && event.submitter?.name === 'unreadable') return false;
            return true;
        });
        inputs.forEach((input) => apply(input, true));
        if (inputs.some((input) => composing.has(input))) event.preventDefault();
    }, true);
})();
