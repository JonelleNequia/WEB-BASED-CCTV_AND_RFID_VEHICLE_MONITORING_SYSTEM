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
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-activity-time-'));
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
    const fixture = spawnSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/activity-time-ui.php', directory], {
        cwd: root, env, encoding: 'utf8', timeout: 30000,
    });
    assert.equal(fixture.status, 0, fixture.stderr || fixture.stdout);
    assert.ok(fs.existsSync(directory + '/activity-time.json'), fixture.stderr || fixture.stdout);
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

    const fixtureData = JSON.parse(fs.readFileSync(directory + '/activity-time.json', 'utf8'));
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    const artifacts = path.join(root, 'storage/app/ui-review/activity-time'); fs.mkdirSync(artifacts, { recursive: true });
    const requests = [];
    page.on('request', request => { if (request.headers()['x-auto-filter'] === '1') requests.push(request.url()); });
    const ready = () => page.waitForFunction(() => document.querySelector('[data-auto-list]')?.dataset.autoState === 'ready');
    const rows = () => page.locator('#event-log-modal-data').evaluate(node => JSON.parse(node.textContent));
    const set = async values => {
        await page.evaluate(values => {
            for (const [name, value] of Object.entries(values)) document.querySelector('[name="' + name + '"]').value = value;
            document.querySelector('[name="' + Object.keys(values).at(-1) + '"]').dispatchEvent(new Event('input', { bubbles: true }));
        }, values);
    };
    const total = async () => Number((await page.locator('[data-log-summary=total] .stat-value').innerText()).replaceAll(',', ''));
    await page.goto(base + '/logs'); await ready();
    check(await page.getByLabel('From Time', { exact: true }).count() === 1 && await page.getByLabel('To Time', { exact: true }).count() === 1, 'Time controls have accessible labels beside dates');
    await set({ date_from: '2026-11-03', date_to: '2026-11-05', time_from: '08:00', time_to: '10:00' }); await ready();
    check(await total() === fixtureData.inside.length && (await rows()).length === 15, 'Daily windows include 36 matches across three dates, with 15 rows per page');
    check(new URL(page.url()).searchParams.get('time_to') === '10:00', 'Applied time window enters browser history');
    const matches = [];
    matches.push(...(await rows()).map(row => row.id));
    await page.locator('.pagination-links').getByRole('link', { name: 'Next', exact: true }).click(); await ready();
    check(new URL(page.url()).searchParams.get('page') === '2' && await page.locator('#time_from').inputValue() === '08:00', 'Pagination retains the daily window');
    matches.push(...(await rows()).map(row => row.id));
    await page.locator('.pagination-links').getByRole('link', { name: 'Next', exact: true }).click(); await ready();
    matches.push(...(await rows()).map(row => row.id));
    check(JSON.stringify(matches.sort((a,b) => a-b)) === JSON.stringify(fixtureData.inside.sort((a,b) => a-b)), 'All table pages contain exactly the expected event IDs');
    const csvURL = await page.locator('[data-auto-link=csv]').getAttribute('href');
    const csv = await (await page.request.get(csvURL)).text();
    const lines = csv.trim().split('\n').slice(1);
    // Fixture plates contain no CSV quoting characters, so the existing column layout is easy to compare.
    const csvIds = lines.map(line => fixtureData.ids[line.split(',')[3]]).sort((a,b) => a-b);
    check(JSON.stringify(csvIds) === JSON.stringify(fixtureData.inside), 'CSV exports all matching IDs beyond the current page');
    check(csv.trim().split('\n')[0].split(',').length === 14, 'CSV column structure stays unchanged');
    const reportURL = new URL(await page.locator('[data-auto-link=report-current]').getAttribute('href')); reportURL.searchParams.set('autoprint', '0');
    const print = await context.newPage(); await print.goto(reportURL.href);
    const printPlates = await print.locator('.records-table tbody .col-plate').allTextContents();
    check(JSON.stringify(printPlates.map(plate => fixtureData.ids[plate.trim()]).sort((a,b) => a-b)) === JSON.stringify(fixtureData.inside), 'Print preview contains the same matching IDs beyond the table page');
    check((await print.locator('[data-report-filters]').innerText()).includes('Daily 8:00 AM – 10:00 AM (Asia/Manila'), 'Print summary identifies the Manila daily window');
    await print.screenshot({ path: path.join(artifacts, 'print-window.png'), fullPage: true }); await print.close();
    await set({ time_to: '09:00' }); await ready();
    check(!new URL(page.url()).searchParams.has('page') && await total() === 30, 'Changed criteria return to page one and include the entire end minute');
    await page.goBack(); await ready();
    check(new URL(page.url()).searchParams.get('page') === '3' && await page.locator('#time_to').inputValue() === '10:00', 'Back restores page and valid time controls');
    await page.goForward(); await ready();
    check(await page.locator('#time_to').inputValue() === '09:00' && await total() === 30, 'Forward restores the newer window');
    const successful = await page.locator('[data-auto-update=results]').innerHTML();
    const lastValidURL = page.url(); const baseline = requests.length;
    await set({ time_from: '22:00', time_to: '02:00' });
    await page.waitForFunction(() => document.querySelector('[data-auto-list]').dataset.autoState === 'error');
    check(requests.length === baseline && await page.locator('[data-auto-update=results]').innerHTML() === successful, 'Reversed times preserve the last valid results without requesting');
    check(await page.locator('#time_to_error').isVisible() && (await page.locator('#time_to_error').innerText()).includes('Overnight'), 'Invalid daily range has inline overnight guidance');
    check(page.url() === lastValidURL && await page.locator('[data-auto-link=csv]').getAttribute('href') === null, 'Invalid input retains valid URL while removing stale CSV target');
    check(await page.locator('[data-auto-link=report-current]').getAttribute('href') === null, 'Invalid input removes stale Print target');
    const pages = context.pages().length;
    const allowed = await page.locator('[data-auto-link=csv]').evaluate(link => link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ctrlKey: true })));
    check(allowed === false && context.pages().length === pages && page.url() === lastValidURL, 'Modified export clicks cannot silently open stale criteria');
    await page.screenshot({ path: path.join(artifacts, 'invalid-window.png'), fullPage: true });
    await set({ time_from: '08:00', time_to: '10:00' }); await ready();
    check(await page.locator('#time_to_error').isHidden() && await page.locator('[data-auto-link=csv]').getAttribute('href') !== null, 'Correction clears inline errors and restores current export URLs');
    await set({ date_from: '2026-11-05', date_to: '2026-11-03' });
    check(await page.locator('#date_to_error').isVisible() && (await page.locator('[data-auto-status]').innerText()).includes('To date'), 'Reversed dates show an inline error');
    await page.locator('[data-auto-reset]').click(); await ready();
    check(await page.locator('#time_from').inputValue() === '' && await page.locator('#time_to').inputValue() === ''
        && await page.locator('#date_from').inputValue() === '' && await page.locator('#date_to').inputValue() === '', 'Reset clears both date and time fields after invalid input');
    const beforeMalformed = await page.locator('[data-auto-update=results]').innerHTML();
    await page.locator('#time_from').evaluate(input => { input.type = 'text'; input.value = 'not-a-time'; input.dispatchEvent(new Event('input', { bubbles: true })); });
    await page.waitForFunction(() => document.querySelector('[data-auto-list]').dataset.autoState === 'error');
    check(await page.locator('#time_from_error').isVisible() && await page.locator('[data-auto-update=results]').innerHTML() === beforeMalformed, 'Malformed direct input receives inline server feedback without replacing valid results');
    await page.locator('#time_from').evaluate(input => { input.type = 'time'; input.value = ''; input.dispatchEvent(new Event('change', { bubbles: true })); });
    await ready(); check(await page.locator('#time_from_error').isHidden(), 'Correcting malformed input clears its inline error');
    await set({ time_to: '00:00' }); await ready(); check(await total() === 1, 'To Time only includes Philippine midnight');
    await set({ time_from: '23:59', time_to: '' }); await ready(); check(await total() === 1, 'From Time only includes the last daily minute');
    await page.locator('[data-auto-reset]').click(); await ready();
    await page.evaluate(() => {
        window.savedFetch = window.fetch;
        window.fetch = async function(input, options) {
            if (options?.headers?.['X-Auto-Filter'] === '1' && new URL(input).searchParams.get('time_from') === '08:00') {
                const response = await window.savedFetch(input, { ...options, signal: undefined });
                await new Promise(resolve => setTimeout(resolve, 650)); return response;
            }
            return window.savedFetch(input, options);
        };
    });
    await set({ time_from: '08:00' });
    await page.waitForFunction(() => document.querySelector('[data-auto-list]').dataset.autoState === 'loading');
    check(await page.locator('[data-auto-link=csv]').getAttribute('href') === null, 'Pending filter changes block exports too');
    await set({ time_from: '10:01' }); await ready();
    await page.waitForTimeout(800);
    check(new URL(page.url()).searchParams.get('time_from') === '10:01' && await total() === 4, 'A delayed older response cannot replace a newer time window');
    await page.evaluate(() => { window.fetch = window.savedFetch; });
    await page.locator('[data-auto-reset]').click(); await ready();
    await set({ date_from: '2026-11-03', date_to: '2026-11-05', time_from: '08:00', time_to: '10:00' }); await ready();
    await page.locator('#plate_text').fill('win'); await ready();
    await page.locator('#gate').selectOption('gate-2'); await ready(); check(await total() === 0, 'Time combines with plate and gate filters');
    await page.locator('#gate').selectOption('gate-1'); await ready();
    await page.locator('summary').filter({ hasText: 'More filters' }).click();
    await page.locator('#category').selectOption('faculty_staff'); await ready();
    await page.locator('#event_type').selectOption('ENTRY'); await ready();
    check(await total() === 36, 'Time combines with category and movement without losing the plate criterion');
    await page.setViewportSize({ width: 1366, height: 768 }); await page.screenshot({ path: path.join(artifacts, 'desktop-window.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 }); await page.screenshot({ path: path.join(artifacts, 'mobile-window.png'), fullPage: true });
    check(await page.locator('#time_from').isVisible() && await page.locator('#time_to').isVisible(), 'Time inputs remain available at narrow width');
    const bounds = await page.locator('form[data-auto-filter]').evaluate(form => [...form.querySelectorAll('input:not([type=hidden]), select')].map(input => {
        const rect = input.getBoundingClientRect(); return { name: input.name, left: rect.left, right: rect.right, width: innerWidth };
    }));
    check(bounds.every(rect => rect.left >= 0 && rect.right <= rect.width), 'Date, time and other active filter inputs fit the narrow viewport: ' + JSON.stringify(bounds));
    check(errors.length === 0, 'No browser script errors');
    console.log(`Activity time browser checks: ${checks} passed.`);
} finally {
    if (browser) await browser.close();
    if (server) server.kill('SIGTERM');
    fs.rmSync(directory, { recursive: true, force: true });
}
