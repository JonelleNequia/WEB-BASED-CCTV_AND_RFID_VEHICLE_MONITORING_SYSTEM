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
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-dashboard-occurrences-'));
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
    const fixture = spawnSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/dashboard-occurrences-ui.php', directory], {
        cwd: root, env, encoding: 'utf8', timeout: 30000,
    });
    assert.equal(fixture.status, 0, fixture.stderr || fixture.stdout);
    assert.ok(fs.existsSync(directory + '/dashboard-occurrences.json'), fixture.stderr || fixture.stdout);
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

    const fixtureData = JSON.parse(fs.readFileSync(directory + '/dashboard-occurrences.json', 'utf8'));
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    const artifacts = path.join(root, 'storage/app/ui-review/dashboard-occurrences'); fs.mkdirSync(artifacts, { recursive: true });
    const state = async () => (await page.request.get(base + '/admin/live-state')).json();
    const liveRows = () => page.locator('[data-dashboard-stream=events] .stream-item');
    const settled = () => page.waitForFunction(() => [...document.querySelectorAll('[data-dashboard-stream=events] .stream-item')].every(node => node.dataset.dashboardItemKey));
    await page.goto(base + '/admin');
    await settled();
    let data = await state();
    const keys = data.latest_events.map(row => row.key);
    await page.waitForFunction(keys => JSON.stringify([...document.querySelectorAll('[data-dashboard-stream=events] .stream-item')].map(row => row.dataset.dashboardItemKey)) === JSON.stringify(keys), keys);
    check(await liveRows().count() === 8 && new Set(keys).size === 8, 'Latest eight rows have distinct occurrence IDs');
    for (const id of fixtureData.pair) {
        check(await page.locator(`[data-dashboard-item-key="vehicle_event:${id}"]`).count() === 1, 'Same-plate same-minute event ' + id + ' survives polling separately');
    }
    check(await page.locator('[data-dashboard-stream=events] strong').filter({ hasText: /^IN • 395ISX$/ }).count() === 2, 'Two genuinely separate movements retain identical visible plate and time');
    check(data.latest_events.filter(row => row.key === 'vehicle_event:' + fixtureData.rfid).length === 1
        && !data.latest_events.some(row => row.key === 'rfid_scan:' + fixtureData.rfid), 'RFID, visitor, legacy and canonical links produce one movement row');
    check(data.latest_events.some(row => row.key === 'visitor_record:' + fixtureData.review && row.direction_label === 'Direction unknown'), 'Visitor-only Review occurrence is included with Direction unknown');
    check(data.latest_events.some(row => row.key === 'guest_observation:' + fixtureData.legacy && row.direction_label === 'Direction unknown'), 'Legacy evidence with no direction is not shown as IN');
    check(await page.locator('[data-dashboard-stream=events] .badge').allTextContents().then(labels => labels.every(label => !['Entry', 'Exit', 'IN', 'OUT', 'Direction unknown'].includes(label))), 'Each row has only one direction label; status badge does not repeat it');
    check(await page.getByText("Today's traffic", { exact: true }).count() === 0 && await page.locator('[data-dashboard-chart]').count() === 0, 'Today’s traffic card is removed');
    check(await page.locator('[data-dashboard-needs-attention] [data-dashboard-review]').innerText() === '2 to review', 'Current Review total is clearly shown inside Needs attention');
    check(await page.locator('[data-dashboard-review]').evaluate(node => !node.parentElement.closest('a')), 'Review link is not nested in another link');
    const reviewPage = await context.newPage();
    await reviewPage.goto(base + '/visitors?tab=review');
    check(await reviewPage.getByText('Review (2)', { exact: true }).count() === 1, 'Dashboard total agrees with the Review tab');
    await reviewPage.close();
    await page.evaluate(key => { window.retainedEventNode = document.querySelector(`[data-dashboard-item-key="${key}"]`); }, 'vehicle_event:' + fixtureData.pair[0]);
    const polled = page.waitForResponse(response => response.url().endsWith('/admin/live-state') && response.request().resourceType() === 'fetch');
    await polled;
    check(await page.evaluate(key => window.retainedEventNode === document.querySelector(`[data-dashboard-item-key="${key}"]`), 'vehicle_event:' + fixtureData.pair[0]), 'Repeated polls retain the same keyed DOM node');

    const periods = ['today', 'week', 'month', 'year'];
    const gateNames = (counts) => Object.values(counts.gates).map(row => row.label);
    const directionOf = row => row.event_type === 'GUEST' ? row.direction : ({ ENTRY: 'IN', EXIT: 'OUT' }[row.event_type] || 'UNKNOWN');
    for (const period of periods) {
        await page.locator(`[data-segments=dashboard-counts] [data-segment="${period}"]`).click();
        check(await page.locator(`[data-segment-panel="${period}"]`).first().isVisible(), period + ' period is selected');
        const counts = data.movement_counts[period];
        check(JSON.stringify(gateNames(counts)) === JSON.stringify(['Main Gate', 'Service Gate']), period + ' gates match the operational configuration');
        check(counts.gates['gate-3'].in === 0 && counts.gates['gate-3'].out === 0, period + ' includes operational gates with zero movements');
        for (const [event_type, key] of [['ENTRY', 'in'], ['EXIT', 'out'], ['UNKNOWN', 'unknown']]) {
            // Legacy Activity Logs rows keep type GUEST and their actual direction separately.
            const query = new URLSearchParams({ period });
            const response = await page.request.get(base + '/logs?' + query, { headers: { Accept: 'application/json' } });
            const direction = { ENTRY: 'IN', EXIT: 'OUT', UNKNOWN: 'UNKNOWN' }[event_type];
            check((await response.json()).logs.filter(row => directionOf(row) === direction).length === counts[key], period + ' overall ' + key + ' matches Activity Logs including historical gates');
            if (key !== 'unknown') {
                for (const [gate, row] of Object.entries(counts.gates)) {
                    const perGate = await (await page.request.get(base + '/logs?' + new URLSearchParams({ period, gate }), { headers: { Accept: 'application/json' } })).json();
                    check(perGate.logs.filter(log => directionOf(log) === direction).length === row[key], period + ' ' + gate + ' ' + key + ' matches Activity Logs');
                }
            }
        }
    }
    check(await page.getByText(/including inactive and archived gates/).count() === 1, 'The difference between overall and operational gate scope is explained');
    const historical = await (await page.request.get(base + '/logs?gate=gate-4', { headers: { Accept: 'application/json' } })).json();
    check(historical.total === 1 && historical.logs[0].plate_number === 'ARCH123', 'Archived gate history remains in Activity Logs');
    const logRows = (await (await page.request.get(base + '/logs', { headers: { Accept: 'application/json' } })).json()).logs;
    check(keys.every(key => logRows.some(log => log.record_type + ':' + log.id === key)), 'Every recent Dashboard occurrence has its corresponding Activity Logs row');
    await page.locator('[data-segments=dashboard-counts] [data-segment=today]').click();
    await page.screenshot({ path: path.join(artifacts, 'desktop.png'), fullPage: true });

    // Update only the guarded private fixture DB; polling must rebuild membership and names.
    const changed = spawnSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/dashboard-occurrences-ui.php', directory, 'gates'], { cwd: root, env, encoding: 'utf8', timeout: 30000 });
    assert.equal(changed.status, 0, changed.stderr || changed.stdout);
    await page.locator('[data-segments=dashboard-counts] [data-segment=month]').click();
    await page.waitForFunction(() => document.querySelector('[data-dashboard-gates="month"]')?.textContent.includes('New Zero Gate'));
    check(await page.locator('[data-dashboard-gates=month] th').allTextContents().then(labels => JSON.stringify(labels) === JSON.stringify(['Renamed Main Gate', 'New Zero Gate'])), 'Polling renames a gate, removes an archived gate and adds a zero-count gate');
    check(await page.locator('[data-segments=dashboard-counts] [data-segment=month]').getAttribute('aria-selected') === 'true', 'Polling preserves the selected period');
    const monitor = await context.newPage(); await monitor.goto(base + '/gates');
    check(await monitor.locator('.gate-card[data-gate]').evaluateAll(nodes => JSON.stringify(nodes.map(node => node.dataset.gate))) === JSON.stringify(['gate-1', 'gate-5']), 'Updated gate rows match Gate Monitor exactly');
    await monitor.close();
    const csrf = await page.locator('meta[name=csrf-token]').getAttribute('content');
    const headers = { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' };
    const noPlate = await page.request.patch(base + fixtureData.review_url, { headers, data: { action: 'no_plate' } });
    check(noPlate.ok(), 'Existing No plate visible action succeeds without creating a movement');
    const direction = await page.request.patch(base + fixtureData.review_url, { headers, data: { action: 'set_direction', direction: 'IN' } });
    check(direction.ok(), 'Review direction action resolves the visitor card');
    const eventDirection = await page.request.patch(base + fixtureData.direction_url, { headers, data: { direction: 'OUT' }, maxRedirects: 0 });
    check(eventDirection.status() === 302, 'Existing canonical direction action succeeds');
    await page.waitForFunction(() => document.querySelector('[data-dashboard-metric=review_count]')?.textContent === '0');
    check(await page.locator('[data-dashboard-review]').innerText() === '0 to review', 'Review count refreshes down to zero without reloading');
    await page.locator('[data-dashboard-review]').focus(); await page.keyboard.press('Enter'); await page.waitForURL(/tab=review/);
    check(await page.getByText('Nothing to review', { exact: true }).count() === 1 && await page.locator('[data-review-remaining]').innerText() === '0', 'Keyboard Review link opens the same zero-count queue');
    await page.goto(base + '/admin'); await settled();
    for (const width of [390, 320]) {
        await page.setViewportSize({ width, height: 844 });
        await page.locator('[data-segments=dashboard-counts] [data-segment=year]').click();
        check(await page.locator('[data-dashboard-gates=year] tr').count() === 2, width + 'px layout retains both operational gates');
        check(await page.locator('[data-dashboard-needs-attention] [data-dashboard-review]').isVisible(), width + 'px layout keeps the Review count readable');
        await page.screenshot({ path: path.join(artifacts, 'narrow-' + width + '.png'), fullPage: true });
        check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), width + 'px layout does not overflow the page: ' + JSON.stringify(await page.evaluate(() => [...document.querySelectorAll('.dashboard-grid *, [data-dashboard-metrics] *')].filter(node => node.getBoundingClientRect().right > innerWidth + 1).slice(0, 12).map(node => ({ tag: node.tagName, class: node.className, text: node.textContent.slice(0, 60) })))));
    }
    const none = spawnSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/dashboard-occurrences-ui.php', directory, 'no-gates'], { cwd: root, env, encoding: 'utf8', timeout: 30000 });
    assert.equal(none.status, 0, none.stderr || none.stdout);
    await page.waitForFunction(() => document.querySelector('[data-dashboard-gates=year]')?.textContent.includes('No operational gates configured.'));
    check(await page.locator('[data-dashboard-gates=year] td[colspan="3"]').count() === 1, 'No operational gates has a clear empty state without dropping historical totals');
    const lastRows = await page.locator('[data-dashboard-stream=events]').innerHTML();
    await page.route('**/admin/live-state', route => route.fulfill({ status: 503, contentType: 'application/json', body: '{}' }));
    await page.waitForResponse(response => response.url().endsWith('/admin/live-state') && response.status() === 503);
    check(await page.locator('[data-dashboard-stream=events]').innerHTML() === lastRows, 'A failed poll preserves the last valid activity rows');
    check(errors.length === 0, 'No Dashboard browser errors');
    console.log(`Dashboard occurrence browser checks: ${checks} passed.`);
} finally {
    if (browser) await browser.close();
    if (server) server.kill('SIGTERM');
    fs.rmSync(directory, { recursive: true, force: true });
}

