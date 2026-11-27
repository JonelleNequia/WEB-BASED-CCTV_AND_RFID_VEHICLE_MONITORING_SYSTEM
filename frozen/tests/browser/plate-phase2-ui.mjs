import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { chromium } from 'playwright';

// Never accept a live installation URL or database for mutation tests.
const root = path.resolve(import.meta.dirname, '../..');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-plate-phase2-'));
fs.chmodSync(directory, 0o700);
const env = {
    ...process.env, APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_URL: '',
    DB_DATABASE: directory + '/ui.sqlite', SESSION_DRIVER: 'database', CACHE_STORE: 'array',
    LOG_CHANNEL: 'testing', CAMERA_FILES_PATH: directory + '/camera', DEVICE_FILES_PATH: directory + '/devices',
    ARGON_MEMORY: '1024', ARGON_TIME: '1',
    APP_KEY: 'base64:' + randomBytes(32).toString('base64'),
    APP_CONFIG_CACHE: directory + '/config.php', APP_ROUTES_CACHE: directory + '/routes.php',
};
let server;
let browser;
let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; };

try {
    const fixture = spawnSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/plate-phase2-ui.php', directory], {
        cwd: root, env, encoding: 'utf8', timeout: 30000,
    });
    assert.equal(fixture.status, 0, fixture.stderr || fixture.stdout);
    const port = await new Promise((resolve, reject) => {
        const socket = net.createServer();
        socket.on('error', reject);
        socket.listen(0, '127.0.0.1', () => {
            const value = socket.address().port;
            socket.close(() => resolve(value));
        });
    });
    const base = 'http://127.0.0.1:' + port;
    server = spawn(process.env.PHP_BINARY || 'php', ['-S', '127.0.0.1:' + port,
        path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], {
        cwd: path.join(root, 'public'), env: { ...env, APP_URL: base }, stdio: 'ignore',
    });
    for (let attempt = 0; attempt < 60; attempt++) {
        try { if ((await fetch(base + '/login', { signal: AbortSignal.timeout(2000) })).status === 200) break; } catch {}
        if (attempt === 59) throw new Error('Isolated browser server did not start.');
        await new Promise((resolve) => setTimeout(resolve, 200));
    }
    const macChrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
    browser = await chromium.launch({ headless: true, executablePath:
        process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || (fs.existsSync(macChrome) ? macChrome : undefined) });
    const context = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    await context.route('**/*', (route) => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    const credentials = JSON.parse(fs.readFileSync(directory + '/login.json', 'utf8'));
    await page.goto(base + '/login');
    await page.locator('[name=email]').fill(credentials.email);
    await page.locator('[name=password]').fill(credentials.password);
    await page.locator('button[type=submit]').click();
    await page.waitForURL(/\/admin$/);
    console.log('Isolated browser login passed.');

    const errors = []; page.on('pageerror', error => errors.push(error.message));
    const recordId = JSON.parse(fs.readFileSync(directory + '/plate-phase2.json', 'utf8')).record;
    const csrf = async () => ({ Accept: 'application/json', 'X-CSRF-TOKEN': await page.locator('meta[name=csrf-token]').getAttribute('content') });
    const artifacts = path.join(root, 'storage/app/ui-review/plate-phase2'); fs.mkdirSync(artifacts, { recursive: true });
    await page.goto(base + '/registry');
    for (const [input, stored] of [['abc 1234', 'ABC 1234'], ['abc 123', 'ABC 123'], ['123abc', '123ABC'], ['a123bc', 'A123BC'], ['ab-00123', 'AB-00123']]) {
        await page.waitForFunction(() => globalThis.PhilcstPlate && document.readyState === 'complete');
        await page.locator('[data-drawer-open=add-vehicle-drawer]').click();
        await page.waitForFunction(() => document.getElementById('add-vehicle-drawer').classList.contains('is-open'));
        await page.locator('#add_owner').fill('Ma. José Dela Cruz'); await page.locator('#add_registered_by').fill('Fixture Registrar');
        await page.locator('#add_vehicle_type').selectOption('Car'); await page.locator('#add_plate_number').fill(input);
        check(await page.locator('#add_plate_number').inputValue() === stored, 'Manual input uppercases without reordering: ' + input);
        const save = page.locator('[data-vehicle-form=add] button[type=submit]');
        await page.waitForFunction(() => !document.querySelector('[data-vehicle-form=add] button[type=submit]').disabled);
        check(await save.isEnabled(), 'Plausible plate format passes client validation: ' + input + ' ' + JSON.stringify(await page.locator('[data-vehicle-form=add]').evaluate(form => ({ busy: form.getAttribute('aria-busy'), invalid: [...form.elements].filter(input => input.willValidate && !input.validity.valid).map(input => ({ name: input.name, value: input.value, message: input.validationMessage })) }))));
        await save.click(); await page.getByText(stored + ' was saved to the local vehicle registry.', { exact: true }).waitFor();
        const url = await page.locator('[data-vehicle-row]').filter({ has: page.getByText(stored, { exact: true }) }).getAttribute('data-vehicle-url');
        const data = await (await page.request.get(url)).json(); check(data.plate_number === stored && data.tag === null, 'Stored plate and optional RFID are preserved');
    }
    await page.locator('[data-drawer-open=add-vehicle-drawer]').click();
    await page.locator('#add_owner').fill('Anne-Marie'); await page.locator('#add_vehicle_type').selectOption('Car');
    const plate = page.locator('#add_plate_number'); const save = page.locator('[data-vehicle-form=add] button[type=submit]');
    for (const value of ['395/ISX', '395_ISX', '395🚗ISX', '---', 'A'.repeat(21)]) {
        await plate.fill(value);
        check(await save.isDisabled() && await plate.getAttribute('aria-invalid') === 'true' && await plate.inputValue() === value, 'Unsupported/oversized input remains available with inline error');
    }
    await plate.evaluate(node => { node.value = '395/isx'; node.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertFromPaste' })); });
    check(await save.isDisabled() && await plate.inputValue() === '395/ISX', 'Paste uppercases but never strips invalid evidence');
    await page.locator('#add_plate_number-plate-error').scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(artifacts, 'registry-plate-error.png'), fullPage: true });
    await plate.fill('A12BC3'); check(await save.isEnabled() && await plate.getAttribute('aria-invalid') === 'false', 'Correcting invalid input clears inline validity');
    await plate.evaluate(node => { node.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true })); node.value = 'a123bc'; node.dispatchEvent(new InputEvent('input', { bubbles: true, isComposing: true })); });
    check(await plate.inputValue() === 'a123bc' && await save.isDisabled(), 'IME composition preserves in-progress text and blocks incomplete save');
    await plate.evaluate(node => node.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true })));
    check(await plate.inputValue() === 'A123BC' && await save.isEnabled(), 'IME completion uppercases committed text and recovers');
    await page.locator('#add-vehicle-drawer [data-drawer-close]').filter({ hasText: 'Cancel' }).click();
    await page.locator('#vehicle_q').fill('395');
    await page.waitForFunction(() => document.querySelector('[data-auto-list]').dataset.autoState === 'ready');
    await page.locator('[data-vehicle-row]').filter({ hasText: '395ISX' }).locator('.row-menu summary').click();
    await page.getByRole('menuitem', { name: 'Edit details', exact: true }).click();
    await page.locator('#edit_plate_number').fill('395isx');
    check(await page.locator('#edit_plate_number').inputValue() === '395ISX', 'Editing preserves number-first literal order');
    await page.locator('[data-vehicle-form=edit] button[type=submit]').click(); await page.getByText('395ISX was updated.', { exact: true }).waitFor();
    await page.goto(base + '/visitors');
    await page.getByRole('button', { name: 'Add guest manually', exact: true }).click();
    await page.locator('#manual_plate').fill('zx/00123');
    await page.getByRole('button', { name: 'Save guest', exact: true }).click();
    check(await page.locator('#manual_plate').getAttribute('aria-invalid') === 'true'
        && await page.locator('#manual_plate').inputValue() === 'ZX/00123', 'Guests manual entry retains invalid text and blocks native submission');
    await page.locator('#manual_plate-plate-error').scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(artifacts, 'guests-plate-error.png'), fullPage: true });
    await page.locator('#manual_plate').fill('zx-00123');
    check(await page.locator('#manual_plate').getAttribute('aria-invalid') === 'false', 'Guests manual entry clears the field error after correction');
    await page.getByRole('button', { name: 'Save guest', exact: true }).click();
    await page.getByText('Unregistered guest ZX 00123 recorded IN at Gate 1.', { exact: true }).waitFor();
    const guest = page.locator('tr[id^="visitor-record-"]').filter({ hasText: 'ZX 00123' });
    const guestId = await guest.getAttribute('id');
    await guest.locator('.row-menu summary').click();
    await guest.getByRole('menuitem', { name: 'Correct plate', exact: true }).click();
    await page.locator('#visitor_plate_value').fill('z x - 0 0 1 2 3');
    await page.locator('[data-visitor-form=plate] button[type=submit]:not([name=unreadable])').click();
    await page.getByText('Record #' + guestId.split('-').pop() + ' plate set to ZX 00123.', { exact: true }).waitFor();
    check(await page.locator('#' + guestId).count() === 1, 'Guests correction keeps its original record and normalized separators');
    await page.locator('#visitor_q').fill('zx001');
    check(await page.locator('#visitor_q').inputValue() === 'ZX001', 'Plate-only Guests search uppercases without imposing identity validation');
    await page.waitForFunction(() => document.querySelector('[data-auto-list]').dataset.autoState === 'ready');
    check(await page.locator('tr[id^="visitor-record-"]').count() === 1, 'Guests partial plate search uses normalized keys');
    await page.locator('#visitor_gate').selectOption('gate-2');
    await page.getByText('No guests match these filters.', { exact: true }).waitFor();
    check(await page.locator('tr[id^="visitor-record-"]').count() === 0, 'Guests plate search combines with the gate criterion');
    await page.goto(base + '/visitors?tab=review');
    const review = page.locator(`[data-review-card][data-record-id="${recordId}"]`);
    const reviewInput = page.locator('[data-review-plate-input]').first();
    await reviewInput.fill('395/ISX');
    check(await page.locator('[data-review-enter]').first().isDisabled() && await reviewInput.inputValue() === '395/ISX', 'Review correction rejects unsupported characters inline');
    await page.screenshot({ path: path.join(artifacts, 'review-plate-error.png'), fullPage: true });
    await reviewInput.fill('395isx');
    check(await reviewInput.inputValue() === '395ISX' && await page.locator('[data-review-enter]').first().isEnabled(), 'Review correction recovers without reordering');
    await page.locator('[data-review-enter]').first().click();
    await page.waitForFunction(() => document.querySelector('[data-review-queue]')?.dataset.remaining === '0' || [...document.querySelectorAll('[data-review-card]')].every(node => node.hidden));
    await page.goto(base + '/logs?plate_text=395isx');
    await page.locator('#plate_text').fill('395isx');
    check(await page.locator('#plate_text').inputValue() === '395ISX', 'Activity Logs plate search uppercases without transposing');
    const rows = await page.locator('#event-log-modal-data').evaluate(node => JSON.parse(node.textContent));
    const event = rows.find(row => row.record_type === 'vehicle_event');
    check(rows.length === 1 && event?.plate_number === '395ISX', 'Literal correction produces one canonical registered occurrence');
    await page.request.patch(base + '/visitors/records/' + recordId + '/review', { headers: await csrf(), data: { action: 'correct_plate', plate: '395isx' } });
    const retry = await (await page.request.get(base + '/logs?plate_text=395isx', { headers: { Accept: 'application/json' } })).json();
    check(retry.logs.length === 1 && retry.logs[0].id === event.id, 'Retry retains the same event ID and count');
    await page.locator('#gate').selectOption('gate-2'); await page.getByText('No records matched the current filters', { exact: true }).waitFor();
    check(await page.locator('[data-event-log-list] [data-event-log-view]').count() === 0, 'Plate search combines with the gate filter');
    await page.goto(base + '/registry?q=00123');
    check(await page.locator('[data-vehicle-row]').count() === 2, 'Partial search retains zeros and keeps O/0 and I/1 candidates distinct');
    await page.goto(base + '/registry?q=ao-0123'); check(await page.locator('[data-vehicle-row]').count() === 1, 'O is distinct from zero under separator-equivalent search');
    await page.goto(base + '/registry?q=ai-0123'); check(await page.locator('[data-vehicle-row]').count() === 1, 'I is distinct from one under separator-equivalent search');
    await page.screenshot({ path: path.join(artifacts, 'literal-search.png'), fullPage: true });
    check(errors.length === 0, 'No browser script errors');
    console.log(`Plate Phase 2 browser checks: ${checks} passed.`);
} finally {
    if (browser) await browser.close();
    if (server) server.kill('SIGTERM');
    fs.rmSync(directory, { recursive: true, force: true });
}
