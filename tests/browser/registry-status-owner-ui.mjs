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
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-registry-owner-'));
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
    const fixture = spawnSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/registry-status-owner-ui.php', directory], {
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
    page.on('pageerror', error => errors.push(error.message));
    const ids = JSON.parse(fs.readFileSync(directory + '/registry.json', 'utf8'));
    const screenshotDir = path.join(root, 'storage/app/ui-review/registry-status-owner');
    fs.mkdirSync(screenshotDir, { recursive: true });
    const waitReady = () => page.waitForFunction(() => document.querySelector('[data-auto-list]')?.dataset.autoState === 'ready');
    const choose = async (selector, value) => {
        const name = await page.locator(selector).getAttribute('name');
        const response = page.waitForResponse(reply => reply.request().headers()['x-auto-filter'] === '1'
            && new URL(reply.url()).searchParams.get(name) === value);
        await page.locator(selector).selectOption(value);
        await response;
        await waitReady();
    };
    const rows = () => page.locator('[data-vehicle-row]');
    await page.goto(base + '/registry');
    check(await page.getByRole('columnheader', { name: 'Registration Status', exact: true }).count() === 0
        && await page.getByRole('columnheader', { name: 'Campus State', exact: true }).count() === 1, 'Old status column is absent; Campus State remains');
    check(await page.locator('thead th').evaluateAll(nodes => nodes.length === 9 && nodes[0].classList.contains('registration-status-cell')
        && nodes[0].getBoundingClientRect().width <= 40 && nodes[1].textContent.trim() === 'Plate'), 'Narrow unlabeled status column immediately precedes Plate');
    check(await rows().evaluateAll(nodes => nodes.every(row => row.cells.length === 9
        && row.cells[0].querySelectorAll('.registration-status-dot').length === 1
        && row.cells[1].querySelector('strong'))), 'Every row has one first-column dot and aligned Plate cell');
    check(await page.locator('.registration-status-button, .registration-status-control').count() === 0, 'Green and power status controls have been removed');
    for (const status of ['active', 'inactive']) {
        await choose('#vehicle_status_filter', status);
        for (const state of ['inside', 'outside']) {
            await choose('#vehicle_state_filter', state);
            const expected = status === 'active' && state === 'outside' ? 3 : 1;
            check(await rows().count() === expected
                && await rows().evaluateAll((nodes, value) => nodes.every(node => node.textContent.toLowerCase().includes(value)), state), 'Independent combined filters: ' + status + '/' + state);
            check(await rows().evaluateAll((nodes, value) => nodes.every(row => row.cells[0].querySelector('.registration-status-dot').classList.contains('is-' + value)
                && row.cells[0].querySelector('.sr-only').textContent.toLowerCase() === value), status), 'Automatic results refresh dot status and screen-reader label: ' + status + '/' + state);
        }
    }
    await page.locator('[data-auto-reset]').click(); await waitReady();
    check(await rows().count() === 6, 'Reset restores both filter defaults');
    const activeRow = page.locator(`[data-vehicle-row][data-vehicle-url$="/${ids['active-INSIDE']}"]`);
    const active = activeRow.locator('.registration-status-dot');
    check(await active.evaluate(node => {
        const probe = document.createElement('span'); probe.style.color = 'var(--purple)'; node.append(probe);
        const same = getComputedStyle(node).backgroundColor === getComputedStyle(probe).color; probe.remove(); return same;
    }), 'Active dot uses the existing app purple');
    check(await active.evaluate(node => node.offsetWidth === 8 && node.offsetHeight === 8 && getComputedStyle(node).borderRadius === '50%'), 'Status light is a small circle');
    check(await activeRow.locator('td').first().evaluate(node => !node.querySelector('button, a, form, input, [tabindex], [role=button]')
        && node.querySelector('.registration-status-dot').getAttribute('aria-hidden') === 'true'), 'Dot is passive with screen-reader text separate from decoration');
    check(await activeRow.getByRole('cell', { name: 'Active', exact: true }).count() === 1
        && await page.locator(`[data-vehicle-url$="/${ids['inactive-INSIDE']}"]`).getByRole('cell', { name: 'Inactive', exact: true }).count() === 1, 'Status cells expose Active and Inactive accessible names');
    const openAction = async (row, label) => {
        await row.locator('.row-menu summary').focus(); await page.keyboard.press('Enter');
        await row.getByRole('menuitem', { name: label + ' vehicle…', exact: true }).click();
    };
    await openAction(activeRow, 'Deactivate');
    const dialog = page.locator('#confirm-dialog');
    await dialog.waitFor({ state: 'visible' });
    check((await dialog.innerText()).includes('Deactivate ACT-1001'), 'Three-dot action opens existing confirmation identifying vehicle');
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    check(await activeRow.locator('.registration-status-dot.is-active').count() === 1, 'Cancel preserves the active dot');
    await openAction(activeRow, 'Deactivate');
    await dialog.getByRole('button', { name: 'Deactivate', exact: true }).click();
    await page.getByText('ACT-1001 was deactivated. Scans of its tag will be flagged.', { exact: true }).waitFor();
    const inactive = activeRow.locator('.registration-status-dot.is-inactive');
    check(await inactive.count() === 1 && (await activeRow.locator('td').first().textContent()).trim() === 'Inactive', 'Deactivation refreshes indicator and screen-reader status');
    check(await inactive.evaluate(node => getComputedStyle(node).backgroundColor === 'rgb(107, 114, 128)'), 'Inactive dot is gray');
    check((await activeRow.textContent()).includes('Inside'), 'Deactivation preserves recorded campus state');
    await openAction(activeRow, 'Activate'); await dialog.getByRole('button', { name: 'Activate', exact: true }).click();
    await page.getByText('ACT-1001 is active again.', { exact: true }).waitFor();
    check(await activeRow.locator('.registration-status-dot.is-active').count() === 1
        && (await activeRow.locator('td').first().textContent()).trim() === 'Active', 'Activation refreshes the active indicator');
    await page.locator('#vehicle_q').fill('NO-MATCH'); await waitReady();
    await page.getByText('No vehicles match these filters.', { exact: true }).waitFor();
    check(await rows().count() === 0 && await page.locator('.registry-vehicles-table table').count() === 0, 'Empty results use the existing standalone empty state without a stale colspan');
    await page.locator('[data-auto-reset]').click(); await waitReady();
    await page.locator('.toast-close').evaluateAll(nodes => nodes.forEach(node => node.click()));
    await page.screenshot({ path: path.join(screenshotDir, 'registry-desktop.png'), fullPage: true });
    const openAdd = async () => {
        await page.locator('[data-drawer-open="add-vehicle-drawer"]').click();
        await page.waitForFunction(() => document.querySelector('#add-vehicle-drawer').classList.contains('is-open'));
    };
    await openAdd();
    const owner = page.locator('#add_owner'); const staff = page.locator('#add_registered_by');
    const ownerError = page.locator('#add_owner_error'); const save = page.locator('[data-vehicle-form="add"] button[type=submit]');
    check(await save.isDisabled() && await ownerError.isHidden(), 'Incomplete new registration is disabled without premature owner error');
    check((await page.locator('label[for=add_owner]').innerText()) === 'Owner Name'
        && (await page.locator('#add_available option').first().innerText()) === 'No RFID assigned', 'Owner and no-RFID labels omit Optional');
    await page.locator('#add_plate_number').fill('OWN-7001'); await page.locator('#add_vehicle_type').selectOption('Car'); await staff.fill('Fixture Registrar');
    for (const name of [' ', 'Owner 1', 'Owner 🚗', '...---', 'Owner & Co', 'ʼ', '\u007fOwner', 'Owner\u200b', 'é'.repeat(101)]) {
        await owner.fill(name);
        check(await ownerError.isVisible() && await save.isDisabled() && await owner.inputValue() === name, 'Invalid owner retained with inline error: ' + name.slice(0, 15));
    }
    for (const name of ['Ma. José Dela Cruz', 'O’Connor', 'Anne-Marie', '李', 'Jose\u0301']) {
        await owner.fill(name); check(await ownerError.isHidden() && await save.isEnabled(), 'Valid personal name clears error: ' + name);
    }
    await owner.evaluate(node => { node.value = 'Pasted 123'; node.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertFromPaste' })); });
    check(await ownerError.isVisible() && await save.isDisabled(), 'Paste validates immediately');
    await owner.fill('Anne-Marie');
    await owner.evaluate(node => { node.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true })); node.value = 'Owner 1'; node.dispatchEvent(new InputEvent('input', { bubbles: true, isComposing: true })); });
    check(await ownerError.isHidden() && await save.isDisabled(), 'IME keeps intermediate input without messages and prevents submit');
    await owner.evaluate(node => node.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true })));
    check(await ownerError.isVisible() && await save.isDisabled(), 'IME completion validates final text');
    await owner.fill('Anne-Marie'); await staff.fill('Staff 1'); await owner.fill('O’Connor');
    check(await save.isDisabled(), 'Correcting owner cannot bypass invalid staff');
    await staff.fill('Fixture Registrar'); await owner.fill('Owner 1'); await staff.fill('Anne-Marie');
    check(await save.isDisabled(), 'Correcting staff cannot bypass invalid owner');
    await owner.fill('O’Connor');
    await page.waitForFunction(() => getComputedStyle(document.querySelector('#add-vehicle-drawer .drawer-panel')).opacity === '1');
    await page.screenshot({ path: path.join(screenshotDir, 'add-valid-owner.png'), fullPage: true });
    await save.click(); await page.getByText('OWN-7001 was saved to the local vehicle registry.', { exact: true }).waitFor();
    const url = await page.locator('[data-vehicle-row]').filter({ hasText: 'OWN-7001' }).getAttribute('data-vehicle-url');
    const details = await (await page.request.get(url)).json();
    check(details.tag === null && details.vehicle_owner_name === 'O’Connor' && details.registered_by === 'Anne-Marie', 'Untagged registration preserves distinct owner/staff and has no tag');
    for (const query of ['register_plate=' + ids.profile, 'register_tag=UNKNOWN-OWNER-UID']) {
        await page.goto(base + '/registry?tab=vehicles&' + query);
        await page.locator('#add-vehicle-drawer').waitFor({ state: 'visible' });
        check(await owner.getAttribute('required') !== null && await save.isDisabled(), 'Guest/RFID deep link shares required owner form: ' + query);
    }
    await page.goto(base + '/registry');
    for (const index of [0, 1]) {
        const row = page.locator(`[data-vehicle-row][data-vehicle-url$="/${ids['legacy-' + index]}"]`);
        await row.locator('.row-menu summary').click(); await row.getByRole('menuitem', { name: 'Edit details', exact: true }).click();
        await page.locator('#edit-vehicle-drawer').waitFor({ state: 'visible' });
        check(await page.locator('#edit_owner').inputValue() === (index ? '3M & Sons, Inc.' : ''), 'Legacy owner remains readable in edit');
        await page.locator('#edit_vehicle_type').selectOption('Bus');
        await page.locator('[data-vehicle-form=edit] button[type=submit]').click();
        await page.getByText('LEG-' + (index + 1) + ' was updated.', { exact: true }).waitFor();
        check((await (await page.request.get(base + '/registry/vehicles/' + ids['legacy-' + index])).json()).vehicle_type === 'Bus', 'Legacy owner permits unrelated edit');
    }
    await page.setViewportSize({ width: 768, height: 900 }); await page.goto(base + '/registry');
    check(await page.locator('#vehicle_status_filter').evaluate(node => node.clientWidth >= 140), 'Tablet filters retain readable widths');
    await page.screenshot({ path: path.join(screenshotDir, 'registry-tablet.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 }); await page.goto(base + '/registry');
    check(await page.locator('#vehicle_status_filter').evaluate(node => node.clientWidth >= 250), 'Mobile registration filter displays its full selected value');
    const scroll = page.locator('.registry-vehicles-table .table-responsive');
    check(await scroll.evaluate(node => node.scrollWidth > node.clientWidth && ['auto', 'scroll'].includes(getComputedStyle(node).overflowX)), 'Narrow table scrolls to preserve readable columns');
    check(await rows().evaluateAll(nodes => nodes.every(row => row.cells[0].getBoundingClientRect().right <= row.cells[1].getBoundingClientRect().left + 1)), 'Narrow layout preserves dot-before-plate alignment');
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Narrow table does not overflow the page');
    await page.screenshot({ path: path.join(screenshotDir, 'registry-mobile.png'), fullPage: true });
    check(errors.length === 0, 'No browser errors');
    console.log(`Registry status/owner browser checks: ${checks} passed.`);
} finally {
    if (browser) await browser.close();
    if (server) server.kill('SIGTERM');
    fs.rmSync(directory, { recursive: true, force: true });
}
