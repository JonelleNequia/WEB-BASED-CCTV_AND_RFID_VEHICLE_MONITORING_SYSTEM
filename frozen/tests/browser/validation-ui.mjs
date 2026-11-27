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
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-validation-'));
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
    const fixture = spawnSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/ui-workspace.php', directory], {
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

    const token = await page.locator('meta[name=csrf-token]').getAttribute('content');
    const headers = { 'X-CSRF-TOKEN': token, Accept: 'application/json' };
    const tagResponse = await page.request.post(base + '/rfid-inventory', {
        headers, data: { uid: '0000-FIXTURE', auto_number: 1 },
    });
    check(tagResponse.status() === 201, 'Fixture RFID tag is created');
    const fixtureTag = await tagResponse.json();
    const vehicleResponse = await page.request.post(base + '/vehicle-registry', {
        headers, data: { rfid_uid: '0000-FIXTURE', display_number: fixtureTag.number_label, plate_number: 'UIV-0001',
            registered_by: 'UI Test Registrar',
            vehicle_owner_name: 'Validation Owner', category: 'faculty_staff', vehicle_type: 'Car' },
    });
    check(vehicleResponse.status() === 201, 'Fixture vehicle is created');
    const { vehicle_id: id } = await vehicleResponse.json();
    await page.goto(base + '/vehicle-registry/' + id + '/edit');
    await page.locator('#vehicle_owner_name').fill('Owner 123');
    await Promise.all([
        page.waitForResponse((response) => response.request().method() === 'POST' && response.url() === base + '/vehicle-registry/' + id),
        page.getByRole('button', { name: 'Update Vehicle' }).click(),
    ]);
    await page.locator('.field-error').filter({ hasText: 'Digits, emojis' }).waitFor();
    check(await page.locator('#vehicle_owner_name').inputValue() === 'Owner 123', 'Rejected name remains editable');
    check(await page.locator('#plate_number').inputValue() === 'UIV-0001', 'Valid plate survives rejected submission');
    const visibleErrors = await page.locator('.field-error:visible').allTextContents();
    check(visibleErrors.length === 1, 'Name error is field-specific: ' + JSON.stringify(visibleErrors));
    check(await page.locator('#vehicle_type').inputValue() === 'Car', 'Valid vehicle type survives rejected submission');
    check(await page.locator('#vehicle_type_other').isHidden(), 'Empty custom type does not become required after rejection');
    await page.locator('#vehicle_owner_name').fill('O’Connor');
    await Promise.all([
        page.waitForURL(/\/registry\?tab=vehicles$/),
        page.getByRole('button', { name: 'Update Vehicle' }).click(),
    ]);
    check(await page.getByText('UIV-0001 was updated.', { exact: true }).isVisible(), 'Corrected Unicode name saves successfully');
    console.log('Name rejection, input preservation and corrected submission passed.');

    const drawerTagResponse = await page.request.post(base + '/rfid-inventory', {
        headers, data: { uid: '0000-DRAWER', auto_number: 1 },
    });
    check(drawerTagResponse.status() === 201, 'Drawer fixture tag is created');
    const { rfid_tag_id: drawerTagId, number_label: drawerTagNumber } = await drawerTagResponse.json();
    await page.goto(base + '/registry?tab=vehicles');
    await page.locator('[data-drawer-open="add-vehicle-drawer"]').first().click();
    await page.locator('#add_available').selectOption(String(drawerTagId));
    await page.locator('#add_tag_number').fill(drawerTagNumber);
    await page.locator('#add_plate_number').fill('UIV-0002');
    await page.locator('#add_vehicle_type').selectOption('Car');
    await page.locator('#add_owner').fill('Drawer 123');
    check(await page.getByRole('button', { name: 'Save Vehicle', exact: true }).isDisabled(), 'Invalid owner disables the new-registration submit control');
    await page.locator('#add-vehicle-drawer .field-error').filter({ hasText: 'Digits, emojis' }).waitFor();
    check(await page.locator('#add_vehicle_type').inputValue() === 'Car', 'Drawer retains valid vehicle type');
    check(await page.locator('#add_vehicle_type_other').isHidden(), 'Drawer does not require a blank custom type');
    check(await page.locator('#add_available').inputValue() === String(drawerTagId), 'Drawer retains chosen RFID tag');
    check(await page.locator('#add_plate_number').inputValue() === 'UIV-0002', 'Drawer retains valid plate');
    await page.locator('#add_owner').fill('Ma. José Dela Cruz');
    await page.getByRole('button', { name: 'Save Vehicle', exact: true }).click();
    await page.getByText('UIV-0002 was saved to the local vehicle registry.', { exact: true }).waitFor();
    check(await page.getByText('UIV-0002 was saved to the local vehicle registry.', { exact: true }).isVisible(), 'Corrected drawer submission succeeds');

    await page.goto(base + '/registry?tab=vehicles&q=UIV&state=invalid');
    check(new URL(page.url()).searchParams.get('q') === 'UIV', 'Valid search remains after invalid filter');
    check(!new URL(page.url()).searchParams.has('state'), 'Rejected filter is removed from redirected URL');
    check(await page.locator('[name=q]').inputValue() === 'UIV', 'Search control retains valid input');
    check(await page.getByText('Some filters could not be applied. Your other selections have been kept.', { exact: true }).isVisible(), 'List page explains invalid filters');
    const report = await page.goto(base + '/vehicle-events/export/csv?date_from=invalid');
    check(report.status() === 422, 'Invalid export is rejected');
    check(await page.getByRole('link', { name: 'Return to filters' }).isVisible(), 'Rejected export has a recovery link');
    console.log(`Validation browser checks: ${checks} passed.`);
} finally {
    if (browser) await browser.close();
    if (server) server.kill('SIGTERM');
    fs.rmSync(directory, { recursive: true, force: true });
}
