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
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-sticker-tags-'));
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
    const fixture = spawnSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/sticker-tags-ui.php', directory], {
        cwd: root, env, encoding: 'utf8', timeout: 30000,
    });
    assert.equal(fixture.status, 0, fixture.stderr || fixture.stdout);
    assert.ok(fs.existsSync(directory + '/sticker-tags.json'), fixture.stderr || fixture.stdout);
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

    const fixtureData = JSON.parse(fs.readFileSync(directory + '/sticker-tags.json', 'utf8'));
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    const artifacts = path.join(root, 'storage/app/ui-review/sticker-tags'); fs.mkdirSync(artifacts, { recursive: true });
    const drawer = page.locator('#add-tag-drawer');
    const number = drawer.locator('[name=display_number]');
    const uid = drawer.locator('[name=uid]');
    const save = drawer.locator('[data-sticker-save]');
    const open = async () => {
        await page.getByRole('button', { name: 'Add RFID Tag', exact: true }).click(); await drawer.waitFor({ state: 'visible' });
        await page.waitForFunction(() => {
            const panel = document.querySelector('#add-tag-drawer .drawer-panel');
            return getComputedStyle(panel).opacity === '1' && panel.getBoundingClientRect().right <= innerWidth + 1;
        });
    };
    const close = async () => { await drawer.getByRole('button', { name: 'Cancel', exact: true }).click(); await drawer.waitFor({ state: 'hidden' }); };
    const submit = async () => {
        const navigation = page.waitForURL(url => url.pathname === '/registry' && url.searchParams.get('tab') === 'tags', { waitUntil: 'load' });
        await Promise.all([page.waitForEvent('load'), save.click()]); await navigation;
    };
    await page.goto(base + '/registry?tab=tags');
    check(await page.getByRole('button', { name: 'Register Tags', exact: true }).count() >= 1, 'Existing bulk registration remains available');
    check((await page.locator('.registry-tags-table').innerText()).includes('#G-01'), 'Historical nonnumeric display label stays readable without being rewritten');
    await open();
    check(await save.isDisabled() && await number.getAttribute('type') === 'text', 'Tag number uses text to preserve zeros and blank required inputs block submission');
    check(await number.getAttribute('aria-describedby') === 'sticker_number_help sticker_number_error', 'Printed number has accessible help and inline error association');
    await uid.fill('0000epc789');
    for (const value of ['0', '000000', '123/4', '12.5', '1e3', '-12', '12 3', 'ABC123', '１２３', '123😀', '1000000', '000000000000000000001']) {
        await number.fill(value);
        check(await save.isDisabled() && await number.getAttribute('aria-invalid') === 'true'
            && await drawer.locator('#sticker_number_error').isVisible(), 'Invalid tag number rejected inline: ' + value);
    }
    check(await number.inputValue() === '000000000000000000001', 'Oversized pasted number is retained for correction rather than silently truncated');
    await number.evaluate(input => {
        input.value = '000123';
        input.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertFromPaste', data: '000123' }));
    });
    check(await number.inputValue() === '000123' && await save.isEnabled() && await drawer.locator('#sticker_number_error').isHidden(), 'Corrected paste retains zeros, clears inline error and enables save');
    await number.evaluate(input => input.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true })));
    check(await save.isDisabled(), 'IME composition blocks premature submission');
    await number.evaluate(input => {
        input.value = '１２３'; input.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true }));
    });
    check(await save.isDisabled() && await number.getAttribute('aria-invalid') === 'true', 'Unsupported composed digits are rejected after composition completes');
    await number.fill('000123');
    check(await save.isEnabled() && await number.getAttribute('aria-invalid') === 'false', 'ASCII correction recovers from IME validation');
    await uid.press('Enter');
    const beforeSave = await page.request.post(base + '/registry/tags/lookup', { headers: { 'X-CSRF-TOKEN': await page.locator('meta[name=csrf-token]').getAttribute('content'), Accept: 'application/json' }, data: { uid: '0000epc789' } });
    check(await drawer.isVisible() && (await beforeSave.json()).state === 'new', 'Keyboard-reader Enter captures UID without automatically saving');
    await page.screenshot({ path: path.join(artifacts, 'add-tag-desktop.png') });
    await submit();
    const firstRow = page.locator('.registry-tags-table tbody tr').filter({ hasText: '0000EPC789' });
    check((await firstRow.innerText()).includes('#000123'), 'Saved inventory row displays the exact printed number beside the independent normalized UID');
    const initialDashboard = await (await page.request.get(base + '/admin/live-state')).json();
    check(initialDashboard.latest_events.length === 0, 'Registering a sticker/UID pair creates no vehicle movement');
    const csrf = async () => ({ 'X-CSRF-TOKEN': await page.locator('meta[name=csrf-token]').getAttribute('content'), Accept: 'application/json' });
    const lookup = async value => (await page.request.post(base + '/registry/tags/lookup', { headers: await csrf(), data: { uid: value } })).json();
    check((await lookup('000123')).state === 'new' && (await lookup('0000EPC789')).state === 'available', 'Lookup resolves UID only, never the printed sticker number');

    await open(); await number.fill('123'); await uid.fill('SECOND-UID'); await submit();
    await drawer.waitFor({ state: 'visible' });
    check(await number.inputValue() === '123' && await uid.inputValue() === 'SECOND-UID'
        && await drawer.locator('#sticker_number_error').isVisible(), 'Duplicate numeric key rejects different padding, retains both inputs and reopens the drawer');
    await number.fill('000124');
    check(await drawer.locator('#sticker_number_error').isHidden() && await save.isEnabled(), 'Editing the duplicated number clears its error for retry');
    await submit();
    check((await page.locator('.registry-tags-table').innerText()).includes('#000124'), 'Corrected retry saves the second printed number once');
    await open(); await number.fill('000125'); await uid.fill('0000EPC789'); await submit();
    await drawer.waitFor({ state: 'visible' });
    check(await number.inputValue() === '000125' && await uid.inputValue() === '0000EPC789'
        && await drawer.locator('#sticker_uid_error').isVisible(), 'Duplicate electronic UID fails independently and retains the printed number');
    await uid.fill('THIRD-UID'); await submit();
    check((await page.locator('.registry-tags-table').innerText()).includes('#000125'), 'Changing only the UID recovers without changing printed text');

    await page.getByRole('button', { name: 'Register Tags', exact: true }).first().click();
    const bulk = page.locator('#register-tag-drawer'); await bulk.waitFor({ state: 'visible' });
    await bulk.locator('[data-bulk-scan]').fill('BULK-SCANNED-UID');
    await bulk.locator('[data-bulk-scan]').press('Enter');
    await bulk.locator('[data-bulk-list] li[data-state=ok]').waitFor();
    check((await bulk.locator('[data-bulk-list]').innerText()).includes('#126'), 'Bulk UID scanning still assigns the next numeric inventory number');
    check(await bulk.locator('[data-bulk-next]').innerText() === '127', 'Bulk next-number feedback agrees with the server');
    await Promise.all([page.waitForEvent('load'), bulk.getByRole('button', { name: 'Done', exact: true }).click()]);
    let polls = 0;
    await page.route('**/registry/tags/uhf-reads?*', route => {
        polls++;
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ now: 1000, connected: true,
            reads: polls === 1 ? [] : [{ epc: '0000E280123456789ABC', epoch: 1001 }] }) });
    });
    await open(); await number.fill('000127');
    await drawer.getByRole('button', { name: 'Read with UHF reader', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('#sticker_uid').value === '0000E280123456789ABC');
    check(await number.inputValue() === '000127' && await uid.inputValue() === '0000E280123456789ABC', 'Fixture reader fills UID without overwriting the manually printed number');
    check(await drawer.locator('[data-sticker-uhf]').getAttribute('aria-pressed') === 'false', 'Single-read listener stops after one UID');
    check((await lookup('0000E280123456789ABC')).state === 'new', 'Reading the UID alone does not register or assign a tag');
    await submit();
    check((await page.locator('.registry-tags-table').innerText()).includes('#000127'), 'Reader-filled UID saves together with the exact printed number');
    await page.unroute('**/registry/tags/uhf-reads?*');

    await page.goto(base + '/registry');
    const vehicleRow = () => page.locator(`[data-vehicle-url$="/${fixtureData.vehicle}"]`).filter({ has: page.locator('td') });
    const replace = async (label) => {
        await vehicleRow().locator('.row-menu > summary').click();
        await vehicleRow().locator('[data-vehicle-action=replace]').click();
        const drawer = page.locator('#replace-tag-drawer'); await drawer.waitFor({ state: 'visible' });
        await drawer.locator('[name=rfid_tag_id]').selectOption({ label });
        await Promise.all([page.waitForEvent('load'), drawer.locator('button[type=submit]').click()]);
    };
    check(await page.locator('#add_available option').filter({ hasText: '#000123 · 0000EPC789' }).count() === 1, 'Vehicle selectors show printed number and separate UID');
    await replace('#000123 · 0000EPC789');
    check((await vehicleRow().innerText()).includes('#000123'), 'Existing assignment workflow displays the sticker number on Vehicles');
    await vehicleRow().click(); await page.locator('#vehicle-panel').waitFor({ state: 'visible' });
    await page.locator('#vehicle-panel .vehicle-panel-details').waitFor();
    check((await page.locator('#vehicle-panel').innerText()).includes('#000123'), 'Vehicle Details uses the printed number');
    await page.locator('#vehicle-panel button[data-drawer-close]').click();
    await replace('#000124 · SECOND-UID');
    await vehicleRow().click(); await page.locator('#vehicle-panel').waitFor({ state: 'visible' });
    await page.locator('#vehicle-panel .vehicle-panel-details').waitFor();
    check((await page.locator('#vehicle-panel').innerText()).includes('#000124') && (await page.locator('#vehicle-panel').innerText()).includes('#000123'), 'Replacement keeps current and historical sticker labels distinct');
    await page.locator('#vehicle-panel button[data-drawer-close]').click();
    check((await lookup('0000EPC789')).state === 'lost' && (await lookup('000123')).state === 'new', 'Lost permanence belongs to the electronic UID, not the printed number');
    await page.goto(base + '/registry?tab=tags'); await open(); await number.fill('000128'); await uid.fill('0000EPC789'); await submit();
    await drawer.waitFor({ state: 'visible' });
    check((await drawer.locator('#sticker_uid_error').innerText()).includes('permanently Lost'), 'A lost UID cannot be enrolled again under a new printed number');
    await close();
    for (const width of [390, 320]) {
        await page.setViewportSize({ width, height: 844 }); await open();
        check(await number.isVisible() && await uid.isVisible() && await save.isVisible(), width + 'px drawer keeps both identifiers and save action accessible');
        check(await number.evaluate(input => input.getBoundingClientRect().left >= 0 && input.getBoundingClientRect().right <= innerWidth), width + 'px printed-number input fits the viewport');
        await page.screenshot({ path: path.join(artifacts, 'add-tag-' + width + '.png') }); await close();
    }
    await page.setViewportSize({ width: 1366, height: 768 });
    await page.locator('#tag_status_filter').selectOption('lost');
    await page.waitForFunction(() => document.querySelector('[data-auto-list]')?.dataset.autoState === 'ready');
    const lostRow = page.locator('.registry-tags-table tbody tr').filter({ hasText: '0000EPC789' });
    check((await lostRow.innerText()).includes('#000123') && await lostRow.locator('button').count() === 0, 'Lost row retains sticker/UID history with no reactivation action');
    const printURL = await page.locator('[data-auto-link=list-print]').getAttribute('href');
    const print = await context.newPage(); await print.goto(printURL);
    const tableIDs = await page.locator('.registry-tags-table tbody tr').count();
    check(await print.locator('.records-table tbody tr').count() === tableIDs && (await print.locator('.records-table').innerText()).includes('000123'), 'Filtered Print matches lost inventory and preserves sticker zeros');
    await print.close();
    const csvURL = await page.locator('[data-auto-link=list-csv]').getAttribute('href');
    const csv = await (await page.request.get(csvURL)).text();
    check(csv.includes('000123') && csv.includes('0000EPC789') && csv.trim().split('\n').length === tableIDs + 1, 'Filtered CSV contains the same records with independent literal tag number and UID');
    await page.goto(base + '/registry');
    await page.getByRole('button', { name: 'Add Vehicle', exact: true }).first().click();
    check(await page.locator('#add_registered_by').inputValue() === 'Fixture Staff', 'Tag operations and failed saves retain the staff-name default');
    check(await page.locator('#add_available option').first().innerText() === 'No RFID assigned' && await page.locator('#add_owner').getAttribute('required') !== null, 'Optional RFID and required Owner Name remain intact');
    check(errors.length === 0, 'No browser script errors');
    console.log(`RFID sticker number browser checks: ${checks} passed.`);
} finally {
    if (browser) await browser.close();
    if (server) server.kill('SIGTERM');
    fs.rmSync(directory, { recursive: true, force: true });
}

