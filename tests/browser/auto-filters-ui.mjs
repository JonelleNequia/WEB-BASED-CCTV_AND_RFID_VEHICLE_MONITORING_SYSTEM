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
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-filters-'));
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
    const fixture = spawnSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/auto-filters-ui.php', directory], {
        cwd: root, env, encoding: 'utf8', timeout: 30000,
    });
    assert.equal(fixture.status, 0, fixture.stderr || fixture.stdout);
    assert.ok(fs.existsSync(directory + '/filter-records.json'), fixture.stderr || fixture.stdout);
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

    const failures = [];
    const requests = [];
    let loads = 0;
    let mutations = 0;
    page.on('pageerror', (error) => failures.push(error.message));
    page.on('load', () => loads++);
    page.on('request', (request) => {
        if (request.headers()['x-auto-filter'] === '1') requests.push(request.url());
        if (request.method() !== 'GET') mutations++;
    });
    const ready = () => page.waitForFunction(() => document.querySelector('[data-auto-list]')?.dataset.autoState === 'ready');
    const count = () => page.locator('[data-auto-update="results"] tbody tr').count();
    const value = (name) => new URL(page.url()).searchParams.get(name);
    async function search(selector, text) {
        await page.locator(selector).fill(text);
        await ready();
    }
    async function choose(selector, selected) {
        await page.locator(selector).selectOption(selected);
        await ready();
    }
    async function reset() {
        await page.locator('[data-auto-reset]').first().click();
        await ready();
    }

    await page.goto(base + '/registry?tab=vehicles');
    const loadBaseline = loads;
    const baseline = requests.length;
    await page.evaluate(() => {
        window.filterInput = document.querySelector('#vehicle_q');
        window.filterForm = document.querySelector('[data-auto-filter]');
        window.pageIdentity = 'same-document';
    });
    await page.locator('#vehicle_q').pressSequentially('ZED', { delay: 60 });
    await page.waitForTimeout(100);
    check(requests.length === baseline, 'Rapid text does not request before the idle debounce');
    check(await page.locator('[data-auto-update="results"]').getAttribute('aria-busy') === 'true', 'Pending filtering exposes its loading state');
    await ready();
    check(requests.length === baseline + 1, 'Rapid typing produces one request');
    check(await count() === 24, 'Server filtering returns the matching vehicles');
    check(await page.evaluate(() => document.activeElement === window.filterInput && window.filterInput === document.querySelector('#vehicle_q')
        && window.filterForm === document.querySelector('[data-auto-filter]') && window.filterInput.selectionStart === 3), 'Search focus, caret and control nodes survive results');
    check(loads === loadBaseline, 'Typing does not reload the page');
    await page.evaluate(() => {
        window.filterInput.setSelectionRange(1, 2);
        const select = document.querySelector('#vehicle_status_filter');
        select.value = 'active'; select.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await ready();
    check(await count() === 22 && value('q') === 'ZED' && value('status') === 'active', 'Dropdown combines with current text');
    check(await page.evaluate(() => document.activeElement === window.filterInput && window.filterInput.selectionStart === 1 && window.filterInput.selectionEnd === 2), 'Updates retain a selected text range');
    await choose('#vehicle_state_filter', 'inside');
    check(await count() === 10, 'Multiple existing filters combine');
    await search('#vehicle_q', '');
    check(value('q') === null && value('status') === 'active' && value('state') === 'inside', 'Clearing text keeps other selected criteria');
    await search('#vehicle_q', 'NO-RESULT');
    check(await page.locator('[data-auto-status]').textContent() === 'No matching results.', 'Empty results have clear feedback');
    await reset();
    check(await count() === 28 && await page.locator('#vehicle_status_filter').inputValue() === '', 'Reset clears controls and criteria');
    check(await page.locator('[data-auto-filter] button[type=submit]').count() === 0, 'Automatic form has no visible submit-only button');

    await search('#vehicle_q', 'Z');
    check(await count() === 24, 'A single-character search is applied');
    await search('#vehicle_q', 'ABC');
    await page.goBack();
    await page.waitForFunction(() => document.querySelector('#vehicle_q').value === 'Z' && document.querySelector('[data-auto-list]').dataset.autoState === 'ready');
    check(await count() === 24, 'Back restores query, controls and results');
    await page.goForward();
    await page.waitForFunction(() => document.querySelector('#vehicle_q').value === 'ABC' && document.querySelector('[data-auto-list]').dataset.autoState === 'ready');
    check(await count() === 4 && loads === loadBaseline, 'Forward restores results without a full reload');
    await reset();
    const imeBaseline = requests.length;
    await page.locator('#vehicle_q').focus();
    await page.evaluate(() => {
        const input = document.querySelector('#vehicle_q');
        input.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true }));
        input.value = 'Z';
        input.dispatchEvent(new InputEvent('input', { bubbles: true, isComposing: true, data: 'Z' }));
    });
    await page.waitForTimeout(400);
    check(requests.length === imeBaseline, 'IME intermediate text is not submitted');
    await page.evaluate(() => {
        const input = document.querySelector('#vehicle_q');
        input.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true, data: 'Z' }));
        input.dispatchEvent(new InputEvent('input', { bubbles: true, data: 'Z' }));
    });
    await ready();
    check(requests.length === imeBaseline + 1 && await count() === 24, 'Committed IME text requests once');

    // Deliberately ignore AbortSignal for the old request: the version check
    // must reject its delayed response even if transport cancellation fails.
    await reset();
    await page.evaluate(() => {
        window.originalFetch = window.fetch.bind(window);
        window.fetch = (url, options = {}) => {
            if (options.headers?.['X-Auto-Filter'] && new URL(url, location.href).searchParams.get('q') === 'ZED') {
                return window.originalFetch(url, { ...options, signal: undefined }).then((response) => new Promise((resolve) => setTimeout(() => resolve(response), 1300)));
            }
            return window.originalFetch(url, options);
        };
    });
    const oldRequest = page.waitForRequest((request) => request.headers()['x-auto-filter'] === '1' && new URL(request.url()).searchParams.get('q') === 'ZED');
    await page.locator('#vehicle_q').fill('ZED');
    await oldRequest;
    await search('#vehicle_q', 'ABC');
    await page.waitForTimeout(1400);
    check(value('q') === 'ABC' && await count() === 4, 'A delayed older response cannot overwrite newer results');
    await page.evaluate(() => { window.fetch = window.originalFetch; });

    await reset();
    await page.evaluate(() => {
        window.debounceApplied = [];
        window.trackDebounce = (event) => window.debounceApplied.push(new URL(event.detail.url).searchParams.get('q'));
        document.addEventListener('auto-list:updated', window.trackDebounce);
        window.fetch = (url, options = {}) => {
            if (options.headers?.['X-Auto-Filter'] && new URL(url, location.href).searchParams.get('q') === 'ZED') {
                return window.originalFetch(url, { ...options, signal: undefined }).then((response) => new Promise((resolve) => setTimeout(() => resolve(response), 180)));
            }
            return window.originalFetch(url, options);
        };
    });
    const earlyOld = page.waitForRequest((request) => request.headers()['x-auto-filter'] === '1' && new URL(request.url()).searchParams.get('q') === 'ZED');
    await page.locator('#vehicle_q').fill('ZED');
    await earlyOld;
    await page.locator('#vehicle_q').fill('ABC');
    await page.waitForTimeout(220);
    check(!await page.evaluate(() => window.debounceApplied.includes('ZED')), 'A keystroke invalidates old results during the next debounce window');
    await ready();
    check(await count() === 4 && value('q') === 'ABC', 'The later debounced result is applied');
    await page.evaluate(() => { window.fetch = window.originalFetch; document.removeEventListener('auto-list:updated', window.trackDebounce); });

    let fail = true;
    await page.route('**/registry?**', async (route) => {
        if (route.request().headers()['x-auto-filter'] === '1' && new URL(route.request().url()).searchParams.get('q') === 'FAIL' && fail) {
            return route.fulfill({ status: 500, body: 'Isolated fixture failure' });
        }
        return route.continue();
    });
    const previous = await page.locator('[data-auto-update="results"]').innerHTML();
    await page.locator('#vehicle_q').fill('FAIL');
    await page.waitForFunction(() => document.querySelector('[data-auto-list]').dataset.autoState === 'error');
    check(await page.locator('[data-auto-update="results"]').innerHTML() === previous, 'A request failure keeps successful rows');
    check(await page.locator('[data-auto-retry]').isVisible() && (await page.locator('[data-auto-status]').textContent()).includes('Previous results'), 'Failure explains retained results and offers Retry');
    fail = false;
    await page.locator('[data-auto-retry]').click();
    await ready();
    check(value('q') === 'FAIL' && await page.locator('[data-auto-status]').textContent() === 'No matching results.', 'Retry uses the failed criteria');
    await reset();
    await search('#vehicle_q', 'ABC');
    await page.locator('[data-vehicle-row] td strong').first().click();
    await page.locator('#vehicle-panel').waitFor({ state: 'visible' });
    await page.waitForFunction(() => document.querySelector('#vehicle-panel-title').textContent.includes('ABC-'));
    check((await page.locator('#vehicle-panel-title').textContent()).includes('ABC-'), 'Replaced rows still open their details panel');
    await page.keyboard.press('Escape');
    await page.locator('[data-vehicle-row] details summary').first().click();
    await page.getByRole('menuitem', { name: 'Edit details', exact: true }).first().click();
    await page.locator('#edit-vehicle-drawer').waitFor({ state: 'visible' });
    const editRequests = requests.length;
    const postBaseline = mutations;
    await page.locator('#edit_owner').fill('Unsaved Owner');
    await page.waitForTimeout(450);
    check(requests.length === editRequests && mutations === postBaseline, 'Typing in an edit dialog never auto-submits');
    await page.keyboard.press('Escape');
    check(loads === loadBaseline, 'All Registry updates retained the document');
    console.log('Registry debounce, focus, combined filters, history, IME, races, errors and row actions passed.');

    await page.goto(base + '/registry?tab=tags');
    await choose('#tag_status_filter', 'lost');
    check(await count() === 1 && (await page.locator('[data-auto-update="results"]').textContent()).includes('LOST-001'), 'RFID inventory filters automatically');
    await reset();
    check(await count() === 30, 'Tag Reset stays on the tag tab');

    await page.goto(base + '/visitors?tab=records');
    await search('#visitor_q', 'V');
    check(await count() === 20, 'Visitors supports one-character filtering');
    await page.locator('.pagination-links a').filter({ hasText: 'Next' }).click();
    await ready();
    check(value('page') === '2' && value('q') === 'V' && await count() === 5, 'Pagination retains criteria without a reload');
    await search('#visitor_q', 'VIS-0001');
    check(value('page') === null && await count() === 1, 'Changing criteria resets pagination');
    await choose('#visitor_gate', 'gate-2');
    check(await page.locator('[data-auto-status]').textContent() === 'No matching results.', 'Visitor gate filter combines with text');
    await choose('#visitor_gate', 'gate-1');
    await choose('#visitor_status', 'dismissed');
    check(await page.locator('[data-auto-status]').textContent() === 'No matching results.', 'Visitor status applies automatically');
    await reset();
    check(await page.locator('#visitor_status').inputValue() === 'active' && await count() === 20, 'Reset restores the existing default status');
    await page.goto(base + '/visitors?tab=plates');
    const firstRanked = await page.locator('[data-auto-update="results"] tbody tr').first().innerText();
    await choose('#plate_sort', 'recent');
    check((await page.locator('[data-auto-update="results"] tbody tr').first().innerText()) !== firstRanked, 'Profile sort updates both columns and rows');
    await search('#plate_q', '0');
    check(value('sort') === 'recent' && value('q') === '0', 'Profile search preserves sort and leading zero');

    await page.goto(base + '/logs?tab=scans');
    await search('#history_q', '0');
    check(await count() === 1 && (await page.locator('[data-auto-update="results"]').textContent()).includes('UID0'), 'Zero filters scan history instead of being ignored');
    await choose('#history_scan_location', 'gate-2');
    check(await page.locator('[data-auto-status]').textContent() === 'No matching results.', 'Scan location combines with zero search');
    await reset();
    await search('#history_q', 'R');
    check(await count() === 2, 'One-character scan search works');
    await choose('#verification_status', 'unknown_tag');
    check(await count() === 1, 'Scan result selection applies automatically');
    await page.goto(base + '/logs?tab=alerts');
    await search('#alert_q', '0');
    await choose('#alert_type', 'anomaly');
    check(await page.locator('[data-auto-status]').textContent() === 'No matching results.', 'Alert type and text combine');
    await choose('#alert_type', 'unknown_tag');
    check(await count() === 1, 'Alert result recovers after changing a dropdown');
    console.log('Inventory, Visitors, profiles, scan history and Alerts passed.');

    await page.goto(base + '/logs?tab=events');
    await search('#plate_text', 'LOG');
    check(await count() === 15, 'Activity Logs retains its existing fifteen-row server pagination');
    await page.locator('.more-filters summary').click();
    await choose('#event_type', 'ENTRY');
    await choose('#gate', 'gate-1');
    check(await page.locator('.more-filters').evaluate((node) => node.open), 'Filter disclosure stays open');
    await page.locator('[aria-label="Timeframe"] a').filter({ hasText: 'Today' }).click();
    await ready();
    check(value('period') === 'today' && value('plate_text') === 'LOG', 'Quick timeframes preserve current text');
    const dateBaseline = requests.length;
    await page.evaluate(() => {
        document.querySelector('#date_from').value = '2099-12-31';
        document.querySelector('#date_to').value = '2000-01-01';
        document.querySelector('#date_to').dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.waitForTimeout(350);
    check(requests.length === dateBaseline && (await page.locator('[data-auto-status]').textContent()).includes('To date'), 'An invalid date range keeps results without requesting');
    await page.evaluate(() => {
        document.querySelector('#date_from').value = '2000-01-01';
        document.querySelector('#date_to').value = '2099-12-31';
        document.querySelector('#date_to').dispatchEvent(new Event('change', { bubbles: true }));
    });
    await ready();
    check(value('period') === null && value('date_to') === '2099-12-31', 'A valid explicit range replaces the quick timeframe');
    const csvURL = new URL(await page.locator('[data-auto-link="csv"]').getAttribute('href'));
    check(csvURL.searchParams.get('plate_text') === 'LOG' && csvURL.searchParams.get('gate') === 'gate-1'
        && csvURL.searchParams.get('event_type') === 'ENTRY' && csvURL.searchParams.get('date_to') === '2099-12-31', 'CSV keeps all applied criteria');
    const csv = await page.request.get(csvURL.href);
    check((await csv.text()).trim().split('\n').length === 24, 'CSV exports all twenty-three matching events, beyond the visible page');
    await page.locator('details.menu summary').filter({ hasText: 'Print' }).click();
    await page.locator('[data-event-log-print-snapshots]').check();
    await page.locator('details.menu summary').filter({ hasText: 'Print' }).click();
    await search('#plate_text', 'LOG-0');
    check(await page.locator('[data-event-log-print-snapshots]').isChecked(), 'The print snapshot checkbox survives filtering');
    const printURL = new URL(await page.locator('[data-auto-link="report-current"]').getAttribute('href'));
    check(printURL.searchParams.get('plate_text') === 'LOG-0' && printURL.searchParams.get('gate') === 'gate-1', 'Print links use the latest criteria');
    await page.locator('.pagination-links a').filter({ hasText: 'Next' }).click();
    await ready();
    check(await count() === 8 && value('page') === '2' && await page.locator('[data-live-indicator]').count() === 0, 'Page two is a filtered snapshot, with no live indicator');
    const pageTwoRequests = requests.length;
    await page.waitForTimeout(4200);
    check(requests.length === pageTwoRequests, 'Page-two results are not overwritten by polling');
    await page.locator('.pagination-links a').filter({ hasText: 'Previous' }).click();
    await ready();
    const poll = await page.waitForRequest((request) => request.headers()['x-auto-filter'] === '1'
        && new URL(request.url()).pathname === '/logs', { timeout: 6000 });
    const pollURL = new URL(poll.url());
    check(pollURL.searchParams.get('plate_text') === 'LOG-0' && pollURL.searchParams.get('gate') === 'gate-1'
        && pollURL.searchParams.get('event_type') === 'ENTRY', 'Polling resumes on page one using the latest combined criteria');
    await ready();
    await page.evaluate(() => {
        window.originalFetch = window.fetch.bind(window);
        window.fetch = (url, options = {}) => {
            if (options.headers?.['X-Auto-Filter'] && new URL(url, location.href).searchParams.get('plate_text') === 'LOG-0') {
                return window.originalFetch(url, { ...options, signal: undefined }).then((response) => new Promise((resolve) => setTimeout(() => resolve(response), 1400)));
            }
            return window.originalFetch(url, options);
        };
    });
    await page.waitForRequest((request) => request.headers()['x-auto-filter'] === '1'
        && new URL(request.url()).searchParams.get('plate_text') === 'LOG-0', { timeout: 6000 });
    await search('#plate_text', 'LOG-0001');
    await page.waitForTimeout(1500);
    check(await count() === 1 && value('plate_text') === 'LOG-0001', 'Delayed polling cannot overwrite newer search results');
    check(await page.locator('[data-log-summary="total"] .stat-value').textContent() === '1', 'A stale poll cannot overwrite filtered counts');
    await page.evaluate(() => { window.fetch = window.originalFetch; });
    await page.locator('[data-event-log-view]').first().click();
    await page.locator('#log-details').waitFor({ state: 'visible' });
    check((await page.locator('#log-details-title').textContent()).includes('LOG-0001'), 'Details use the newly filtered record data');
    check(!(await page.locator('#log-details').innerText()).includes('HistoricalColorMarker'), 'Color removal remains intact');
    await page.evaluate(() => { window.originalPrint = window.print; window.print = () => { window.recordPrintCalled = true; }; });
    await page.locator('[data-log-details-print]').click();
    await page.emulateMedia({ media: 'print' });
    check(await page.locator('[data-event-log-print-sheet]').isVisible() && (await page.locator('[data-event-log-print-sheet]').innerText()).includes('LOG-0001'), 'Per-record print remains visible through the list scope');
    check(await page.locator('.event-log-list-view').isHidden() && await page.locator('.auto-filter-feedback').isHidden(), 'Per-record print excludes the list and filter feedback');
    check(await page.locator('[data-event-log-print-sheet]').evaluate((node) => node.getBoundingClientRect().width > 300), 'The print record retains a usable page width');
    await page.emulateMedia({ media: 'screen' });
    await page.evaluate(() => { dispatchEvent(new Event('afterprint')); window.print = window.originalPrint; });
    await page.keyboard.press('Escape');

    let downloads = 0;
    page.on('download', () => downloads++);
    const successful = await page.locator('[data-auto-update="results"]').innerHTML();
    await page.route('**/logs?**', (route) => route.request().headers()['x-auto-filter'] === '1'
        && new URL(route.request().url()).searchParams.get('plate_text') === 'FAIL'
        ? route.fulfill({ status: 500, body: 'Isolated fixture failure' }) : route.continue());
    await page.locator('#plate_text').fill('FAIL');
    await page.waitForFunction(() => document.querySelector('[data-auto-list]').dataset.autoState === 'error');
    check(await page.locator('[data-auto-update="results"]').innerHTML() === successful, 'Failed log filtering retains the last successful records');
    check(await page.locator('[data-auto-link="csv"]').getAttribute('aria-disabled') === 'true', 'Exports wait for successfully applied criteria');
    await page.locator('[data-auto-link="csv"]').click({ force: true });
    await page.waitForTimeout(150);
    check(downloads === 0 && value('plate_text') === 'LOG-0001', 'A rejected filter cannot trigger a broader or stale download');
    await reset();
    check(await page.locator('[data-auto-link="csv"]').getAttribute('aria-disabled') === null, 'Reset restores exports after failure');
    const applied = await page.locator('[data-auto-update="results"]').innerHTML();
    await page.locator('#plate_text').fill('X'.repeat(260));
    await page.waitForFunction(() => document.querySelector('[data-auto-list]').dataset.autoState === 'error');
    check(await page.locator('#plate_text').getAttribute('aria-invalid') === 'true'
        && await page.locator('[data-auto-update="results"]').innerHTML() === applied, 'Server field validation preserves results and identifies the bad input');
    await search('#plate_text', 'LOG');
    check(await page.locator('#plate_text').getAttribute('aria-invalid') === null, 'Correcting invalid input restores automatic results');
    check(failures.length === 0, 'No browser script errors');
    console.log(`Automatic filtering browser checks: ${checks} passed.`);
} finally {
    if (browser) await browser.close();
    if (server) server.kill('SIGTERM');
    fs.rmSync(directory, { recursive: true, force: true });
}
