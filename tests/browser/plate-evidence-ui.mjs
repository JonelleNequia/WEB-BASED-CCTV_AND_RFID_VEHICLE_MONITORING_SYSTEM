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
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-plate-evidence-'));
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
    const fixture = spawnSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/plate-evidence-ui.php', directory], {
        cwd: root, env, encoding: 'utf8', timeout: 30000,
    });
    assert.equal(fixture.status, 0, fixture.stderr || fixture.stdout);
    assert.ok(fs.existsSync(directory + '/plate-evidence.json'), fixture.stdout + fixture.stderr);
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

    const errors = []; page.on('pageerror', error => errors.push(error.message));

    const records = JSON.parse(fs.readFileSync(directory + '/plate-evidence.json', 'utf8'));
    const artifacts = path.join(root, 'storage/app/ui-review/plate-evidence'); fs.mkdirSync(artifacts, { recursive: true });
    const open = async (name) => {
        await page.goto(base + '/visitors?tab=review');
        await page.waitForFunction(() => globalThis.PhilcstPlate && document.readyState === 'complete');
        const card = page.locator('#review-card-' + records[name]);
        for (let n = 0; n < 10 && !(await card.isVisible()); n++) await page.locator('[data-review-next]').click();
        assert.ok(await card.isVisible(), 'Review card available: ' + name + ' #' + records[name] + ' ' + JSON.stringify(await page.locator('[data-review-card]').evaluateAll(nodes => nodes.map(node => ({ id: node.id, hidden: node.hidden })))));
        return card;
    };
    const action = async (card, value) => {
        const [result] = await Promise.all([
            page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/review')),
            card.locator('button:not(.sr-only)[name=action][value="' + value + '"]:visible').click(),
        ]);
        check(result.status() === 200, 'Review action succeeds: ' + value);
        const data = await result.json(); check(data.ok === true, 'Server confirms action: ' + value);
        return data;
    };
    let card = await open('actual');
    check((await card.innerText()).includes('Identifier type is unconfirmed'), 'Clear OCR and Registry hit still require type confirmation');
    check(await card.locator('.review-plate-zoom img').count() === 1, 'Linked identifier image is available');
    await card.locator('.review-ocr-evidence summary').click();
    check((await card.locator('.review-ocr-evidence').innerText()).includes('395ISX'), 'Raw OCR is inspectable');
    const image = await card.locator('.review-plate-zoom img').getAttribute('src');
    await card.locator('[data-review-plate-input]').fill('395/ISX');
    check(await card.locator('[data-review-enter]').isDisabled(), 'Save plate rejects invalid text');
    await card.locator('button[value="set_direction:IN"]').click();
    await page.getByText('Record #' + records.actual + ' set to IN.', { exact: true }).waitFor();
    check((await card.locator('[data-review-direction]').innerText()) === 'IN', 'Set direction ignores stale invalid plate and updates the card');
    check(await card.locator('[data-review-direction-badge]').isHidden(), 'Resolved direction removes the stale Direction unknown badge');
    check(await card.locator('[data-review-plate-input]').inputValue() === '395/ISX', 'Unrelated action retains the invalid input for correction');
    await page.screenshot({ path: path.join(artifacts, 'stale-input-direction.png'), fullPage: true });
    await card.locator('[data-review-plate-input]').fill('395isx');
    check(await card.locator('[data-review-enter]').isEnabled(), 'Correcting the input recovers Save plate');
    const confirmed = await action(card, 'correct_plate');
    check(confirmed.registration_status === 'registered' && confirmed.plate_number === '395ISX', 'Staff-confirmed actual plate matches the eligible Registry record');
    await page.goto(base + '/logs?plate_text=395ISX');
    let logs = await page.locator('#event-log-modal-data').evaluate(node => JSON.parse(node.textContent));
    const event = logs.find(row => row.record_type === 'vehicle_event');
    check(!!event && event.plate_number === '395ISX', 'Activity Logs uses the confirmed literal plate');
    const csrf = await page.locator('meta[name=csrf-token]').getAttribute('content');
    const retry = await page.request.patch(base + '/visitors/records/' + records.actual + '/review', {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, data: { action: 'correct_plate', plate: '395isx' } });
    check(retry.status() === 200, 'Correction retry succeeds');
    const after = await (await page.request.get(base + '/logs?plate_text=395isx', { headers: { Accept: 'application/json' } })).json();
    check(after.logs.filter(row => row.record_type === 'vehicle_event').length === 1
        && after.logs.find(row => row.record_type === 'vehicle_event').id === event.id, 'Retry retains one event ID');
    check(event.image_url || image, 'Occurrence media remains linked');
    for (const [name, kind, label] of [['temporary', 'temporary_plate', 'Temporary plate'], ['conduction', 'conduction_sticker', 'Conduction sticker'], ['mv', 'mv_file', 'MV file number']]) {
        card = await open(name);
        await card.locator('[data-review-plate-input]').fill('STALE/INVALID');
        await card.locator('.review-temporary summary').click();
        await card.locator('[name=identifier_type]').selectOption(kind);
        await card.locator('[name=identifier_text]').fill('395ISX');
        if (name === 'conduction') {
            await page.setViewportSize({ width: 390, height: 844 });
            await page.screenshot({ path: path.join(artifacts, 'temporary-mobile.png'), fullPage: true });
        }
        const data = await action(card, 'temporary_identifier');
        check(data.registration_status === 'guest' && data.plate_number === null, 'Confirmed ' + label + ' is evidence only, despite the same Registry text');
        await page.setViewportSize({ width: 1366, height: 768 });
        await page.goto(base + '/visitors');
        const row = page.locator('#visitor-record-' + records[name]);
        check((await row.innerText()).includes(label + ' (no actual plate)') && (await row.innerText()).includes('Evidence only: 395ISX'), 'Guests labels evidence without claiming an actual plate');
    }
    card = await open('no_plate');
    await card.locator('[data-review-plate-input]').fill('BAD/PLATE');
    check(await card.locator('[data-review-enter]').isDisabled(), 'Invalid text still cannot be saved as a plate');
    const noPlate = await action(card, 'no_plate');
    check(noPlate.needs.plate === false, 'No plate visible succeeds independently of stale text');
    card = await open('dismiss'); await card.locator('[data-review-plate-input]').fill('BAD/PLATE');
    const dismissed = await action(card, 'dismiss:not_vehicle');
    check(dismissed.needs.plate === false, 'Dismiss succeeds independently of stale text');
    await page.goto(base + '/logs');
    logs = await page.locator('#event-log-modal-data').evaluate(node => JSON.parse(node.textContent));
    const temporary = logs.filter(row => row.status_label === 'Evidence only');
    check(temporary.length === 3 && temporary.every(row => row.log_type === 'unregistered_visitor' && row.identifier_text === '395ISX'), 'Logs classifications and evidence agree with Guests');
    await page.screenshot({ path: path.join(artifacts, 'activity-evidence.png'), fullPage: true });
    const index = logs.findIndex(row => row.record_type === 'visitor_record' && row.id === records.conduction);
    await page.locator('[data-event-log-view="' + index + '"]').click();
    check((await page.locator('[data-log-details-grid]').innerText()).includes('Conduction sticker')
        && (await page.locator('[data-log-details-grid]').innerText()).includes('395ISX'), 'Existing Details displays identifier type and evidence text');

    const plain = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 1366, height: 768 } });
    await plain.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    const fallback = await plain.newPage();
    await fallback.goto(base + '/login');
    await fallback.locator('[name=email]').fill(credentials.email); await fallback.locator('[name=password]').fill(credentials.password);
    await fallback.locator('button[type=submit]').click(); await fallback.waitForURL(/\/admin$/);
    await fallback.goto(base + '/visitors?tab=review');
    const fallbackCard = fallback.locator('#review-card-' + records.direction);
    await fallbackCard.locator('[name=plate]').fill('INVALID/PLATE');
    await fallbackCard.locator('[data-review-enter]').click();
    check((await fallback.locator('body').innerText()).includes('Unsupported symbols'), 'Without JavaScript, Save plate is rejected server-side');
    check(await fallbackCard.locator('[name=plate]').inputValue() === 'INVALID/PLATE', 'Without JavaScript, rejected text is retained');
    await fallbackCard.locator('button[value=no_plate]').click();
    check((await fallback.locator('body').innerText()).includes('no plate visible (still counted)'), 'Plain form No plate visible ignores stale invalid text');
    await fallbackCard.locator('button[value="set_direction:OUT"]').click();
    check((await fallback.locator('body').innerText()).includes('set to OUT'), 'Plain form Set direction validates independently');
    await plain.close();
    check(errors.length === 0, 'No browser script errors');
    console.log(`Plate evidence browser checks: ${checks} passed.`);
} finally {
    if (browser) await browser.close();
    if (server) server.kill('SIGTERM');
    fs.rmSync(directory, { recursive: true, force: true });
}
