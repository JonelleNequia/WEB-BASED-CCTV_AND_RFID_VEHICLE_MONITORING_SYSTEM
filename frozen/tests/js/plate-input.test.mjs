import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

function harness(value) {
    class Input {
        constructor(text) { this.value = text; this.selectionStart = this.selectionEnd = text.length; }
        matches(selector) { return selector.includes('data-normalize-plate') || (this.review && selector === '[data-review-plate-input]'); }
        setSelectionRange(start, end, direction) { this.selectionStart = start; this.selectionEnd = end; this.selectionDirection = direction; }
    }
    const listeners = {};
    const document = { addEventListener: (name, callback) => { listeners[name] = callback; } };
    const context = { document, HTMLInputElement: Input };
    vm.runInNewContext(readFileSync(new URL('../../public/js/plate-input.js', import.meta.url), 'utf8'), context);
    return { rules: context.PhilcstPlate, input: new Input(value), send: (name, input, extras = {}) => listeners[name]({ target: input, ...extras }) };
}

test('input, blur and submit preserve literal character order', () => {
    for (const [value, expected] of [['395isx', '395ISX'], ['isx395', 'ISX395'], [' 395\u00a0 isx ', '395 ISX'], ['0012 ab', '0012 AB'], ['395/ISX', '395/ISX']]) {
        const h = harness(value);
        h.send('input', h.input);
        h.send('blur', h.input);
        assert.equal(h.input.value, expected);
        h.send('submit', { querySelectorAll: () => [h.input] });
        assert.equal(h.input.value, expected);
    }
});

test('the shared PHP/browser/Python corpus keeps literal keys and display separators', () => {
    const cases = JSON.parse(readFileSync(new URL('../fixtures/plate-contract.json', import.meta.url), 'utf8'));
    const { rules } = harness('');
    for (const item of cases) {
        assert.equal(rules.normalize(item.input) || null, item.normalized);
        assert.equal(rules.key(item.input), item.key);
        assert.equal(rules.display(item.input) || null, item.display);
    }
});

test('IME and insertion caret are preserved without transposing on commit', () => {
    const h = harness('395isx');
    h.input.selectionStart = h.input.selectionEnd = 4;
    h.send('compositionstart', h.input);
    h.send('input', h.input, { isComposing: true });
    assert.equal(h.input.value, '395isx');
    h.send('compositionend', h.input);
    assert.equal(h.input.value, '395ISX');
    assert.equal(h.input.selectionStart, 4);
});

test('unrelated Review actions do not wait on composition in the plate field', () => {
    const h = harness('395/ISX');
    h.input.review = true;
    h.send('compositionstart', h.input);
    let blocked = false;
    const form = { querySelectorAll: () => [h.input] };
    h.send('submit', form, { submitter: { value: 'no_plate' }, preventDefault() { blocked = true; } });
    assert.equal(blocked, false);
    h.send('submit', form, { submitter: { value: 'correct_plate' }, preventDefault() { blocked = true; } });
    assert.equal(blocked, true);
});
