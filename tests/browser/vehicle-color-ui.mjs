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
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-color-'));
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
    const fixture = spawnSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/vehicle-color-ui.php', directory], {
        cwd: root, env, encoding: 'utf8', timeout: 30000,
    });
    assert.equal(fixture.status, 0, fixture.stderr || fixture.stdout);
    assert.ok(fs.existsSync(directory + '/color-records.json'), fixture.stderr || fixture.stdout);
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

    const ids = JSON.parse(fs.readFileSync(directory + '/color-records.json', 'utf8'));
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(base + '/vehicle-events/create');
    check(await page.locator('[name=vehicle_color], [name=detected_vehicle_color]').count() === 0, 'Manual form has no color input');
    await page.locator('#plate_text').fill('CLR-UI001');
    await page.locator('#vehicle_type').selectOption('Car');
    await page.locator('#camera_id').selectOption(await page.locator('#camera_id option:not([value=""])').first().getAttribute('value'));
    await page.locator('#roi_name').selectOption(await page.locator('#roi_name option:not([value=""])').first().getAttribute('value'));
    await Promise.all([
        page.waitForURL(/\/vehicle-events\/\d+$/),
        page.getByRole('button', { name: 'Save Manual Log' }).click(),
    ]);
    check(await page.getByText('ENTRY event saved and active session opened.', { exact: true }).isVisible(), 'Manual event saves without color');
    check(await page.locator('.field-error').count() === 0, 'Manual event has no color validation error');

    await page.goto(base + '/vehicle-events/' + ids.pending);
    check(await page.locator('[name=vehicle_color]').count() === 0, 'Pending completion has no color input');
    check(!(await page.locator('body').innerText()).includes('HistoricalColorMarker'), 'Pending details hide retained historical color');
    await page.locator('#plate_text').fill('CLR-UI002');
    await page.getByRole('button', { name: 'Complete Record' }).click();
    await page.getByText('Incomplete ENTRY record completed and active session opened.', { exact: true }).waitFor();
    check(await page.getByRole('button', { name: 'Complete Record' }).count() === 0, 'Completion succeeds without color');
    check(!(await page.locator('body').innerText()).includes('HistoricalColorMarker'), 'Completed details hide retained historical color');

    await page.goto(base + '/vehicle-events/' + ids.historical);
    check(!(await page.locator('body').innerText()).includes('HistoricalColorMarker'), 'Historical event keeps color out of its displayed vehicle');
    const live = page.waitForResponse((response) => new URL(response.url()).pathname === '/logs'
        && response.request().headers()['x-auto-filter'] === '1');
    await page.goto(base + '/logs?tab=events');
    const refreshed = await live;
    check(refreshed.status() === 200, 'Activity Logs live refresh succeeds');
    check(!(await refreshed.text()).includes('vehicle_color'), 'Live feed omits historical color');
    check(await page.getByRole('columnheader', { name: 'Vehicle', exact: true }).count() === 1, 'Logs header describes vehicle type only');
    check(!(await page.locator('body').innerText()).includes('HistoricalColorMarker'), 'Refreshed rows omit color');
    await page.locator('[data-event-log-view]').first().click();
    await page.locator('#log-details').waitFor({ state: 'visible' });
    const labels = await page.locator('[data-log-details-grid] dt').allTextContents();
    check(labels.includes('Vehicle') && !labels.includes('Color'), 'Details modal keeps vehicle type and removes Color');
    check(!(await page.locator('#log-details').innerText()).includes('HistoricalColorMarker'), 'Details modal omits historical color values');

    await page.goto(base + '/visitors');
    check(await page.locator('[name=vehicle_color]').count() === 0, 'Manual visitor drawer has no color input');
    await page.goto(base + '/logs/print?autoprint=0');
    check(!(await page.locator('body').innerText()).includes('HistoricalColorMarker'), 'Printed report omits color values');
    const csv = await page.request.get(base + '/vehicle-events/export/csv?all=1');
    const csvText = await csv.text();
    check(csv.status() === 200 && !csvText.split('\n')[0].includes('Color'), 'CSV header has no Color column');
    check(!csvText.includes('HistoricalColorMarker'), 'CSV rows omit retained historical color');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(base + '/vehicle-events/create');
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Manual form fits a narrow viewport');
    check(errors.length === 0, 'Browser reports no script errors');
    console.log(`Vehicle Color browser checks: ${checks} passed.`);
} finally {
    if (browser) await browser.close();
    if (server) server.kill('SIGTERM');
    fs.rmSync(directory, { recursive: true, force: true });
}
