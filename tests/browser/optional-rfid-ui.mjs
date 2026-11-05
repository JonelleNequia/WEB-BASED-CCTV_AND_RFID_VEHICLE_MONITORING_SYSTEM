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
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-optional-rfid-'));
fs.chmodSync(directory, 0o700);
const env = {
    ...process.env, APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_URL: '',
    DB_DATABASE: directory + '/ui.sqlite', SESSION_DRIVER: 'database', CACHE_STORE: 'array',
    LOG_CHANNEL: 'testing', CAMERA_FILES_PATH: directory + '/camera', DEVICE_FILES_PATH: directory + '/devices',
    ARGON_MEMORY: '1024', ARGON_TIME: '1', DETECTOR_API_KEY: 'phase3-browser-key', PYTHONDONTWRITEBYTECODE: '1',
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
    const integration = { ...headers, 'X-Api-Key': 'phase3-browser-key' };
    const getVehicle = async () => (await (await page.request.get(base + '/registry/vehicles/' + id)).json());
    const ready = () => page.waitForFunction(() => document.querySelector('[data-auto-list]')?.dataset.autoState === 'ready');

    await page.goto(base + '/registry');
    await page.locator('[data-drawer-open="add-vehicle-drawer"]').first().click();
    await page.waitForFunction(() => document.getElementById('add-vehicle-drawer').classList.contains('is-open'));
    check(await page.locator('#add_registered_by').inputValue() === '', 'Fresh installation staff default remains blank');
    await page.locator('#add_owner').fill('Fixture Owner');
    await page.locator('#add_plate_number').fill('ab 00001');
    await page.locator('#add_registered_by').fill('First Registrar');
    await page.locator('#add_vehicle_type').selectOption('Car');
    check(await page.locator('#add_available').inputValue() === '' && await page.locator('#add_scan').inputValue() === '', 'Optional RFID starts unassigned');
    await page.getByRole('button', { name: 'Save Vehicle', exact: true }).click();
    await page.getByText('AB 00001 was saved to the local vehicle registry.', { exact: true }).waitFor();
    const detailUrl = await page.locator('[data-vehicle-row]').first().getAttribute('data-vehicle-url');
    const id = Number(detailUrl.split('/').pop());
    let vehicle = await getVehicle();
    check(vehicle.tag === null && vehicle.registered_by === 'First Registrar', 'Untagged registration stores staff attribution and no dummy assignment');
    const untaggedRow = await page.locator('[data-vehicle-row]').first().innerText();
    check(untaggedRow.toLowerCase().includes('no rfid assigned'), 'Registry list clearly labels the untagged vehicle');
    await page.goto(base + '/registry?tab=tags');
    check(await page.getByText('No RFID tags registered yet.', { exact: true }).isVisible(), 'Untagged registration does not create inventory entries');

    const occurrenceTime = new Date().toISOString();
    const crossing = await page.request.post(base + '/api/v1/integration/crossings', { headers: integration, data: {
        external_event_key: 'phase3-browser-pass', camera_role: 'gate-1', direction: 'IN',
        event_time: occurrenceTime, detection_metadata: { rfid_status: 'no_pass' },
    } });
    check(crossing.status() === 201, 'Simulated camera crossing is accepted');
    const crossingBody = await crossing.json();
    const guestCopy = await page.request.post(base + '/api/guest-observation', { headers: integration, data: {
        external_event_key: 'phase3-browser-pass', camera_role: 'gate-1', event_time: occurrenceTime,
        detected_vehicle_type: 'Car', vehicle_image_path: 'fixture/guest.jpg', detection_metadata: { direction: 'IN' },
    } });
    check(guestCopy.status() === 201, 'Legacy Guest evidence is accepted before plate identification');
    const plateResponse = await page.request.post(base + '/api/v1/integration/visitor-plates', { headers: integration, data: {
        external_event_key: 'phase3-browser-pass', camera_role: 'gate-1', event_time: occurrenceTime,
        plate_status: 'read', plate_number: 'ab-00001', plate_confidence: 0.95,
    } });
    check(plateResponse.status() === 200, 'Simulated reliable plate vote is accepted');
    const plateBody = await plateResponse.json();
    const eventId = plateBody.visitor_record.vehicle_event_id;
    check(plateBody.visitor_record.registration_status === 'registered' && eventId, 'OCR identifies the untagged registration and exposes its canonical movement');
    vehicle = await getVehicle();
    check(vehicle.current_state === 'inside' && vehicle.entries_today === 1, 'Plate IN updates actual occupancy once');
    const duplicateVote = await page.request.post(base + '/api/v1/integration/visitor-plates', { headers: integration, data: {
        external_event_key: 'phase3-browser-pass', camera_role: 'gate-1', event_time: occurrenceTime,
        plate_status: 'read', plate_number: 'AB00001', plate_confidence: 0.95,
    } });
    check(duplicateVote.status() === 200 && (await duplicateVote.json()).visitor_record.vehicle_event_id === eventId, 'Repeated OCR keeps the same movement event');
    check((await getVehicle()).entries_today === 1, 'Repeated OCR does not increment the entry count');

    await page.goto(base + '/logs?tab=events&log_type=registered');
    let logs = await page.locator('#event-log-modal-data').evaluate((node) => JSON.parse(node.textContent));
    check(logs.length === 1 && logs[0].source_label === 'OCR plate' && logs[0].rfid_tag_uid === 'N/A', 'Activity Logs preserves real plate provenance without fake RFID');
    check(!logs[0].is_alert && logs[0].status_label === 'Inside' && !logs[0].alert_reason, 'Adopted Guest copy has no stale classification anomaly');
    check(!(await page.locator('[data-event-log-list]').innerText()).includes('Unregistered guest'), 'Rendered Registered row has no stale Guest subtitle');
    await page.locator('[data-event-log-view]').first().click();
    check(!(await page.locator('#log-details').innerText()).includes('Tag not read'), 'Details does not warn solely because no RFID was assigned');
    await page.locator('#log-details button[data-drawer-close]').click();
    const csv = await page.request.get(base + '/vehicle-events/export/csv?all=1&log_type=registered');
    check(csv.status() === 200 && (await csv.text()).includes('OCR plate'), 'Registered export includes the plate movement once');
    await page.goto(base + '/vehicle-events/' + eventId);
    check((await page.locator('body').innerText()).includes('No RFID scan linked'), 'Movement details support an absent RFID relationship');
    check(!(await page.locator('body').innerText()).includes('Unregistered guest'), 'Full details uses the corrected occurrence classification');

    const createTag = async (uid) => {
        const response = await page.request.post(base + '/rfid-inventory', { headers, data: { uid, auto_number: 1 } });
        assert.equal(response.status(), 201);
        return (await response.json()).rfid_tag_id;
    };
    const assign = async (tagId, expectedLabel) => {
        await page.goto(base + '/registry');
        await page.locator('[data-vehicle-row]').first().click();
        await page.waitForFunction(() => document.getElementById('vehicle-panel').classList.contains('is-open'));
        await page.locator('#vehicle-panel').getByRole('button', { name: expectedLabel, exact: true }).click();
        await page.waitForFunction(() => document.getElementById('replace-tag-drawer').classList.contains('is-open'));
        await page.locator('#replace_available').selectOption(String(tagId));
        await page.locator('#replace-tag-drawer').getByRole('button', { name: expectedLabel, exact: true }).click();
        await page.getByText(/AB 00001 now uses/).waitFor();
    };
    const firstTag = await createTag('BROWSER-AVAILABLE-1');
    await assign(firstTag, 'Assign Tag');
    vehicle = await getVehicle();
    check(vehicle.tag.id === firstTag && vehicle.current_state === 'inside' && vehicle.entries_today === 1, 'Later assignment retains vehicle ID and occupancy');
    check(vehicle.registered_by === 'First Registrar', 'Later assignment does not rewrite original staff attribution');
    check(await page.locator('#add_registered_by').inputValue() === 'First Registrar', 'Later assignment does not change the remembered default');

    await page.goto(base + '/registry?tab=tags');
    let tagRow = page.locator('tbody tr').filter({ hasText: 'BROWSER-AVAILABLE-1' });
    await tagRow.getByRole('button', { name: 'Mark lost', exact: true }).click();
    await page.locator('#confirm-dialog').waitFor({ state: 'visible' });
    check((await page.locator('#confirm-dialog-message').innerText()).includes('permanently Lost / Unavailable'), 'Mark-lost confirmation explains permanent unavailability');
    await page.locator('#confirm-dialog [data-confirm-yes]').click();
    await page.getByText('Lost / Unavailable', { exact: true }).first().waitFor();
    tagRow = page.locator('tbody tr').filter({ hasText: 'BROWSER-AVAILABLE-1' });
    check((await tagRow.innerText()).toLowerCase().includes('lost / unavailable') && await tagRow.locator('button').count() === 0, 'Lost inventory row has a permanent label and no reactivation action');
    const ignored = await page.request.post(base + '/api/v1/integration/rfid-scans', { headers: integration, data: {
        tag_uid: 'b r o w s e r - a v a i l a b l e - 1', scan_location: 'gate-1',
    } });
    check(ignored.status() === 200 && (await ignored.json()).outcome === 'ignored', 'Canonical-format lost reads are quietly ignored');
    check((await getVehicle()).current_state === 'inside', 'Losing or reading the old tag does not unregister or move the vehicle');
    const rejectEnable = await page.request.post(base + '/registry/tags/' + firstTag + '/status', { headers, data: { status: 'available' } });
    check(rejectEnable.status() === 422, 'Backend rejects reactivation');
    const rejectDuplicate = await page.request.post(base + '/rfid-inventory', { headers, data: { uid: 'browser-available-1', auto_number: 1 } });
    check(rejectDuplicate.status() === 422, 'Backend rejects recreation of the lost UID');

    await page.goto(base + '/gates/gate-1/kiosk');
    const kioskIgnored = page.waitForResponse((response) => new URL(response.url()).pathname === '/station/gate-1/rfid-scan');
    await page.locator('[data-rfid-input]').fill('BROWSER-AVAILABLE-1');
    await page.locator('[data-rfid-input]').press('Enter');
    check((await (await kioskIgnored).json()).outcome === 'ignored', 'Kiosk input shares permanent lost enforcement');
    await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    check(await page.locator('[data-station-alert]').isHidden(), 'Lost read does not open an operator alert');
    check(!(await page.locator('[data-scan-result]').innerText()).includes('BROWSER-AVAILABLE-1'), 'Lost read does not replace the camera result with a tag popup');

    const secondTag = await createTag('BROWSER-REPLACEMENT-2');
    await assign(secondTag, 'Replace Tag');
    vehicle = await getVehicle();
    check(vehicle.tag.id === secondTag && vehicle.current_state === 'inside' && vehicle.entries_today === 1, 'Replacing a lost tag preserves vehicle identity and occupancy');
    check(vehicle.previous_tags.some((tag) => tag.uid === 'BROWSER-AVAILABLE-1' && tag.status === 'lost'), 'Previous lost assignment remains in history');
    check(vehicle.registered_by === 'First Registrar' && await page.locator('#add_registered_by').inputValue() === 'First Registrar', 'Replacement leaves Phase 1 attribution/default unchanged');

    const actualRead = await page.request.post(base + '/api/v1/integration/rfid-scans', { headers: integration, data: {
        tag_uid: 'BROWSER-REPLACEMENT-2', scan_location: 'gate-1',
    } });
    check(actualRead.status() === 201 && (await actualRead.json()).saved === true, 'The replacement tag can record genuine RFID evidence under the existing offline fallback');
    check((await getVehicle()).current_state === 'outside' && (await getVehicle()).exits_today === 1, 'Replacement tag read produces exactly one next transition');
    await page.goto(base + '/logs?tab=events');
    logs = await page.locator('#event-log-modal-data').evaluate((node) => JSON.parse(node.textContent));
    check(logs.length === 2 && logs.some((row) => row.source_label === 'OCR plate') && logs.some((row) => row.rfid_tag_uid === 'BROWSER-REPLACEMENT-2'), 'Logs contain one plate IN and one actual RFID OUT, without a lost-read record');
    const duplicateCrossing = await page.request.post(base + '/api/v1/integration/crossings', { headers: integration, data: {
        external_event_key: 'phase3-browser-pass', camera_role: 'gate-1', direction: 'IN', event_time: occurrenceTime,
    } });
    check(duplicateCrossing.status() === 200 && (await duplicateCrossing.json()).crossing.id === crossingBody.crossing.id, 'A retried crossing keeps its original identity');
    await page.goto(base + '/registry?state=outside');
    check(await page.locator('[data-vehicle-row]').count() === 1, 'Registry occupancy filter sees the reconciled state');
    check(errors.length === 0, 'No browser script errors');
    console.log(`Optional RFID browser checks: ${checks} passed.`);
} finally {
    if (browser) await browser.close();
    if (server) server.kill('SIGTERM');
    fs.rmSync(directory, { recursive: true, force: true });
}

