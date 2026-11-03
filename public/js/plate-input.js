/* Mirrors PlateNumber::normalize. Display grouping happens on the server. */
(function () {
    const selector = '[data-normalize-plate]';
    const composing = new WeakSet();
    function spaces(value) {
        return value.replace(/[a-z]/g, (letter) => letter.toUpperCase()).replace(/\p{Zs}+/gu, ' ');
    }
    function normalize(value) {
        const text = spaces(value).replace(/^ +| +$/g, '');
        if (/[^A-Z0-9 -]/.test(text)) return text;
        const compact = text.replace(/[ -]/g, '');
        for (const [letters, digits] of [[3, 4], [3, 3], [2, 5], [2, 4]]) {
            const match = compact.match(new RegExp('^(\\d{' + digits + '})([A-Z]{' + letters + '})$'));
            if (match) return match[2] + '-' + match[1];
        }
        return text;
    }
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
        event.target.querySelectorAll(selector).forEach((input) => apply(input, true));
        if ([...event.target.querySelectorAll(selector)].some((input) => composing.has(input))) event.preventDefault();
    }, true);
})();
