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
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-list-reports-'));
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
    const fixture = spawnSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/list-reports-ui.php', directory], {
        cwd: root, env, encoding: 'utf8', timeout: 30000,
    });
    assert.equal(fixture.status, 0, fixture.stderr || fixture.stdout);
    assert.ok(fs.existsSync(directory + '/list-reports.json'), fixture.stderr || fixture.stdout);
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

    const data = JSON.parse(fs.readFileSync(directory + '/list-reports.json', 'utf8'));
    const artifacts = path.join(root, 'storage/app/ui-review/list-reports'); fs.mkdirSync(artifacts, { recursive: true });
    const errors = []; context.on('page', p => p.on('pageerror', error => errors.push(error.message))); page.on('pageerror', error => errors.push(error.message));
    const ready = async () => {
        try { await page.waitForFunction(() => document.querySelector('[data-auto-list]')?.dataset.autoState === 'ready'); }
        catch (error) { throw new Error(error.message + '\n' + JSON.stringify({ url: page.url(), errors, status: await page.locator('[data-auto-status]').innerText() })); }
    };
    const select = async (id, value) => { await page.locator(id).selectOption(value); await ready(); };
    const printLink = () => page.locator('[data-auto-link=list-print]');
    const csvLink = () => page.locator('[data-auto-link=list-csv]');
    const reportIds = p => p.locator('.records-table tbody tr').evaluateAll(rows => rows.map(row => Number(row.dataset.recordId)));
    const same = (actual, expected) => JSON.stringify(actual) === JSON.stringify(expected);
    const openPreview = async () => {
        const popup = page.waitForEvent('popup'); await printLink().click(); const preview = await popup;
        await preview.waitForLoadState('networkidle'); return preview;
    };
    const checkCSV = async (p, href) => {
        const response = await page.request.get(href); check(response.status() === 200, 'CSV direct request succeeds');
        const buffer = await response.body(); check(buffer.subarray(0,3).equals(Buffer.from([239,187,191])), 'CSV carries a UTF-8 BOM');
        const lines = buffer.toString('utf8').slice(1).trim().split('\r\n');
        const ids = lines.slice(1).map(row => Number(row.split(',')[0]));
        check(same(ids, await reportIds(p)), 'CSV and Print have identical ordered record IDs');
        check(lines.every(row => !row.includes('/evidence/') && !row.includes('<img')), 'CSV contains record data without image URLs or markup');
    };

    await page.goto(base + '/registry');
    check(await printLink().isVisible() && await csvLink().isVisible(), 'Vehicles has Print and CSV beside its controls');
    const vehicles = await openPreview();
    check(same(await reportIds(vehicles), data.vehicles), 'Vehicle report includes all 65 records in list order');
    check((await vehicles.locator('.records-table').innerText()).includes('No RFID assigned'), 'Vehicle report retains untagged registrations');
    check(await vehicles.locator('[data-report-snapshots]').count() === 0, 'Vehicle inventory does not borrow an unrelated movement image');
    await checkCSV(vehicles, await csvLink().getAttribute('href'));
    await vehicles.screenshot({ path: path.join(artifacts, 'vehicles-preview.png'), fullPage: true });
    await vehicles.pdf({ path: path.join(artifacts, 'vehicles.pdf'), preferCSSPageSize: true, printBackground: true });
    await vehicles.close();
    await select('#vehicle_status_filter', 'inactive'); await select('#vehicle_state_filter', 'outside');
    const filteredIds = await page.locator('[data-vehicle-row]').evaluateAll(rows => rows.map(row => Number(row.dataset.vehicleUrl.split('/').at(-1))));
    const filtered = await openPreview();
    check(same(await reportIds(filtered), filteredIds), 'Independent Registration Status and Campus State are applied to Print');
    check((await filtered.locator('[data-report-filters]').innerText()).includes('Registration Status: Inactive')
        && (await filtered.locator('[data-report-filters]').innerText()).includes('Campus State: Outside'), 'Vehicle report describes both applied filters');
    await checkCSV(filtered, await csvLink().getAttribute('href')); await filtered.close();
    const validRows = await page.locator('[data-auto-update=results]').innerHTML(); const validUrl = page.url();
    await page.locator('#vehicle_q').fill('x'.repeat(256));
    check(await printLink().getAttribute('href') === null && await csvLink().getAttribute('href') === null, 'Pending search immediately removes stale export targets');
    await page.waitForFunction(() => document.querySelector('[data-auto-list]').dataset.autoState === 'error');
    check(await page.locator('[data-auto-update=results]').innerHTML() === validRows && page.url() === validUrl, 'Invalid filter retains the last valid results and URL');
    check(await printLink().getAttribute('href') === null && await csvLink().getAttribute('href') === null, 'Invalid filter keeps Print and CSV disabled');
    check(await csvLink().evaluate(link => link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true }))) === false, 'Modified export clicks cannot bypass stale-criteria protection');
    await page.locator('#vehicle_q').fill('REP'); await ready();
    check(await printLink().getAttribute('href') !== null && new URL(await csvLink().getAttribute('href')).searchParams.get('q') === 'REP', 'Correction restores export targets for the applied search');
    await page.locator('[data-auto-reset]').click(); await ready();

    await page.goto(base + '/registry?tab=tags');
    const tags = await openPreview();
    const tagListIds = await page.locator('[data-auto-update=results] tbody tr').evaluateAll(rows => rows.map(row => Number(row.querySelector('form[action*="/registry/tags/"]')?.action.match(/tags\/(\d+)/)?.[1])));
    check(same(await reportIds(tags), tagListIds) && tagListIds.length === 65 && await tags.locator('[data-report-snapshots]').count() === 0, 'Tag report contains the complete inventory in list order without an image option');
    check((await tags.locator('[data-report-count]').innerText()) === '65', 'Tag count uses the full report');
    await checkCSV(tags, await csvLink().getAttribute('href'));
    await tags.screenshot({ path: path.join(artifacts, 'tags-preview.png'), fullPage: true });
    await tags.pdf({ path: path.join(artifacts, 'tags.pdf'), preferCSSPageSize: true, printBackground: true }); await tags.close();
    await select('#tag_status_filter', 'available');
    const available = await openPreview();
    check((await reportIds(available)).length === 33 && new URL(await csvLink().getAttribute('href')).searchParams.get('status') === 'available', 'Tag status changes synchronize both exports');
    await checkCSV(available, await csvLink().getAttribute('href')); await available.close();

    await page.goto(base + '/visitors?record_filter=manual');
    const guestListIds = [];
    for (let index = 1; index <= 4; index++) {
        guestListIds.push(...await page.locator('[data-auto-update=results] tbody tr').evaluateAll(rows => rows.map(row => Number(row.id.replace('visitor-record-', '')))));
        if (index < 4) { await page.locator('.pagination-links').getByRole('link', { name: 'Next', exact: true }).click(); await ready(); }
    }
    const guests = await openPreview();
    check(same(await reportIds(guests), guestListIds) && guestListIds.length === 65, 'Guest Print includes all matching IDs across four list pages');
    check(await guests.locator('img').count() === 0 && await guests.locator('[data-report-snapshots]').count() === 1, 'Guest images are optional and off by default');
    await checkCSV(guests, await csvLink().getAttribute('href'));
    check((await guests.locator('.report-back').getAttribute('href')).includes('record_filter=manual'), 'Return link keeps the applied quick criterion');
    await guests.pdf({ path: path.join(artifacts, 'guests-no-images.pdf'), preferCSSPageSize: true, printBackground: true });
    const imagesNavigation = guests.waitForURL(url => url.searchParams.get('snapshots') === '1');
    await guests.locator('[data-report-snapshots]').check(); await imagesNavigation; await guests.waitForLoadState('networkidle');
    check((await reportIds(guests)).length === 65 && new URL(guests.url()).searchParams.get('record_filter') === 'manual', 'Include images retains filters and every matching record');
    const imageCount = await guests.locator('img[data-report-image]').count();
    const placeholderCount = await guests.locator('.no-snapshot:visible').count();
    check(imageCount === 64 && placeholderCount === 1, 'Each image belongs to its occurrence, with a missing-image placeholder: ' + JSON.stringify({ imageCount, placeholderCount,
        images: await guests.locator('img[data-report-image]').evaluateAll(images => images.slice(0, 2).map(image => ({ src: image.src, complete: image.complete, width: image.naturalWidth }))) }));
    check(await guests.locator('img[data-report-image]').evaluateAll(images => images.every(image => image.complete && image.naturalWidth > 0)), 'Authenticated occurrence images load in preview');
    check((await guests.locator('figcaption').allTextContents()).includes('Plate image'), 'Plate crop is clearly labelled when no snapshot is attached');
    await guests.screenshot({ path: path.join(artifacts, 'guests-images-preview.png'), fullPage: true });
    await guests.pdf({ path: path.join(artifacts, 'guests-images.pdf'), preferCSSPageSize: true, printBackground: true });
    await guests.evaluate(() => { window.printCalls = 0; window.print = () => { window.printCalls++; }; });
    await guests.locator('[data-report-print]').click(); await guests.waitForFunction(() => window.printCalls === 1);
    await guests.locator('[data-report-pdf]').click(); await guests.waitForFunction(() => window.printCalls === 2);
    check(await guests.locator('[data-report-pdf-hint]').isVisible(), 'Print and Save as PDF use the existing browser workflow with destination guidance');
    await guests.emulateMedia({ media: 'print' });
    check(await guests.locator('.report-toolbar').isHidden(), 'Preview controls are hidden on printed pages');
    check(await guests.locator('.records-table thead').evaluate(node => getComputedStyle(node).display) === 'table-header-group', 'Print table uses repeated headers');
    await guests.emulateMedia({ media: 'screen' });
    await guests.route('**/evidence/occurrences/**', route => route.fulfill({ status: 404, body: 'Missing fixture image' }));
    // Block the concrete URL too, independent of route naming conventions.
    const imageUrl = await guests.locator('img').first().getAttribute('src');
    await guests.route('**' + imageUrl, route => route.fulfill({ status: 404, body: 'Missing fixture image' }));
    await guests.reload(); await guests.waitForLoadState('networkidle');
    check(await guests.locator('.no-snapshot:visible').count() > 1, 'Image load failure also produces a readable placeholder');
    await guests.close();

    await page.locator('[data-auto-update=record-filters]').getByRole('link', { name: 'Unregistered Guests', exact: true }).click(); await ready();
    check(!new URL(page.url()).searchParams.has('page') && new URL(await csvLink().getAttribute('href')).searchParams.get('record_filter') === 'unregistered', 'Quick filter returns to page one and synchronizes report targets');
    await page.goBack(); await ready();
    check(new URL(await printLink().getAttribute('href')).searchParams.get('record_filter') === 'manual', 'Back restores export criteria with the selected quick filter');
    await page.goForward(); await ready();
    check(new URL(await printLink().getAttribute('href')).searchParams.get('record_filter') === 'unregistered', 'Forward restores newer export criteria');
    await page.evaluate(() => {
        window.savedFetch = window.fetch;
        window.fetch = async (...args) => {
            const response = await window.savedFetch(...args);
            if (new URL(args[0], location.href).searchParams.get('record_filter') === 'registered') await new Promise(resolve => setTimeout(resolve, 650));
            return response;
        };
    });
    await page.locator('[data-auto-update=record-filters]').getByRole('link', { name: 'Registered', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('[data-auto-list]').dataset.autoState === 'loading');
    await page.locator('[data-auto-update=record-filters]').getByRole('link', { name: 'Manual entries', exact: true }).click(); await ready(); await page.waitForTimeout(800);
    check(new URL(await printLink().getAttribute('href')).searchParams.get('record_filter') === 'manual', 'Delayed older response cannot restore stale export links');
    await page.evaluate(() => { window.fetch = window.savedFetch; });
    await select('#visitor_gate', 'gate-2');
    const empty = await openPreview(); check(await empty.locator('.report-empty').isVisible(), 'Empty filtered Print has a clear state');
    await checkCSV(empty, await csvLink().getAttribute('href')); await empty.close();
    for (const invalid of ['/registry/print?status=invalid', '/registry/export/csv?tab=tags&status=invalid', '/visitors/print?record_filter=invalid', '/visitors/export/csv?gate=missing']) {
        const response = await page.request.get(base + invalid); check(response.status() === 422, 'Direct invalid report criteria are rejected');
    }
    await page.goto(base + '/visitors?record_filter=manual&gate=' + data.archived_gate);
    const historical = await openPreview(); check((await reportIds(historical)).length === 1, 'Archived-gate history remains available in reports'); await historical.close();
    await page.setViewportSize({ width: 390, height: 844 });
    for (const [tab, suffix] of [['vehicles', ''], ['tags', '?tab=tags']]) {
        await page.goto(base + '/registry' + suffix);
        const linksFit = await page.locator('[data-auto-link]').evaluateAll(links => links.every(link => {
            const rect = link.getBoundingClientRect(); return rect.left >= 0 && rect.right <= innerWidth;
        }));
        check(await printLink().isVisible() && await csvLink().isVisible() && linksFit, tab + ' export actions fit the narrow viewport');
        await page.screenshot({ path: path.join(artifacts, tab + '-mobile.png'), fullPage: true });
    }
    await page.goto(base + '/visitors?record_filter=manual&gate=' + data.archived_gate);
    check(await printLink().isVisible() && await csvLink().isVisible(), 'Export actions remain available at narrow width');
    const bounds = await page.locator('[data-auto-link]').evaluateAll(links => links.map(link => { const rect = link.getBoundingClientRect(); return rect.left >= 0 && rect.right <= innerWidth; }));
    check(bounds.every(Boolean), 'Export actions fit the narrow viewport');
    await page.screenshot({ path: path.join(artifacts, 'guests-mobile.png'), fullPage: true });
    check(errors.length === 0, 'No browser script errors');
    console.log(`List reports browser checks: ${checks} passed.`);
} finally {
    if (browser) await browser.close();
    if (server) server.kill('SIGTERM');
    fs.rmSync(directory, { recursive: true, force: true });
}

