import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import { spawn, spawnSync } from 'node:child_process';
import { chromium } from 'playwright';
import { randomBytes } from 'node:crypto';

// This runner creates its own database/server. It never accepts a live app URL.
const root = path.resolve(import.meta.dirname, '../..');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-'));
fs.chmodSync(directory, 0o700);
const screenshots = path.join(root, 'storage/app/ui-review/settings');
fs.mkdirSync(screenshots, { recursive: true });
const php = process.env.PHP_BINARY || 'php';
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
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

try {
    const fixture = spawnSync(php, ['tests/fixtures/ui-workspace.php', directory], { cwd: root, env, encoding: 'utf8' });
    assert.equal(fixture.status, 0, fixture.stderr || fixture.stdout);
    const port = await new Promise((resolve, reject) => {
        const socket = net.createServer();
        socket.on('error', reject);
        socket.listen(0, '127.0.0.1', () => { const value = socket.address().port; socket.close(() => resolve(value)); });
    });
    const base = 'http://127.0.0.1:' + port;
    server = spawn(php, ['-S', '127.0.0.1:' + port, path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], {
        cwd: path.join(root, 'public'), env: { ...env, APP_URL: base }, stdio: ['ignore', 'ignore', 'ignore'],
    });
    server.on('error', (error) => { console.error(error.message); });
    for (let attempt = 0; attempt < 60; attempt++) {
        try { if ((await fetch(base + '/login')).status === 200) break; } catch {}
        if (attempt === 59) throw new Error('Isolated browser server did not start.');
        await delay(200);
    }
    const macChrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
    const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || (fs.existsSync(macChrome) ? macChrome : undefined);
    browser = await chromium.launch({ executablePath, headless: true });
    const context = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    await context.route('**/*', (route) => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const credentials = JSON.parse(fs.readFileSync(directory + '/login.json', 'utf8'));
    async function login(email = credentials.email) {
        await page.goto(base + '/login');
        await page.locator('[name=email]').fill(email);
        await page.locator('[name=password]').fill(credentials.password);
        await page.locator('button[type=submit]').click();
        await page.waitForURL(email === credentials.email ? /\/admin$/ : /\/account\/password$/);
    }
    async function open(url) {
        const response = await page.goto(base + url);
        check(response.status() === 200, url + ' renders successfully');
        await page.waitForTimeout(100);
        check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), url + ' does not overflow the viewport');
    }
    await login();
    for (const [width, height] of [[1366, 768], [1920, 1080], [768, 900], [390, 844]]) {
        await page.setViewportSize({ width, height });
        for (const tab of ['gates', 'calibration&gate=gate-1', 'status', 'devices', 'timing', 'manual', 'reader-network', 'test-scan', 'plate-camera']) {
            await open('/settings?tab=' + tab);
            if (tab === 'gates') {
                check(await page.locator('.setup-device-text').first().evaluate((node) => node.getBoundingClientRect().width >= 140), 'Gate device text retains readable width');
                check(await page.getByText('Archived fixture gate', { exact: true }).count() === 0, 'Archived gates remain excluded');
            }
            if (tab === 'status') {
                check(await page.locator('.health-summary-item').count() === 3, 'System summary includes detector, cameras and readers');
            }
            if (['gates', 'calibration&gate=gate-1', 'status'].includes(tab)) {
                await page.screenshot({ path: path.join(screenshots, `after-${tab.split('&')[0]}-${width}x${height}.png`), fullPage: true });
            }
        }
    }
    console.log('Settings rendered at 1366×768, 1920×1080, 768px and 390px.');
    await page.setViewportSize({ width: 1366, height: 500 });
    await open('/settings?tab=gates');
    const logout = page.locator('.sidebar-logout-button');
    await logout.scrollIntoViewIfNeeded();
    const logoutBox = await logout.boundingBox();
    check(logoutBox.y >= 0 && logoutBox.y + logoutBox.height <= 500, 'Logout remains reachable at short desktop heights');
    await page.locator('[data-sidebar-toggle]').click();
    await page.locator('.sidebar-compact-account summary').click();
    check(await page.locator('.compact-account-panel').isVisible(), 'Collapsed sidebar exposes account information and links');
    await page.locator('[data-sidebar-toggle]').click();

    // Device states are fixtures supplied only to this isolated browser.
    await page.setViewportSize({ width: 1366, height: 600 });
    const config = await page.locator('#add-device-data').evaluate((node) => JSON.parse(node.textContent));
    let devices = Array.from({ length: 35 }, (_, i) => ({
        id: i + 1, name: 'Same camera name', kind: 'camera', status: i === 1 ? 'offline' : 'online',
        ip: '192.0.2.' + (i + 1), mac: '02:00:00:00:00:' + String(i + 1).padStart(2, '0'),
        assigned: [], assign_url: base + '/ui-fixture-assign',
    }));
    devices.push({ id: 99, name: 'Unconfirmed fixture', kind: 'unknown', maybe_camera: true, status: 'unknown', ip: '192.0.2.99', assigned: [] });
    let scanRunning = true;
    let listFailure = false;
    let scanFailure = false;
    let assignments = 0;
    await page.route(config.indexUrl, (route) => route.fulfill(listFailure
        ? { status: 500, json: { message: 'Fixture refresh failure' } }
        : { json: { devices, scan: { running: scanRunning }, service: { state: 'running' } } }));
    await page.route(config.scanUrl, (route) => route.fulfill({ status: scanFailure ? 500 : 200, json: { ok: !scanFailure } }));
    await page.route(base + '/ui-fixture-assign', (route) => {
        assignments++;
        return route.fulfill({ json: assignments === 1 ? { needs_credentials: true } : { ok: false, message: 'Fixture validation feedback' } });
    });
    const trigger = page.locator('[data-gate-card="gate-2"] [data-add-device="camera"]');
    await trigger.click();
    const modal = page.locator('#add-device-modal');
    await modal.locator('input[value="1"]').waitFor();
    const add = modal.getByRole('button', { name: 'Add', exact: true });
    check(await add.isDisabled(), 'Add is disabled before selection');
    const footer = await modal.locator('.drawer-foot').boundingBox();
    check(footer.y + footer.height <= 600, 'Device footer stays inside short viewport');
    check(await modal.locator('.drawer-body').evaluate((node) => node.scrollHeight > node.clientHeight), 'Long device list scrolls within dialog body');
    check(await modal.getByText('192.0.2.2', { exact: true }).count() === 1, 'Addresses distinguish identical names');
    await modal.locator('input[value="2"]').check();
    check(await add.isEnabled(), 'Existing offline selection rule is preserved');
    devices = devices.map((device) => device.id === 2 ? { ...device, status: 'online' } : device);
    scanRunning = false;
    await modal.getByText('Scan complete. Choose a device below, or scan again.').waitFor();
    check(await modal.locator('input[value="2"]').isChecked(), 'Background refresh preserves selected device');
    check(await modal.locator('input[value="2"]').evaluate((node) => node === document.activeElement), 'Background refresh preserves keyboard focus');
    listFailure = true;
    await modal.getByText('Device list could not be refreshed. Your selection is kept; retrying…').waitFor();
    check(await modal.locator('input[value="2"]').isChecked(), 'Refresh error retains selection');
    listFailure = false;
    await add.click();
    await modal.locator('[name=add_username]').waitFor();
    const submit = modal.getByRole('button', { name: 'Save and test', exact: true });
    check(await submit.evaluate((node) => node.form?.id === 'add-device-credentials'), 'Pinned credential footer remains associated with its form');
    await submit.click();
    check(assignments === 1, 'Required credential validation prevents submission');
    await modal.locator('[name=add_username]').fill('fixture-user');
    await submit.click();
    await modal.getByText('Fixture validation feedback').waitFor();
    await page.keyboard.press('Escape');
    check(await trigger.evaluate((node) => node === document.activeElement), 'Closing device dialog returns focus to original trigger');
    scanFailure = true;
    await trigger.click();
    await modal.getByText('The scan could not be started. You can select a listed device or try Scan again.').waitFor();
    check(await modal.getByText('Unconfirmed connection · identity unconfirmed').count() === 1, 'Unconfirmed identity and connection are explicit');
    await modal.locator('.drawer-panel').focus();
    await page.keyboard.press('Shift+Tab');
    check(await modal.evaluate((node) => node.contains(document.activeElement)), 'Focus is trapped even when dialog panel itself had focus');
    await page.screenshot({ path: path.join(screenshots, 'device-dialog-1366x600.png'), fullPage: false });
    await modal.locator('input[value="3"]').check();
    devices = devices.filter((device) => device.id !== 3);
    await page.waitForFunction(() => !document.querySelector('#add-device-modal input[value="3"]'));
    check(await modal.getByRole('button', { name: 'Add', exact: true }).isDisabled(), 'A removed selection cannot be submitted');
    devices = [];
    await modal.getByText('No camera found yet', { exact: true }).waitFor();
    await page.keyboard.press('Escape');
    scanFailure = false;
    devices = [{ id: 71, name: 'Offline reader fixture', kind: 'rfid_reader', status: 'offline', ip: '192.0.2.71', assigned: [] }];
    await page.locator('[data-gate-card="gate-2"] [data-add-device="reader"]').click();
    await modal.locator('input[value="71"]').check();
    check(await modal.getByRole('button', { name: 'Add', exact: true }).isEnabled(), 'Offline RFID readers remain selectable');
    await page.keyboard.press('Escape');

    // Controls stay associated with the existing shared form and validation.
    const management = page.locator('[data-gate-card="gate-2"] .gate-management');
    await management.locator('summary').click();
    await management.locator('[name="gates[gate-2][name]"]').fill('');
    await management.locator('summary').click();
    await page.evaluate(() => document.getElementById('gate-settings-form').requestSubmit());
    check(await management.evaluate((node) => node.open), 'Required-field validation reveals closed management controls');
    await management.locator('[name="gates[gate-2][name]"]').fill('Renamed UI fixture gate');
    await management.locator('input[type=checkbox][name="gates[gate-2][is_active]"]').uncheck();
    await management.getByRole('button', { name: 'Save gate settings' }).click();
    await page.waitForLoadState();
    await page.locator('[data-gate-card="gate-2"] .gate-setup-head h2').filter({ hasText: 'Renamed UI fixture gate' }).waitFor();
    check(await page.locator('[data-gate-card="gate-2"] .gate-setup-head').textContent().then((text) => text.includes('Renamed UI fixture gate') && text.includes('Inactive')), 'Gate rename and activation controls submit successfully');
    check(await page.locator('[data-gate-card="gate-1"] .gate-setup-head h2').textContent() === 'Gate 1', 'Per-card submission preserves other gate names');
    console.log('Device dialog scrolling, states, refresh, validation and keyboard checks passed.');

    // Exercise the real editor on a 4:3 offline fixture, including letterboxing.
    await page.setViewportSize({ width: 1366, height: 768 });
    await open('/settings?tab=calibration&gate=gate-1');
    const calibration = await page.locator('#camera-calibration-data').evaluate((node) => JSON.parse(node.textContent));
    await page.route(calibration.routes.heartbeat, (route) => route.fulfill({ json: { gates: {}, crossings: {} } }));
    const frame = base + '/ui-fixture-frame.svg';
    await page.route(frame, (route) => route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600"><rect width="800" height="600" fill="#e5e7eb"/><path d="M300 0H500L740 600H60Z" fill="#64748b"/><path d="M400 0V600" stroke="white" stroke-dasharray="36 28" stroke-width="8"/><text x="20" y="35" font-size="22">Offline UI fixture · 4:3 image</text></svg>' }));
    await page.evaluate((url) => {
        const card = window.calibrationCards['gate-1'];
        card.applySnapshot(url, null);
        card.applyConnection({ state: 'offline', label: 'Offline', reason: 'UI fixture camera is offline.', tone: 'critical' });
    }, frame);
    await page.waitForFunction(() => document.querySelector('[data-last-picture]').naturalWidth === 800);
    const initial = await page.evaluate(() => JSON.stringify(window.calibrationCards['gate-1'].editor.mask));
    const originalPlayer = await page.locator('[data-video]').elementHandle();
    for (const viewport of [{ width: 1366, height: 768 }, { width: 1920, height: 1080 }, { width: 768, height: 900 }]) {
        await page.setViewportSize(viewport);
        await page.locator('[data-calibration-canvas]').scrollIntoViewIfNeeded();
        await page.waitForTimeout(150);
        check(await page.evaluate(() => JSON.stringify(window.calibrationCards['gate-1'].editor.mask)) === initial, 'Responsive resize preserves normalized ROI');
    }
    // Layout/DPR equivalent of 1366×768 at 125% browser zoom.
    const cdp = await context.newCDPSession(page);
    await cdp.send('Emulation.setDeviceMetricsOverride', { width: 1093, height: 614, deviceScaleFactor: 1.25, mobile: false });
    await page.locator('[data-calibration-canvas]').scrollIntoViewIfNeeded();
    await page.waitForTimeout(150);
    const vertex = await page.evaluate(() => {
        const card = window.calibrationCards['gate-1'];
        const view = card.view(); const bounds = card.canvas.getBoundingClientRect();
        return { x: bounds.left + view.x + .2 * view.width, y: bounds.top + view.y + .2 * view.height, dx: .05 * view.width, dy: .05 * view.height };
    });
    await page.mouse.move(vertex.x, vertex.y); await page.mouse.down();
    await page.mouse.move(vertex.x + vertex.dx, vertex.y + vertex.dy, { steps: 4 }); await page.mouse.up();
    const moved = await page.evaluate(() => window.calibrationCards['gate-1'].editor.mask[0]);
    check(Math.abs(moved.x - .25) < .004 && Math.abs(moved.y - .25) < .004, 'Pointer editing remains aligned with letterboxed image at 125% layout/DPR');
    check(await page.locator('[data-unsaved]').isVisible(), 'Unsaved changes remain visible');
    await page.locator('[data-undo]').click();
    check(await page.evaluate(() => JSON.stringify(window.calibrationCards['gate-1'].editor.mask)) === initial, 'Undo restores exact geometry');
    await page.locator('[data-redo]').click();
    await page.locator('[data-discard]').click();
    check(await page.evaluate(() => JSON.stringify(window.calibrationCards['gate-1'].editor.mask)) === initial, 'Discard restores saved geometry');
    await page.locator('[data-away-direction] input[value=OUT]').check();
    check(await page.locator('[data-video]').evaluate((node, original) => node === original, originalPlayer), 'Unrelated direction controls preserve the video DOM node');
    await page.locator('[data-discard]').click();
    await page.screenshot({ path: path.join(screenshots, 'calibration-125-percent-layout.png'), fullPage: true });
    await cdp.send('Emulation.clearDeviceMetricsOverride');
    await page.setViewportSize({ width: 1366, height: 768 });
    for (const url of ['/admin', '/gates', '/registry', '/visitors', '/logs']) await open(url);
    await page.locator('.sidebar-logout-button').click(); await page.waitForURL(/\/login$/);
    await login('legacy@school.test');
    check(await page.locator('[name=current_password]').isVisible(), 'Legacy password transition still renders');
    await page.goto(base + '/settings'); await page.waitForURL(/\/account\/password$/);
    await page.getByRole('button', { name: /logs*out/i }).click(); await page.waitForURL(/\/login$/);
    check(errors.length === 0, 'No browser JavaScript exceptions: ' + errors.join('; '));
    console.log(`PASS: ${checks} browser assertions. Screenshots: ${screenshots}`);
} finally {
    await browser?.close();
    if (server) {
        const exited = new Promise((resolve) => server.once('exit', resolve));
        server.kill(); await Promise.race([exited, delay(3000)]);
    }
    fs.rmSync(directory, { recursive: true, force: true });
}
