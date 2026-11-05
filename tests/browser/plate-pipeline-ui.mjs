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
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-plates-'));
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
    const fixture = spawnSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/plate-pipeline-ui.php', directory], {
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

    const ids = JSON.parse(fs.readFileSync(directory + '/plate-records.json', 'utf8'));
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const headers = { 'X-CSRF-TOKEN': await page.locator('meta[name=csrf-token]').getAttribute('content'), Accept: 'application/json' };
    const ready = () => page.waitForFunction(() => document.querySelector('[data-auto-list]')?.dataset.autoState === 'ready');
    const rows = () => page.locator('#event-log-modal-data').evaluate((node) => JSON.parse(node.textContent));

    await page.goto(base + '/registry');
    await page.locator('[data-drawer-open="add-vehicle-drawer"]').first().click();
    await page.waitForFunction(() => document.getElementById('add-vehicle-drawer').classList.contains('is-open'));
    const plate = page.locator('#add_plate_number');
    await plate.fill('  ab   00001  ');
    check(await plate.inputValue() === ' AB 00001 ', 'Typing uppercases and collapses repeated spaces without trimming a pending trailing space');
    await plate.evaluate((input) => {
        input.value = 'ab 00001';
        input.setSelectionRange(2, 2);
        input.dispatchEvent(new InputEvent('input', { bubbles: true }));
    });
    check(await plate.inputValue() === 'AB 00001' && await plate.evaluate((input) => input.selectionStart) === 2, 'Normalization preserves the insertion caret');
    await plate.evaluate((input) => {
        input.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true }));
        input.value = 'ab';
        input.dispatchEvent(new InputEvent('input', { bubbles: true, isComposing: true }));
    });
    check(await plate.inputValue() === 'ab', 'IME text is not rewritten during composition');
    await plate.evaluate((input) => input.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true })));
    check(await plate.inputValue() === 'AB', 'IME completion normalizes the committed ASCII letters');
    await plate.fill(' 00001 ab ');
    await page.locator('#add_registered_by').focus();
    check(await plate.inputValue() === '00001 AB', 'Blur preserves literal number-first order');
    await plate.fill('ab/00001');
    check(await plate.inputValue() === 'AB/00001', 'Unsupported symbols are retained for validation instead of stripped');
    check(await page.locator('#add_registered_by').inputValue() === 'Fixture Registrar', 'Phase 1 remembered staff default remains intact');
    await page.keyboard.press('Escape');

    await page.goto(base + '/logs');
    let items = await rows();
    const occurrence = (id) => items.find((row) => (row.record_type === 'visitor_record' && row.id === id)
        || (row.record_type === 'vehicle_event' && row.visitor_record_id === id));
    check(items.length === 3 && occurrence(ids.readable).log_type === 'registered', 'Reliable exact OCR identifies registration and shows one row per crossing');
    check(occurrence(ids.review).log_type === 'review' && occurrence(ids.unmatched).log_type === 'review', 'Uncertain OCR stays Review instead of Guest or Registered');
    const before = await (await page.request.get(base + '/api/recent-station-logs')).json();
    check(before.logs.filter((row) => row.registered).length === 1, 'Monitoring agrees with the initial OCR classification');

    await page.goto(base + '/visitors?tab=review');
    let card = page.locator('#review-card-' + ids.review);
    // Review navigation starts with its newest card; keyboard J selects the next if necessary.
    for (let attempt = 0; attempt < 3 && !(await card.isVisible()); attempt++) await page.keyboard.press('j');
    await card.waitFor({ state: 'visible' });
    await card.locator('[data-review-plate-input]').fill('---');
    await card.getByRole('button', { name: 'Save plate', exact: true }).last().click();
    await card.locator('[data-review-error]').waitFor({ state: 'visible' });
    check((await card.locator('[data-review-error]').innerText()).includes('plate'), 'Invalid manual plate returns a recoverable inline server error');
    await card.locator('[data-review-plate-input]').fill('ab   00001');
    check(await card.locator('[data-review-plate-input]').inputValue() === 'AB 00001', 'Manual correction uses the shared input normalization');
    await card.getByRole('button', { name: 'Save plate', exact: true }).last().click();
    await card.waitFor({ state: 'detached' });
    check(await page.locator('[data-review-flash]').isVisible(), 'Successful correction gives feedback and removes the resolved card');

    card = page.locator('#review-card-' + ids.unmatched);
    for (let attempt = 0; attempt < 3 && !(await card.isVisible()); attempt++) await page.keyboard.press('j');
    await card.waitFor({ state: 'visible' });
    await card.locator('[data-review-plate-input]').fill('temp-0007');
    await card.getByRole('button', { name: 'Save plate', exact: true }).last().click();
    await card.waitFor({ state: 'detached' });
    check(await page.locator('[data-review-done]').isVisible(), 'A valid unmatched manual identifier resolves through the existing Guest workflow');

    await page.goto(base + '/logs');
    items = await rows();
    check(items.length === 3 && occurrence(ids.review).log_type === 'registered', 'Correction reclassifies the existing log without duplicating the crossing');
    check(occurrence(ids.unmatched).log_type === 'unregistered_visitor', 'Unmatched manual correction is consistently an Unregistered Guest');
    await page.locator('[aria-label="Log type"]').getByRole('link', { name: 'Registered', exact: true }).click();
    await ready();
    check((await rows()).length === 2, 'Automatic registration filter uses the corrected classification');
    const normalizedResponse = page.waitForResponse((response) => response.request().headers()['x-auto-filter'] === '1'
        && new URL(response.url()).searchParams.get('plate_text') === 'ab00001');
    await page.locator('[name=plate_text]').fill('ab00001');
    await normalizedResponse;
    await ready();
    check((await rows()).length === 2, 'Normalized plate search finds the same two registered passes');
    const csv = await page.request.get(base + '/vehicle-events/export/csv?all=1&log_type=registered&plate_text=ab00001');
    check(csv.status() === 200 && (await csv.text()).includes('AB 00001') && !(await csv.text()).includes('TEMP-0007'), 'CSV agrees with corrected registration and normalized filters');
    const print = await context.newPage();
    await print.goto(base + '/logs/print?autoprint=0&log_type=registered&plate_text=AB00001');
    check((await print.locator('body').innerText()).includes('AB 00001') && !(await print.locator('body').innerText()).includes('TEMP-0007'), 'Printed report agrees with corrected registration');
    await print.close();
    await page.bringToFront();
    const retry = await page.request.patch(base + '/visitors/records/' + ids.review + '/review', { headers, data: { action: 'correct_plate', plate: 'AB-00001' } });
    check(retry.status() === 200, 'Retrying the same correction succeeds idempotently');
    await page.goto(base + '/visitors');
    const correctedRow = page.locator('#visitor-record-' + ids.review);
    check((await correctedRow.innerText()).includes('Registered vehicle · plate identified'), 'Visitor history shows the registered classification');
    check((await correctedRow.innerText()).includes('camera read A8-00001'), 'Original OCR evidence remains visible after correction');
    const feed = await (await page.request.get(base + '/api/recent-station-logs')).json();
    check(feed.logs.filter((row) => row.record_type === 'visitor_record' || row.visitor_record_id).length === 3
        && feed.logs.filter((row) => row.registered).length === 2, 'Live monitoring contains one corrected row per crossing');
    await page.goto(base + '/registry');
    check(await page.locator('#add_registered_by').inputValue() === 'Fixture Registrar', 'Review never changes the Phase 1 default');
    check(errors.length === 0, 'Browser reports no script errors');
    console.log(`Plate pipeline browser checks: ${checks} passed.`);
} finally {
    if (browser) await browser.close();
    if (server) server.kill('SIGTERM');
    fs.rmSync(directory, { recursive: true, force: true });
}

