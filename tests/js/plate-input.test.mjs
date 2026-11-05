import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

function harness(value) {
    class Input {
        constructor(text) { this.value = text; this.selectionStart = this.selectionEnd = text.length; }
        matches() { return true; }
        setSelectionRange(start, end, direction) { this.selectionStart = start; this.selectionEnd = end; this.selectionDirection = direction; }
    }
    const listeners = {};
    const document = { addEventListener: (name, callback) => { listeners[name] = callback; } };
    vm.runInNewContext(readFileSync(new URL('../../public/js/plate-input.js', import.meta.url), 'utf8'), { document, HTMLInputElement: Input });
    return { input: new Input(value), send: (name, input, extras = {}) => listeners[name]({ target: input, ...extras }) };
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
