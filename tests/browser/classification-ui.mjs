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
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-classification-'));
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
    const fixture = spawnSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/classification-ui.php', directory], {
        cwd: root, env, encoding: 'utf8', timeout: 30000,
    });
    assert.equal(fixture.status, 0, fixture.stderr || fixture.stdout);
    assert.ok(fs.existsSync(directory + '/classification-records.json'), fixture.stderr || fixture.stdout);
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

    const ids = JSON.parse(fs.readFileSync(directory + '/classification-records.json', 'utf8'));
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const ready = () => page.waitForFunction(() => document.querySelector('[data-auto-list]')?.dataset.autoState === 'ready');
    const rows = () => page.locator('#event-log-modal-data').evaluate((node) => JSON.parse(node.textContent));
    const byId = (items, type, id) => items.find((row) => (row.record_type === type && row.id === id)
        || (type === 'visitor_record' && row.record_type === 'vehicle_event' && row.visitor_record_id === id));
    async function reset() { await page.locator('[data-auto-reset]').click(); await ready(); }
    async function chip(label) { await page.locator('[aria-label="Log type"]').getByRole('link', { name: label, exact: true }).click(); await ready(); }
    await page.goto(base + '/logs?tab=events');
    let items = await rows();
    check(items.length === 8, 'The mixed classification fixtures appear once each');
    for (const id of [ids.unknown, ids.unassigned]) {
        const scan = byId(items, 'rfid_scan', id);
        check(scan.log_type === 'rfid_scan' && scan.log_type_label === 'RFID Scan' && !scan.is_guest && scan.category_label === 'N/A', 'Unknown/unassigned scans do not inherit registration or a stale category');
    }
    check(byId(items, 'rfid_scan', ids.unknown).status_label === 'Unknown tag', 'Unknown-tag distinction remains visible');
    check(byId(items, 'rfid_scan', ids.unassigned).status_label === 'Unassigned tag', 'Unassigned-tag distinction remains visible');
    const readable = byId(items, 'visitor_record', ids.readable);
    check(readable.plate_number.includes('0403') && readable.log_type === 'unregistered_visitor'
        && readable.category_label === 'Unregistered Visitor', 'A readable plate without a Registry relation remains unregistered');
    check(byId(items, 'visitor_record', ids.unreadable).log_type === 'review', 'Unreadable plates remain in Review');
    check(byId(items, 'visitor_record', ids.plate_only).log_type === 'registered'
        && byId(items, 'visitor_record', ids.plate_only).status_label === 'Inside', 'An authoritative plate-only relation updates registered occupancy');
    check(byId(items, 'visitor_record', ids.historical).log_type === 'unregistered_visitor', 'Registration added later does not rewrite earlier activity');
    check(await page.locator('[data-log-summary="guests"] .stat-value').textContent() === '2', 'Unregistered counts exclude unknown/unassigned scans and unresolved plates');
    check(await page.locator('[data-log-summary="rfid"] .stat-value').textContent() === '4', 'RFID-only count retains all four scans');

    await chip('Registered');
    items = await rows();
    check(items.length === 3 && items.every((row) => row.log_type === 'registered'), 'Registered chip excludes unknown, unassigned and unregistered records');
    check(ids.registered_scans.every((id) => byId(items, 'rfid_scan', id)), 'Both Faculty and Registered Visitor scans remain registered');
    const registeredCSV = await page.request.get(await page.locator('[data-auto-link="csv"]').getAttribute('href'));
    const registeredText = await registeredCSV.text();
    check(registeredText.trim().split('\n').length === 4 && !registeredText.includes('P4-UNKNOWN') && !registeredText.includes('P4-UNASSIGNED'), 'Registered export has exactly the same three records');
    const report = await context.newPage();
    const printURL = new URL(await page.locator('[data-auto-link="report-current"]').getAttribute('href'));
    printURL.searchParams.set('autoprint', '0');
    await report.goto(printURL.href);
    check(!(await report.locator('body').innerText()).includes('P4-UNKNOWN'), 'Current-list print excludes unknown scans under Registered');
    await report.close();
    await page.bringToFront();

    await chip('Unregistered Visitors');
    items = await rows();
    check(items.length === 2 && items.every((row) => row.log_type === 'unregistered_visitor'), 'Unregistered chip includes readable and pre-registration history; unreadable stays Review');
    await reset();
    await page.locator('.more-filters summary').click();
    await page.locator('#category').selectOption('registered_visitor');
    await ready();
    items = await rows();
    check(items.length === 1 && items[0].record_type === 'rfid_scan' && items[0].id === ids.registered_scans[1], 'Category filter uses effective registration, not stale text on an unknown scan');
    await reset();
    await page.locator('#plate_text').fill('UNR');
    await ready();
    items = await rows();
    check(items.length === 1 && items[0].id === ids.readable && items[0].log_type === 'unregistered_visitor', 'Automatic readable-plate search preserves classification');
    await page.locator('[data-event-log-view]').click();
    await page.locator('#log-details').waitFor({ state: 'visible' });
    const details = await page.locator('[data-log-details-grid]').innerText();
    check(details.includes('Unregistered Visitor') && !details.includes('Registered vehicle'), 'Details modal shows the same unregistered classification');
    await page.waitForFunction(() => document.querySelector('#log-details').classList.contains('is-open'));
    await page.keyboard.press('Escape');
    await page.locator('#log-details').waitFor({ state: 'hidden' });
    await page.bringToFront();
    const live = page.waitForRequest((request) => request.headers()['x-auto-filter'] === '1'
        && new URL(request.url()).searchParams.get('plate_text') === 'UNR');
    await page.locator('#plate_text').focus();
    await live;
    await ready();
    items = await rows();
    check(items.length === 1 && items[0].log_type === 'unregistered_visitor', 'Live refresh preserves current criteria and classification');
    await chip('Registered');
    check((await rows()).length === 0 && await page.locator('[data-auto-status]').textContent() === 'No matching results.', 'Combined Registered and readable-unregistered search yields no false matches');
    await reset();
    await chip('Alerts');
    items = await rows();
    check(items.length === 2 && items.every((row) => row.record_type === 'rfid_scan' && row.log_type === 'rfid_scan'), 'Unknown/unassigned scans remain discoverable in Alerts');
    await page.locator('[data-event-log-view]').first().click();
    check((await page.locator('[data-log-details-grid]').innerText()).includes('RFID Scan'), 'RFID details no longer use Registered as a default');
    await page.waitForFunction(() => document.querySelector('#log-details').classList.contains('is-open'));
    await page.keyboard.press('Escape');
    await page.locator('#log-details').waitFor({ state: 'hidden' });
    await reset();
    const allCSV = await page.request.get(await page.locator('[data-auto-link="csv"]').getAttribute('href'));
    const allText = await allCSV.text();
    check(allText.includes('RFID,"RFID Scan"') && allText.includes(readable.plate_number), 'Unfiltered export retains both scan and readable visitor distinctions');
    check(!allText.includes('HistoricalColorMarker') && !allText.split('\n')[0].includes('Color'), 'Phase 2 color retirement remains intact');
    const station = await page.request.get(base + '/api/recent-station-logs');
    const stationRows = (await station.json()).logs;
    const unknownStationRows = stationRows.filter((row) => row.record_type === 'rfid_scan' && ['scan-' + ids.unknown, 'scan-' + ids.unassigned].includes(row.id));
    check(unknownStationRows.length === 2 && unknownStationRows.every((row) => row.event_type === 'UNKNOWN TAG'), 'Station feed retains unknown/unassigned distinctions');
    check(errors.length === 0, 'No browser script errors');
    console.log(`Classification browser checks: ${checks} passed.`);
} finally {
    if (browser) await browser.close();
    if (server) server.kill('SIGTERM');
    fs.rmSync(directory, { recursive: true, force: true });
}
