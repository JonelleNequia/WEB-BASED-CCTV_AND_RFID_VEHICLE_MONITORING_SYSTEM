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
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-vehicle-types-'));
fs.chmodSync(directory, 0o700);
const env = {
    ...process.env, APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_URL: '',
    DB_DATABASE: directory + '/ui.sqlite', SESSION_DRIVER: 'database', CACHE_STORE: 'array',
    LOG_CHANNEL: 'testing', CAMERA_FILES_PATH: directory + '/camera', DEVICE_FILES_PATH: directory + '/devices',
    ARGON_MEMORY: '1024', ARGON_TIME: '1', DETECTOR_API_KEY: 'phase4-browser-key', PYTHONDONTWRITEBYTECODE: '1',
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

    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const token = await page.locator('meta[name=csrf-token]').getAttribute('content');
    const headers = { 'X-CSRF-TOKEN': token, Accept: 'application/json' };
    const integration = { ...headers, 'X-Api-Key': 'phase4-browser-key' };
    const requested = ['E-bike', 'E-trike', 'E-car', 'Car', 'Truck', 'Tricycle', 'Motorcycle', 'Bus', 'Van'];
    const verifyChoices = async (selector) => {
        const values = await page.locator(selector + ' option').evaluateAll((nodes) => nodes.map((node) => node.value));
        for (const type of requested) check(values.includes(type), selector + ' offers ' + type);
    };
    await page.goto(base + '/registry');
    check(await page.locator('#add_vehicle_type').inputValue() === '', 'New registration requires an explicit type instead of silently selecting Car');
    await verifyChoices('#add_vehicle_type');
    await page.locator('[data-drawer-open="add-vehicle-drawer"]').first().click();
    await page.waitForFunction(() => document.getElementById('add-vehicle-drawer').classList.contains('is-open'));
    await page.locator('#add_owner').fill('Fixture Owner');
    await page.locator('#add_plate_number').fill('pc 00001');
    await page.locator('#add_registered_by').fill('Phase Four Registrar');
    await page.locator('#add_vehicle_type').selectOption('E-bike');
    await page.getByRole('button', { name: 'Save Vehicle', exact: true }).click();
    await page.getByText('PC 00001 was saved to the local vehicle registry.', { exact: true }).waitFor();
    const vehicleUrl = await page.locator('[data-vehicle-row]').first().getAttribute('data-vehicle-url');
    const id = Number(vehicleUrl.split('/').pop());
    const vehicle = async () => (await (await page.request.get(vehicleUrl)).json());
    check((await vehicle()).vehicle_type === 'E-bike', 'Specific electric type is stored without a dummy tag');
    const at = new Date().toISOString();
    check((await page.request.post(base + '/api/v1/integration/crossings', { headers: integration, data: {
        external_event_key: 'phase4-type-pass', camera_role: 'gate-1', direction: 'IN', event_time: at,
        detected_vehicle_type: 'Motorcycle', detection_metadata: { rfid_status: 'no_pass' },
    } })).status() === 201, 'Simulated generic detector crossing accepted');
    const read = await page.request.post(base + '/api/v1/integration/visitor-plates', { headers: integration, data: {
        external_event_key: 'phase4-type-pass', camera_role: 'gate-1', event_time: at, plate_status: 'read',
        plate_number: 'PC00001', plate_confidence: .95, detected_vehicle_type: 'Motorcycle',
    } });
    const eventId = (await read.json()).visitor_record.vehicle_event_id;
    check(Boolean(eventId) && (await vehicle()).entries_today === 1, 'Registry identity creates one canonical entry');
    await page.goto(base + '/vehicle-events/' + eventId);
    check(await page.getByText('Registry record', { exact: true }).isVisible(), 'Details distinguish registry type from independent visual recognition');
    check(await page.getByText('Motorcycle', { exact: true }).first().isVisible(), 'Raw detector result remains visible');
    await verifyChoices('#occurrence_vehicle_type');
    check(await page.locator('#occurrence_vehicle_type').inputValue() === 'E-bike', 'Occurrence editor starts from the specific effective type');
    await page.locator('#occurrence_vehicle_type').selectOption('E-trike');
    await page.getByRole('button', { name: 'Save type', exact: true }).click();
    await page.getByText('Staff confirmed', { exact: true }).waitFor();
    check((await vehicle()).vehicle_type === 'E-bike' && (await vehicle()).entries_today === 1, 'Occurrence correction leaves registry and occupancy unchanged');
    const late = await page.request.post(base + '/api/v1/integration/crossings', { headers: integration, data: {
        external_event_key: 'phase4-type-pass', camera_role: 'gate-1', direction: 'IN', event_time: at, detected_vehicle_type: 'Car',
    } });
    check(late.status() === 200, 'Late generic detector update reuses the occurrence');
    await page.reload();
    check(await page.locator('#occurrence_vehicle_type').inputValue() === 'E-trike', 'Staff type survives late generic evidence and reload');
    check((await vehicle()).entries_today === 1 && (await vehicle()).current_state === 'inside', 'A type update does not replay direction or movement');
    await page.goto(base + '/logs?log_type=registered');
    const logs = await page.locator('#event-log-modal-data').evaluate((node) => JSON.parse(node.textContent));
    check(logs.length === 1 && logs[0].vehicle_type === 'E-trike' && logs[0].vehicle_type_source === 'Staff confirmed', 'Activity Logs uses the same effective type and provenance');
    const csv = await page.request.get(base + '/vehicle-events/export/csv?all=1');
    check((await csv.text()).includes('E-trike'), 'CSV retains staff-confirmed type');
    await page.goto(base + '/registry');
    await page.locator('[data-vehicle-row] details summary').first().click();
    await page.getByRole('menuitem', { name: 'Edit details', exact: true }).first().click();
    await page.waitForFunction(() => document.getElementById('edit-vehicle-drawer').classList.contains('is-open'));
    await verifyChoices('#edit_vehicle_type');
    check(await page.locator('#edit_vehicle_type').inputValue() === 'E-bike', 'Registry editor still shows its original specific type');
    await page.locator('#edit_vehicle_type').selectOption('E-car');
    await page.getByRole('button', { name: 'Save Changes', exact: true }).click();
    await page.getByText('PC 00001 was updated.', { exact: true }).waitFor();
    check((await vehicle()).vehicle_type === 'E-car' && (await vehicle()).registered_by === 'Phase Four Registrar', 'Registry edit preserves original staff attribution');
    await page.goto(base + '/vehicle-events/' + eventId);
    check(await page.locator('#occurrence_vehicle_type').inputValue() === 'E-trike', 'Registry editing does not relabel historical occurrence evidence');
    await page.goto(base + '/visitors');
    await verifyChoices('#manual_type');
    check(await page.locator('#manual_type').inputValue() === '', 'Optional manual type stays blank instead of defaulting to Car');
    const corrections = await page.locator('#visitor-type-modal [name=vehicle_type]').evaluateAll((nodes) => nodes.map((node) => node.value));
    check(requested.every((type) => corrections.includes(type)), 'Existing Correct Type dialog shares the same choices');
    await page.setViewportSize({ width: 390, height: 844 });
    check(await page.locator('body').evaluate((body) => body.scrollWidth <= window.innerWidth), 'Type choices do not introduce horizontal page overflow');
    await page.locator('#visitor-record-1 details summary').click();
    await page.locator('#visitor-record-1 [data-visitor-action="type"]').click();
    await page.locator('#visitor-type-modal').waitFor({ state: 'visible' });
    const selectedType = page.locator('#visitor-type-modal input[value="E-trike"]');
    check(await selectedType.isChecked(), 'Existing correction dialog selects the saved specific type');
    await selectedType.focus();
    await selectedType.press('ArrowRight');
    check(await page.locator('#visitor-type-modal input[value="E-car"]').isChecked(), 'Physical type choices support standard radio keyboard navigation');
    await page.locator('#visitor-type-modal').getByRole('button', { name: 'Save type', exact: true }).click();
    await page.getByText('Record #1 is now a E-car.', { exact: true }).waitFor();
    check((await vehicle()).entries_today === 1, 'Narrow-screen correction leaves movement counts unchanged');
    check(errors.length === 0, 'No browser script errors: ' + errors.join('; '));
    console.log(`Vehicle types browser checks: ${checks} passed.`);
} finally {
    if (browser) await browser.close();
    if (server) server.kill('SIGTERM');
    fs.rmSync(directory, { recursive: true, force: true });
}
