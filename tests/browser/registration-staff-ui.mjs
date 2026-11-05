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
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-staff-'));
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

    const scriptErrors = [];
    page.on('pageerror', (error) => scriptErrors.push(error.message));
    const headers = { 'X-CSRF-TOKEN': await page.locator('meta[name=csrf-token]').getAttribute('content'), Accept: 'application/json' };
    const openAdd = async () => {
        await page.locator('[data-drawer-open="add-vehicle-drawer"]').first().click();
        await page.waitForFunction(() => document.getElementById('add-vehicle-drawer').classList.contains('is-open'));
        await page.locator('#add_vehicle_type').selectOption('Car');
        await page.locator('#add_plate_number').fill('STF-7999');
        await page.locator('#add_owner').fill('Fixture Owner');
    };
    const closeAdd = async () => {
        await page.locator('#add-vehicle-drawer [data-drawer-close]').filter({ hasText: 'Cancel' }).click();
        await page.locator('#add-vehicle-drawer').waitFor({ state: 'hidden' });
    };
    const staff = page.locator('#add_registered_by');
    const error = page.locator('#add_registered_by_error');
    const save = page.getByRole('button', { name: 'Save Vehicle', exact: true });
    const tag = async (uid) => {
        const response = await page.request.post(base + '/rfid-inventory', { headers, data: { uid, auto_number: 1 } });
        assert.equal(response.status(), 201);
        return String((await response.json()).rfid_tag_id);
    };
    const submit = async (plate) => {
        await page.locator('#add_plate_number').fill(plate);
        await page.locator('#add_vehicle_type').selectOption('Car');
        await save.click();
        await page.getByText(plate + ' was saved to the local vehicle registry.', { exact: true }).waitFor();
    };

    await page.goto(base + '/registry?tab=vehicles');
    await openAdd();
    check(await staff.inputValue() === '', 'No remembered default means an initially blank staff field');
    check(await error.isHidden() && await save.isDisabled(), 'Untouched blank staff has no immediate error while required inputs prevent submission');
    await staff.focus();
    await page.locator('#add_plate_number').focus();
    check((await error.innerText()).includes('Enter the name of the staff member'), 'Blur shows a specific required message');
    check(await save.isDisabled() && await staff.getAttribute('aria-invalid') === 'true', 'Known-invalid field disables saving and exposes invalid state');

    for (const value of ['Staff 123', 'Staff 🚗', '---...', 'Staff & Co', 'ʼ', '\u007fStaff', 'A'.repeat(101)]) {
        await staff.fill(value);
        check(await error.isVisible() && await save.isDisabled() && await staff.inputValue() === value,
            'Invalid entered text stays visible with inline feedback: ' + value.slice(0, 12));
    }
    for (const value of ['Ma. José Dela Cruz', 'O’Connor', 'Anne-Marie', '李', 'Jose\u0301']) {
        await staff.fill(value);
        check(await error.isHidden() && await save.isEnabled(), 'Valid Unicode/personal name clears errors: ' + value);
    }
    await staff.evaluate((input) => {
        input.value = 'Pasted 123';
        input.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertFromPaste' }));
    });
    check(await error.isVisible() && await save.isDisabled(), 'Paste input receives immediate validation');
    await staff.evaluate((input) => {
        input.value = 'Anne-Marie';
        input.dispatchEvent(new Event('change', { bubbles: true }));
    });
    check(await error.isHidden() && await save.isEnabled(), 'Autofill-style change validates without a keypress');
    await staff.evaluate((input) => {
        input.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true }));
        input.value = 'Staff 123';
        input.dispatchEvent(new InputEvent('input', { bubbles: true, isComposing: true }));
    });
    check(await error.isHidden() && await save.isDisabled(), 'IME composition suspends new messages and prevents incomplete submission');
    await staff.evaluate((input) => input.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true })));
    check(await error.isVisible(), 'IME completion validates the final value');
    await staff.fill('Ma. José Dela Cruz');
    await closeAdd();
    await page.reload();
    check(await staff.inputValue() === '', 'Cancelling a form never remembers the typed name');

    const firstTag = await tag('STAFF-BROWSER-001');
    await page.reload();
    await openAdd();
    await page.locator('#add_available').selectOption(firstTag);
    await staff.fill('  Ma.  José   Dela Cruz  ');
    await submit('STF-7001');
    check(await staff.inputValue() === 'Ma. José Dela Cruz', 'Successful save prefills the normalized name on the next form');
    const firstUrl = await page.locator('[data-vehicle-row]').first().getAttribute('data-vehicle-url');
    const firstDetails = await (await page.request.get(firstUrl)).json();
    check(firstDetails.registered_by === 'Ma. José Dela Cruz', 'Registration details retain the submitted staff name');
    await page.locator('[data-vehicle-row]').first().click();
    await page.locator('[data-vehicle-panel] dt').filter({ hasText: 'Registered by' }).waitFor();
    check((await page.locator('[data-vehicle-panel]').innerText()).includes('Ma. José Dela Cruz'), 'Existing details drawer displays Registered by');
    await page.waitForFunction(() => document.getElementById('vehicle-panel').classList.contains('is-open'));
    await page.keyboard.press('Escape');
    await page.locator('#vehicle-panel').waitFor({ state: 'hidden' });

    await page.reload();
    await openAdd();
    check(await staff.inputValue() === 'Ma. José Dela Cruz', 'Shared default survives a page refresh and can be edited');
    const recoveryTag = await tag('STAFF-BROWSER-002');
    // A freshly added inventory tag is rendered after this explicit page refresh.
    await closeAdd();
    await page.reload();
    await openAdd();
    await page.locator('#add_available').selectOption(recoveryTag);
    await staff.fill('Anne-Marie');
    await page.locator('#add_plate_number').fill('---');
    await save.click();
    await page.locator('#add-vehicle-drawer .field-error').filter({ hasText: 'plate' }).waitFor();
    check(await staff.inputValue() === 'Anne-Marie', 'Server validation failure preserves the edited staff value');
    check(await error.isHidden() && await save.isEnabled(), 'Valid staff field remains recoverable after another field fails');
    const unfailed = await context.newPage();
    await unfailed.goto(base + '/registry');
    check(await unfailed.locator('#add_registered_by').inputValue() === 'Ma. José Dela Cruz', 'Failed registration does not update the default');
    await unfailed.close();
    await submit('STF-7002');
    check(await staff.inputValue() === 'Anne-Marie', 'Another successful registration replaces the remembered default');

    await openAdd();
    await staff.fill('Open Form Editor');
    const secondTag = await tag('STAFF-BROWSER-003');
    const secondResponse = await page.request.post(base + '/vehicle-registry', { headers, data: {
        vehicle_owner_name: 'Fixture Owner', rfid_tag_id: secondTag, plate_number: 'STF-7003', category: 'faculty_staff', vehicle_type: 'Car', registered_by: '李',
    } });
    check(secondResponse.status() === 201, 'Another valid registration can update the installation-wide default');
    check(await staff.inputValue() === 'Open Form Editor', 'An open edited form is not overwritten by another registration');
    await closeAdd();
    const updated = page.waitForResponse((response) => response.request().headers()['x-auto-filter'] === '1');
    await page.locator('#vehicle_q').fill('STF');
    await updated;
    await page.waitForFunction(() => document.querySelector('[data-auto-list]').getAttribute('aria-busy') !== 'true');
    check(await staff.inputValue() === 'Open Form Editor', 'Automatic list refresh keeps the mounted staff field and edited text');
    await page.reload();
    check(await staff.inputValue() === '李', 'A subsequent page reads the new shared server default');
    check((await (await page.request.get(firstUrl)).json()).registered_by === 'Ma. José Dela Cruz', 'Changing the default never rewrites earlier registration names');

    const stopped = new Promise((resolve) => server.once('exit', resolve));
    server.kill('SIGTERM');
    await stopped;
    server = spawn(process.env.PHP_BINARY || 'php', ['-S', '127.0.0.1:' + port,
        path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], {
        cwd: path.join(root, 'public'), env: { ...env, APP_URL: base }, stdio: 'ignore',
    });
    for (let attempt = 0; attempt < 60; attempt++) {
        try { if ((await fetch(base + '/login', { signal: AbortSignal.timeout(2000) })).status === 200) break; } catch {}
        if (attempt === 59) throw new Error('Isolated browser server did not restart.');
        await new Promise((resolve) => setTimeout(resolve, 200));
    }
    await page.goto(base + '/registry');
    check(await staff.inputValue() === '李', 'The remembered default survives an application process restart');

    await page.setViewportSize({ width: 390, height: 844 });
    await openAdd();
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Staff field fits the existing narrow drawer layout');
    check(scriptErrors.length === 0, 'No browser script errors');
    console.log(`Registration staff browser checks: ${checks} passed.`);
} finally {
    if (browser) await browser.close();
    if (server) server.kill('SIGTERM');
    fs.rmSync(directory, { recursive: true, force: true });
}

