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
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-snapshots-'));
fs.chmodSync(directory, 0o700);
const env = {
    ...process.env, APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_URL: '',
    DB_DATABASE: directory + '/ui.sqlite', SESSION_DRIVER: 'database', CACHE_STORE: 'array',
    LOG_CHANNEL: 'testing', CAMERA_FILES_PATH: directory + '/camera', DEVICE_FILES_PATH: directory + '/devices',
    ARGON_MEMORY: '1024', ARGON_TIME: '1', DETECTOR_API_KEY: 'snapshot-browser-key', PYTHONDONTWRITEBYTECODE: '1',
    APP_KEY: 'base64:' + randomBytes(32).toString('base64'),
    APP_CONFIG_CACHE: directory + '/config.php', APP_ROUTES_CACHE: directory + '/routes.php',
};
let server;
let browser;
let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; };

try {
    const fixture = spawnSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/snapshots-ui.php', directory], {
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
    const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
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

    const ids = JSON.parse(fs.readFileSync(directory + '/snapshots.json', 'utf8'));
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(base + '/logs');
    await page.locator('[data-event-log-list]').waitFor();
    const logs = await page.locator('#event-log-modal-data').evaluate(node => JSON.parse(node.textContent));
    check(logs.length === 5, 'One rendered occurrence per fixture');
    const thumbnails = page.locator('[data-event-log-list] img[src*="/evidence/occurrences/"]');
    check(await thumbnails.count() === 4, 'Registered RFID, plate, Guest and unreadable Review show their own thumbnails');
    for (let i = 0; i < 4; i++) {
        await thumbnails.nth(i).evaluate(img => img.complete && img.naturalWidth > 0 ? true : new Promise((resolve, reject) => { img.onload = resolve; img.onerror = reject; }));
        check(await thumbnails.nth(i).evaluate(img => img.naturalWidth > 0), 'Authenticated fixture image actually renders');
    }
    const screenshotDir = path.join(root, 'storage/app/ui-review/registration-phase6');
    fs.mkdirSync(screenshotDir, { recursive: true });
    await page.screenshot({ path: path.join(screenshotDir, 'activity-images.png'), fullPage: true });
    await thumbnails.first().click();
    await page.locator('#snapshot-viewer').waitFor({ state: 'visible' });
    await page.waitForFunction(() => document.querySelector('[data-snapshot-full]')?.naturalWidth > 0);
    check(await page.locator('[data-snapshot-full]').evaluate(img => img.complete && img.naturalWidth > 0), 'Click-to-view loads the same protected evidence');
    await page.waitForFunction(() => {
        const viewer = document.querySelector('#snapshot-viewer');
        return viewer.classList.contains('is-open')
            && getComputedStyle(viewer.querySelector('.drawer-panel')).opacity === '1';
    });
    await page.screenshot({ path: path.join(screenshotDir, 'snapshot-viewer.png') });
    await page.locator('#snapshot-viewer button[data-drawer-close]').click();
    const rfid = logs.find(row => row.record_type === 'vehicle_event' && row.rfid_tag_uid === 'SNAPSHOT-BROWSER-UID');
    check(Boolean(rfid && rfid.image_url === ids['rfid-photo'].url), 'RFID event uses its crossing photo without a Guest record');
    await page.goto(base + rfid.detail_url.replace(base, ''));
    check(await page.locator('img[src*="/evidence/occurrences/"]').first().evaluate(img => img.complete && img.naturalWidth > 0), 'Full Details image renders');
    check((await page.locator('body').innerText()).includes('Image stored'), 'Frame selection and storage are distinct evidence times');
    await page.goto(base + '/visitors?tab=review');
    check(await page.locator('img[src*="/evidence/occurrences/"]').count() > 0, 'Guest Review retains occurrence images');
    const anonymous = await browser.newContext();
    await anonymous.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    const denied = await anonymous.request.get(base + ids['rfid-photo'].url, { headers: { Accept: 'application/json' } });
    check(denied.status() === 401, 'Direct image request without a session is denied');
    await anonymous.close();
    const feed = await (await page.request.get(base + '/api/recent-station-logs')).json();
    check(feed.logs.some(row => row.image_url === ids['rfid-photo'].url || row.snapshot_url === ids['rfid-photo'].url), 'Monitoring feed carries the same protected photo');
    await page.goto(base + '/gates');
    check(await page.locator('[data-gate="gate-1"] .occurrence-thumb').count() >= 4, 'Gate Monitor recent results show occurrence thumbnails');
    await page.screenshot({ path: path.join(screenshotDir, 'gate-monitor-images.png'), fullPage: true });
    const update = await page.request.post(base + '/api/v1/integration/crossings', {
        headers: { 'X-Api-Key': 'snapshot-browser-key', Accept: 'application/json' },
        multipart: { external_event_key: 'rfid-photo', camera_role: 'gate-1', direction: 'IN', event_time: ids['rfid-photo'].at,
            detection_metadata: JSON.stringify({ picture: { score: 200, selected_at: new Date(new Date(ids['rfid-photo'].at).getTime() + 1000).toISOString() } }),
            snapshot: { name: 'clearer.jpg', mimeType: 'image/jpeg', buffer: fs.readFileSync(directory + '/clearer.jpg') } },
    });
    check(update.status() === 200, 'Clearer same-occurrence image is accepted');
    const newer = (await update.json()).crossing.snapshot_url;
    await page.locator('[data-gate="gate-1"] .occurrence-thumb').filter({ has: page.locator(`img[src="${newer}"]`) }).first().waitFor();
    check(newer !== ids['rfid-photo'].url, 'Polling follows the new image without creating a new movement');
    await page.goto(base + '/gates/gate-1/kiosk');
    check(await page.locator('.station-log-list .occurrence-thumb').count() >= 4, 'Station recent results show associated images');
    check(await page.locator(`.occurrence-thumb img[src="${newer}"]`).count() > 0, 'Station uses the same updated RFID occurrence image');
    await page.screenshot({ path: path.join(screenshotDir, 'station-images.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(base + '/gates');
    check(await page.locator('[data-gate="gate-1"] .occurrence-thumb').count() >= 4, 'Narrow Gate Monitor retains the occurrence links');
    check(await page.locator('[data-gate="gate-1"] [data-gate-logs] strong').evaluateAll(nodes => nodes.every(node => node.scrollWidth <= node.clientWidth + 2)), 'Narrow occurrence thumbnails do not clip the plate identifiers');
    await page.waitForFunction(() => [...document.querySelectorAll('[data-gate="gate-1"] .occurrence-thumb img')].every(img => img.complete && img.naturalWidth > 0));
    await page.screenshot({ path: path.join(screenshotDir, 'gate-monitor-mobile.png'), fullPage: true });
    const missing = logs.find(row => row.record_type === 'visitor_record' && !row.image_url);
    check(Boolean(missing) && !missing.image_url, 'Missing-image row stays empty, never a different latest frame');
    check(errors.length === 0, 'No browser script errors');
    console.log(`Registration snapshot browser checks: ${checks} passed.`);
} finally {
    if (browser) await browser.close();
    if (server) server.kill('SIGTERM');
    fs.rmSync(directory, { recursive: true, force: true });
}
