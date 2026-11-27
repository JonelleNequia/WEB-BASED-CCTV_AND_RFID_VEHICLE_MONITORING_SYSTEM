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
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-manual-entry-'));
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
    const fixture = spawnSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/manual-entry-ui.php', directory], {
        cwd: root, env, encoding: 'utf8', timeout: 30000,
    });
    assert.equal(fixture.status, 0, fixture.stderr || fixture.stdout);
    assert.ok(fs.existsSync(directory + '/manual-entry.json'), fixture.stderr || fixture.stdout);
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

    const ids = JSON.parse(fs.readFileSync(directory + '/manual-entry.json', 'utf8'));
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    const artifacts = path.join(root, 'storage/app/ui-review/manual-entry'); fs.mkdirSync(artifacts, { recursive: true });
    const guest = page.locator('#add-visitor-drawer');
    const plate = guest.locator('#manual_plate');
    const saveGuest = guest.getByRole('button', { name: 'Save guest', exact: true });
    const guestPosts = [];
    page.on('request', request => { if (request.method() === 'POST' && new URL(request.url()).pathname === '/visitors/records') guestPosts.push(request.url()); });
    const openGuest = async () => {
        await page.getByRole('button', { name: 'Add guest manually', exact: true }).click();
        await guest.waitFor({ state: 'visible' });
        await page.waitForFunction(() => getComputedStyle(document.querySelector('#add-visitor-drawer .drawer-panel')).opacity === '1');
        await guest.locator('[name=seen_at]').fill(ids.guest_time);
    };
    const save = async () => { await Promise.all([page.waitForEvent('load'), saveGuest.click()]); };
    const logs = async query => (await (await page.request.get(base + '/logs?' + new URLSearchParams(query), { headers: { Accept: 'application/json' } })).json()).logs;
    await page.goto(base + '/visitors');
    check(await page.locator('#visitor-record-' + ids.historical).count() === 1, 'Historical Guest remains in Guests after later registration');
    await openGuest();
    check(await plate.getAttribute('required') === null && await guest.locator('#manual_type').getAttribute('required') === null,
        'Manual Guest Plate Number and Vehicle Type remain optional');
    check(await plate.inputValue() === '' && await guest.locator('#manual_type').inputValue() === '' && await guest.locator('form').evaluate(form => form.checkValidity()),
        'Blank optional fields leave the manual Guest form valid');
    await save();
    const blank = (await logs({})).find(row => row.record_type === 'visitor_record' && row.plate_number === 'Plate unreadable');
    check(Boolean(blank) && blank.vehicle_type === 'Unknown' && blank.log_type === 'review', 'Blank plate/type saves with the existing Review classification and Unknown type');
    check((await page.locator('body').innerText()).includes('Review record (no plate) recorded IN'), 'Blank-plate feedback accurately describes Review without relabelling history');
    await openGuest();
    await plate.fill('gst/0001');
    const before = guestPosts.length;
    await saveGuest.click();
    check(guestPosts.length === before && await plate.inputValue() === 'GST/0001' && await plate.getAttribute('aria-invalid') === 'true', 'Invalid supplied plate stays available for correction and cannot submit');
    check(await guest.locator('#manual_plate-plate-error').isVisible() && (await guest.locator('#manual_plate-plate-error').innerText()).includes('letters'),
        'Invalid Guest plate has clear inline feedback');
    await page.screenshot({ path: path.join(artifacts, 'guest-plate-error.png') });
    await plate.fill('');
    check(await guest.locator('form').evaluate(form => form.checkValidity()) && await guest.locator('#manual_plate-plate-error').isHidden(), 'Clearing the optional invalid plate restores validity and clears its error');
    await plate.evaluate(input => {
        input.value = 'gst\u00a00001'; input.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertFromPaste', data: input.value }));
    });
    check(await plate.inputValue() === 'GST 0001' && await plate.evaluate(input => input.checkValidity()), 'Pasted separator/case normalization follows the shared plate contract');
    await plate.evaluate(input => input.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true })));
    check(await plate.evaluate(input => !input.checkValidity()), 'IME composition prevents premature manual Guest submission');
    await plate.evaluate(input => {
        input.value = 'gst0001'; input.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true }));
    });
    check(await plate.inputValue() === 'GST0001' && await plate.evaluate(input => input.checkValidity()), 'Committed IME input recovers without altering alphanumeric order');
    await save();
    check((await page.locator('body').innerText()).includes('Unregistered guest GST 0001 recorded IN'), 'Confirmed unmatched plate saves as a Guest with optional blank type');
    const manualGuest = (await logs({ plate_text: 'GST0001' })).find(row => row.record_type === 'visitor_record');
    check(manualGuest?.log_type === 'unregistered_visitor', 'Activity Logs agrees with the saved manual Guest classification');

    // Bypass browser validation to exercise the HTML server error path.
    const csrf = await page.locator('meta[name=csrf-token]').getAttribute('content');
    const bad = await page.request.post(base + '/visitors/records', { maxRedirects: 0,
        headers: { 'X-CSRF-TOKEN': csrf, Accept: 'text/html', Referer: base + '/visitors' },
        form: { _form: 'manual-guest', gate: 'gate-1', direction: 'IN', seen_at: ids.guest_time, plate_number: 'BAD/123', vehicle_type: '', note: 'Keep this note' } });
    check(bad.status() === 302, 'Direct invalid HTML request redirects with validation feedback');
    await page.goto(base + '/visitors'); await guest.waitFor({ state: 'visible' });
    check(await plate.inputValue() === 'BAD/123' && await guest.locator('#manual_note').inputValue() === 'Keep this note'
        && await guest.locator('#manual_plate-plate-error').isVisible(), 'Server failure reopens the Guest drawer with inline error and entered values');
    check((await logs({ plate_text: 'BAD123' })).length === 0, 'Invalid server request creates no occurrence or false match');
    await plate.fill('395isx');
    check(await plate.inputValue() === '395ISX' && await guest.locator('#manual_plate-plate-error').isHidden(), 'Server-error correction retains literal number-first order');
    await save();
    check((await page.locator('body').innerText()).includes('Registered vehicle 395ISX recorded IN')
        && (await page.locator('body').innerText()).includes('View this movement in Activity Logs.'), 'Registered manual movement receives accurate feedback and destination guidance');
    check(await page.locator('.guest-records-table tbody tr').filter({ hasText: '395ISX' }).count() === 0, 'Eligible registered plate without RFID is excluded from Guests');
    const registered = await logs({ plate_text: '395ISX' });
    check(registered.length === 1 && registered[0].log_type === 'registered', 'Registered manual occurrence appears once in Activity Logs');
    const historical = (await logs({ plate_text: 'HIS1001' })).find(row => row.id === ids.historical && row.record_type === 'visitor_record');
    check(historical?.log_type === 'unregistered_visitor', 'Earlier Guest classification is preserved after later registry edits');

    await page.goto(base + '/logs');
    await page.getByRole('link', { name: 'Quick Manual Log', exact: true }).click(); await page.waitForURL(/\/vehicle-events\/create$/);
    check(await page.getByLabel('Plate Confidence', { exact: true }).count() === 0 && await page.getByLabel('Station / ROI', { exact: true }).count() === 0,
        'Quick Manual Log removes both visible controls');
    check(await page.locator('[name=roi_name], [name=plate_confidence]').count() === 0, 'Removed fields are not sent as fabricated hidden inputs');
    check(await page.getByLabel('Date and Time', { exact: true }).getAttribute('type') === 'datetime-local', 'Chosen occurrence input has the Date and Time label');
    await page.locator('#vehicle_type').selectOption('Car');
    await page.locator('#camera_id').selectOption(String(ids.camera));
    await page.getByLabel('Date and Time', { exact: true }).fill(ids.chosen_time);
    await page.locator('#plate_text').fill('qck/0001');
    await page.getByRole('button', { name: 'Save Manual Log', exact: true }).click();
    check(new URL(page.url()).pathname.endsWith('/create') && await page.locator('#plate_text').getAttribute('aria-invalid') === 'true', 'Remaining Quick Manual plate validation still blocks invalid text');
    await page.locator('#plate_text').fill('qck0001');
    check(await page.locator('#plate_text').inputValue() === 'QCK0001', 'Quick Manual plate correction uppercases without transposing');
    await page.screenshot({ path: path.join(artifacts, 'quick-manual-desktop.png'), fullPage: true });
    await Promise.all([page.waitForURL(/\/vehicle-events\/\d+$/), page.getByRole('button', { name: 'Save Manual Log', exact: true }).click()]);
    const saved = await logs({ plate_text: 'QCK0001', date_from: '2026-11-03', date_to: '2026-11-03', time_from: '08:17', time_to: '08:17' });
    check(saved.length === 1 && saved[0].event_time_export === '2026-11-03 08:17:00', 'Quick Manual saves once with exactly the chosen Philippine occurrence date/time');
    check(saved[0].vehicle_type_source === 'Manual entry' && saved[0].rfid_tag_uid === 'N/A', 'Manual type provenance and no fabricated RFID evidence are preserved');
    check((await page.locator('body').innerText()).includes('QCK0001'), 'Successful save opens the existing full occurrence details');
    for (const width of [390, 320]) {
        await page.setViewportSize({ width, height: 844 }); await page.goto(base + '/vehicle-events/create');
        check(await page.getByLabel('Date and Time', { exact: true }).isVisible() && await page.locator('[name=roi_name], [name=plate_confidence]').count() === 0,
            width + 'px Quick Manual layout retains remaining controls');
        check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), width + 'px Quick Manual layout does not overflow');
        await page.screenshot({ path: path.join(artifacts, 'quick-manual-' + width + '.png'), fullPage: true });
        await page.goto(base + '/visitors'); await openGuest(); await plate.fill('INVALID/PLATE');
        check(await guest.locator('#manual_plate-plate-error').isVisible(), width + 'px Guest drawer keeps invalid-plate feedback readable');
        await page.screenshot({ path: path.join(artifacts, 'guest-plate-' + width + '.png') });
        await guest.getByRole('button', { name: 'Cancel', exact: true }).click();
    }
    await page.setViewportSize({ width: 1366, height: 768 }); await page.goto(base + '/registry');
    await page.getByRole('button', { name: 'Add Vehicle', exact: true }).first().click();
    check(await page.locator('#add_registered_by').inputValue() === 'Fixture Staff', 'Successful and failed manual log operations preserve the remembered registration staff name');
    check(await page.locator('#add_owner').getAttribute('required') !== null && await page.locator('#add_available option').first().innerText() === 'No RFID assigned',
        'Required new Owner Name and optional RFID remain unchanged');
    check(errors.length === 0, 'No manual-form browser script errors');
    console.log(`Manual entry cleanup browser checks: ${checks} passed.`);
} finally {
    if (browser) await browser.close();
    if (server) server.kill('SIGTERM');
    fs.rmSync(directory, { recursive: true, force: true });
}

