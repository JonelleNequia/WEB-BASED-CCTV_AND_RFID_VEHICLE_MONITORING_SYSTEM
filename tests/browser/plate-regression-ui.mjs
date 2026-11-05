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
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-plate-regression-'));
fs.chmodSync(directory, 0o700);
const env = {
    ...process.env, APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_URL: '',
    DB_DATABASE: directory + '/ui.sqlite', SESSION_DRIVER: 'database', CACHE_STORE: 'array',
    LOG_CHANNEL: 'testing', CAMERA_FILES_PATH: directory + '/camera', DEVICE_FILES_PATH: directory + '/devices',
    ARGON_MEMORY: '1024', ARGON_TIME: '1', DETECTOR_API_KEY: 'regression-browser-key', APP_DEBUG: 'true', PYTHONDONTWRITEBYTECODE: '1',
    APP_KEY: 'base64:' + randomBytes(32).toString('base64'),
    APP_CONFIG_CACHE: directory + '/config.php', APP_ROUTES_CACHE: directory + '/routes.php',
};
let server;
let browser;
let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; };

try {
    const fixture = spawnSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/plate-regression-ui.php', directory], {
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
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const credentials = JSON.parse(fs.readFileSync(directory + '/login.json', 'utf8'));
    await page.goto(base + '/login');
    await page.locator('[name=email]').fill(credentials.email);
    await page.locator('[name=password]').fill(credentials.password);
    await page.locator('button[type=submit]').click();
    await page.waitForURL(/\/admin$/);
    console.log('Isolated browser login passed.');

    const ids = JSON.parse(fs.readFileSync(directory + '/plate-regression.json', 'utf8'));
    await page.goto(base + '/visitors?tab=review');
    const card = page.locator('#review-card-' + ids.record);
    async function showCard(target) {
        for (let attempt = 0; attempt < 5 && await target.isHidden(); attempt++) await page.keyboard.press('j');
        await target.waitFor({ state: 'visible' });
    }
    await showCard(card);
    const plate = card.locator('[data-review-plate-input]');
    await plate.fill('395isx');
    await plate.blur();
    const typed = await plate.inputValue();
    const saved = page.waitForResponse((r) => r.url().includes('/records/' + ids.record + '/review') && r.request().method() === 'POST');
    await card.getByRole('button', { name: 'Save plate', exact: true }).click();
    const response = await saved;
    const body = await response.json();
    if (process.env.PLATE_REGRESSION_BEFORE === '1') {
        check(typed === 'ISX-395', 'Reproduced browser transposition');
        check(response.status() === 500 && /UNIQUE constraint failed: vehicle_events.external_event_key/.test(body.message), 'Reproduced duplicate-key failure and raw SQL error exposure');
        console.log('Before fix: literal became reversed; duplicate-key failure and raw SQL display reproduced with isolated data.');
    } else {
        check(typed === '395ISX', 'Literal input survives blur');
        check(response.ok() && body.plate_number === '395ISX' && body.registration_status === 'guest', 'Literal submission commits as Guest, not the reversed registry identity');
        check(!(await page.locator('[data-review-flash]').textContent()).includes('SQLSTATE'), 'Successful correction has safe feedback');
        const errorsIds = JSON.parse(fs.readFileSync(directory + '/plate-regression-errors.json', 'utf8'));
        const failure = page.locator('#review-card-' + errorsIds.failure);
        await showCard(failure);
        const failureInput = failure.locator('[data-review-plate-input]');
        await failureInput.fill('395/isx');
        const invalid = page.waitForResponse(r => r.url().endsWith('/records/' + errorsIds.failure + '/review') && r.request().method() === 'POST');
        await failure.getByRole('button', { name: 'Save plate', exact: true }).click();
        check((await invalid).status() === 422, 'Unsupported input receives validation feedback without identity extraction');
        await failure.locator('[data-review-error]').waitFor({ state: 'visible' });
        check(await failureInput.inputValue() === '395/ISX' && (await failure.locator('[data-review-error]').textContent()).includes('Unsupported'), 'Validation error preserves the entered plate and explains the field problem');
        await failureInput.fill('395isx');
        const failed = page.waitForResponse(r => r.url().endsWith('/records/' + errorsIds.failure + '/review') && r.request().method() === 'POST');
        await failure.getByRole('button', { name: 'Save plate', exact: true }).click();
        const failureResponse = await failed;
        const failureBody = await failureResponse.json();
        check(failureResponse.status() === 500 && failureBody.error_code === 'correction_failed', 'Real isolated SQL failure returns safe structured error even with APP_DEBUG=true');
        await failure.locator('[data-review-error]').waitFor({ state: 'visible' });
        check(await failureInput.inputValue() === '395ISX', 'Failed correction preserves literal staff input');
        check(!/SQLSTATE|database\.sqlite|\/private|insert into|stack trace/i.test(await failure.locator('[data-review-error]').textContent()), 'Inline error never exposes database diagnostics');
        check((await failure.locator('[data-review-error]').textContent()).includes('Try again'), 'Unexpected error is actionable');
        check(await page.locator('[data-review-flash]').isHidden() && await failure.locator('[data-review-error]').evaluate(n => n.getBoundingClientRect().top < innerHeight), 'Failed save clears previous success and shows its alert in view');
        const artifact = path.join(root, 'storage/app/ui-review/plate-regression');
        fs.mkdirSync(artifact, { recursive: true });
        await page.screenshot({ path: path.join(artifact, 'after-safe-error.png') });
        await page.route('**/visitors/records/' + errorsIds.failure + '/review', route => route.fulfill({ status: 500,
            contentType: 'application/json', body: JSON.stringify({ message: 'SQLSTATE /private/database.sqlite raw framework exception' }) }), { times: 1 });
        await failure.getByRole('button', { name: 'Save plate', exact: true }).click();
        await failure.locator('[data-review-error]').waitFor({ state: 'visible' });
        check(!(await failure.locator('[data-review-error]').textContent()).includes('SQLSTATE'), 'Client also rejects raw framework messages from a failed response');
        check(await failureInput.inputValue() === '395ISX', 'Transport failure does not clear input');
        await page.route('**/visitors/records/' + errorsIds.failure + '/review', route => route.fulfill({ status: 200,
            contentType: 'application/json', body: JSON.stringify({ ok: false, message: 'SQLSTATE false success' }) }), { times: 1 });
        await failure.getByRole('button', { name: 'Save plate', exact: true }).click();
        await failure.locator('[data-review-error]').waitFor({ state: 'visible' });
        check(await failure.isVisible() && !(await failure.locator('[data-review-error]').textContent()).includes('SQLSTATE'), 'A false-success response cannot remove the card or display diagnostics');
        await failureInput.blur();
        const conflict = page.locator('#review-card-' + errorsIds.conflict);
        await showCard(conflict);
        await conflict.locator('[data-review-plate-input]').fill('395isx');
        const rejected = page.waitForResponse(r => r.url().endsWith('/records/' + errorsIds.conflict + '/review') && r.request().method() === 'POST');
        await conflict.getByRole('button', { name: 'Save plate', exact: true }).click();
        check((await rejected).status() === 409, 'Incompatible event ownership is a controlled conflict');
        await conflict.locator('[data-review-error]').waitFor({ state: 'visible' });
        check((await conflict.locator('[data-review-error]').textContent()).includes('existing crossing evidence'), 'Conflict explains next step safely');
        check(await conflict.locator('[data-review-plate-input]').inputValue() === '395ISX', 'Conflict preserves entered literal');
        const htmlContext = await browser.newContext({ javaScriptEnabled: false, storageState: await context.storageState() });
        await htmlContext.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
        const hp = await htmlContext.newPage();
        await hp.goto(base + '/visitors?tab=review');
        const htmlCard = hp.locator('#review-card-' + errorsIds.failure);
        await htmlCard.locator('[data-review-plate-input]').fill('395isx');
        await htmlCard.getByRole('button', { name: 'Save plate', exact: true }).click();
        check(await hp.locator('#review-card-' + errorsIds.failure + ' [data-review-plate-input]').inputValue() === '395ISX', 'Non-JavaScript error redirect restores the correct card input');
        check(!/SQLSTATE|database\.sqlite|\/private|stack trace/i.test(await hp.locator('body').innerText()), 'Plain HTML error response contains no SQL/path/stack leakage');
        await htmlContext.close();
        check(errors.length === 0, 'No browser script errors');
        console.log(`Plate regression browser checks: ${checks} passed.`);
    }
} finally {
    if (browser) await browser.close();
    if (server) server.kill('SIGTERM');
    fs.rmSync(directory, { recursive: true, force: true });
}
