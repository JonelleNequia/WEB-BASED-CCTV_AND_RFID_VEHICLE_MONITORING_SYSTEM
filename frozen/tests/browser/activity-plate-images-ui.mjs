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
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-activity-plate-images-'));
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
    const fixture = spawnSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/activity-plate-images-ui.php', directory], {
        cwd: root, env, encoding: 'utf8', timeout: 30000,
    });
    assert.equal(fixture.status, 0, fixture.stderr || fixture.stdout);
    assert.ok(fs.existsSync(directory + '/activity-plate-images.json'), fixture.stderr || fixture.stdout);
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

    const data = JSON.parse(fs.readFileSync(directory + '/activity-plate-images.json', 'utf8'));
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    const artifacts = path.join(root, 'storage/app/ui-review/activity-plate-images');
    fs.mkdirSync(artifacts, { recursive: true });
    const ready = () => page.waitForFunction(() => document.querySelector('[data-auto-list]')?.dataset.autoState === 'ready');
    const logs = () => page.locator('#event-log-modal-data').evaluate(node => JSON.parse(node.textContent));
    const imageURLs = row => row.locator('img').evaluateAll(images => images.map(img => new URL(img.src).pathname));
    await page.goto(base + '/logs?plate_text=IMG');
    await ready();
    check(await page.locator('[data-event-log-list] tr').count() === 8, 'Both image combinations appear for canonical and visitor occurrences');
    for (const [plate, expected] of Object.entries(data.expected)) {
        const row = page.locator('[data-event-log-list] tr').filter({ hasText: plate });
        const expectedURLs = [expected.image_url, expected.plate_image_url].filter(Boolean);
        check(JSON.stringify(await imageURLs(row)) === JSON.stringify(expectedURLs), plate + ' keeps its own snapshot and crop in distinct cells');
        check(await row.locator('td:nth-child(3) .plate-cell').count() === 1, plate + ' places the crop or placeholder beside Plate');
        if (expected.plate_image_url) {
            const img = row.locator('td:nth-child(3) img');
            await img.scrollIntoViewIfNeeded();
            await img.evaluate(img => img.decode());
            check(await img.evaluate(img => img.naturalWidth > 0 && getComputedStyle(img).objectFit === 'contain'), plate + ' crop loads without cutting off characters');
        } else {
            check(await row.locator('td:nth-child(3)').innerText().then(text => text.includes('No plate image')), plate + ' explicitly identifies a missing crop');
        }
    }
    await page.screenshot({ path: path.join(artifacts, 'desktop.png'), fullPage: true });
    const crop = page.getByRole('button', { name: 'Enlarge plate image of IMG1000', exact: true });
    await crop.focus(); await page.keyboard.press('Enter');
    await page.locator('#snapshot-viewer').waitFor({ state: 'visible' });
    await page.waitForFunction(() => document.querySelector('[data-snapshot-full]').naturalWidth > 0);
    check(await page.locator('#snapshot-viewer-title').innerText() === 'Plate image'
        && new URL(await page.locator('[data-snapshot-full]').getAttribute('src'), base).pathname === data.expected.IMG1000.plate_image_url,
    'Keyboard activation enlarges the selected crop with the correct title');
    await page.screenshot({ path: path.join(artifacts, 'crop-viewer.png') });
    await page.locator('#snapshot-viewer button[data-drawer-close]').click();
    await page.getByRole('button', { name: 'Enlarge snapshot of IMG1000', exact: true }).click();
    await page.locator('#snapshot-viewer').waitFor({ state: 'visible' });
    check(await page.locator('#snapshot-viewer-title').innerText() === 'Snapshot'
        && new URL(await page.locator('[data-snapshot-full]').getAttribute('src'), base).pathname === data.expected.IMG1000.image_url,
    'Vehicle snapshot opens independently and resets the image viewer title');
    await page.locator('#snapshot-viewer button[data-drawer-close]').click();
    await page.locator('[name=plate_text]').fill('IMG1002'); await ready();
    check((await logs()).length === 1 && (await logs())[0].plate_image_url === data.expected.IMG1002.plate_image_url,
        'Automatic filtering retains the exact crop on a crop-only occurrence');
    const refreshed = page.waitForResponse(response => response.request().headers()['x-auto-filter'] === '1');
    await refreshed; await ready();
    check(await page.locator('[data-event-log-list] td:nth-child(3) img').count() === 1, 'Polling preserves the Plate crop');
    await page.locator('[name=plate_text]').fill('IMG1003'); await ready();
    check(await page.locator('[data-event-log-list] img').count() === 0
        && (await page.locator('[data-event-log-list]').innerText()).includes('No plate image'), 'Refresh does not leak a previous row image into a missing-image row');
    await page.goBack(); await ready();
    check((await logs())[0].plate_image_url === data.expected.IMG1002.plate_image_url, 'Back restores matching occurrence media');
    await page.goto(base + '/logs?plate_text=395ISX'); await ready();
    const corrected = await logs();
    check(corrected.length === 2 && new Set(corrected.map(row => row.plate_image_url)).size === 2,
        'Corrected successive movements keep two events with distinct crops');
    for (const log of corrected) {
        check(log.plate_image_url === data.corrected[log.id].plate_image_url && log.image_url === data.corrected[log.id].image_url
            && log.movement_label === data.corrected[log.id].direction, 'Correction retry retains event ' + log.id + ' media and direction');
    }
    for (const width of [390, 320]) {
        await page.setViewportSize({ width, height: 844 });
        await page.goto(base + '/logs?plate_text=IMG'); await ready();
        check(await page.locator('[data-event-log-list] .plate-cell').count() === 8, width + 'px table retains every Plate cell');
        const target = page.locator('[data-event-log-list] td:nth-child(3) .thumb-button').first();
        await target.scrollIntoViewIfNeeded();
        check(await target.isVisible() && await target.evaluate(node => node.getBoundingClientRect().width > 0), width + 'px layout retains accessible crop controls');
        await page.locator('[data-event-log-list] img').evaluateAll(images => {
            images.forEach(img => { img.loading = 'eager'; });
            return Promise.all(images.map(img => img.decode()));
        });
        await page.locator('[data-event-log-list] tr').first().locator('td:nth-child(3)').evaluate(cell => {
            const scroller = cell.closest('.table-responsive');
            scroller.scrollLeft += cell.getBoundingClientRect().left - scroller.getBoundingClientRect().left - 12;
        });
        await page.screenshot({ path: path.join(artifacts, 'narrow-' + width + '.png'), fullPage: true });
    }
    await page.setViewportSize({ width: 1366, height: 768 });
    const print = await context.newPage();
    await print.goto(base + '/logs/print?plate_text=IMG&snapshots=1&autoprint=0');
    await print.waitForLoadState('networkidle');
    check(await print.locator('.records-table tbody tr').count() === 8 && await print.locator('img.report-plate-image').count() === 4,
        'Print contains the same eight occurrences and four real crops');
    for (const [plate, expected] of Object.entries(data.expected)) {
        const row = print.locator('.records-table tbody tr').filter({ hasText: plate });
        check(JSON.stringify(await imageURLs(row)) === JSON.stringify([expected.image_url, expected.plate_image_url].filter(Boolean)), 'Print ' + plate + ' keeps separate snapshot/crop provenance');
    }
    check(await print.locator('.no-plate-image').count() === 4 && await print.locator('.no-snapshot').count() === 4,
        'Print has explicit independent missing-image placeholders');
    await print.locator('img').evaluateAll(images => Promise.all(images.map(img => img.decode())));
    await print.screenshot({ path: path.join(artifacts, 'matrix-print.png'), fullPage: true });
    await print.pdf({ path: path.join(artifacts, 'matrix-images.pdf'), preferCSSPageSize: true, printBackground: true });
    const noImages = print.waitForURL(url => !url.searchParams.has('snapshots'));
    await print.locator('[data-report-snapshots]').uncheck(); await noImages;
    check(await print.locator('img').count() === 0 && await print.locator('.records-table tbody tr').count() === 8, 'Include images off removes both image types, retaining the same records');
    await print.goto(base + '/logs/print?plate_text=PRT&date_from=2026-11-03&time_from=08:00&time_to=09:00&snapshots=1&autoprint=0');
    await print.locator('img').evaluateAll(images => Promise.all(images.map(img => img.decode())));
    check(await print.locator('.records-table tbody tr').count() === 36 && await print.locator('img').count() === 72, 'Multi-page report includes every filtered snapshot and crop');
    check((await print.locator('[data-report-filters]').innerText()).includes('8:00 AM'), 'Existing Manila daily time filter remains applied to image reports');
    await print.pdf({ path: path.join(artifacts, 'multipage-images.pdf'), preferCSSPageSize: true, printBackground: true });
    const off = print.waitForURL(url => !url.searchParams.has('snapshots'));
    await print.locator('[data-report-snapshots]').uncheck(); await off;
    await print.pdf({ path: path.join(artifacts, 'multipage-no-images.pdf'), preferCSSPageSize: true, printBackground: true });
    // Resolve the export route from the actual list, preserving the existing route conventions.
    await page.goto(base + '/logs?plate_text=PRT&date_from=2026-11-03&time_from=08:00&time_to=09:00'); await ready();
    const csvURL = await page.locator('[data-auto-link=csv]').getAttribute('href');
    const exportResponse = await page.request.get(csvURL);
    const exportText = await exportResponse.text();
    check(exportResponse.ok() && exportText.trim().split('\n').length === 37 && !exportText.includes('/evidence/'), 'CSV remains image-free with all matching records');
    const reportPlates = await print.locator('.col-plate strong').allTextContents();
    check(JSON.stringify(reportPlates.sort()) === JSON.stringify(exportText.trim().split('\n').slice(1).map(line => line.split(',')[3]).sort()), 'Print and CSV record sets match across pages');
    await print.addInitScript(() => { window.printCalls = 0; window.print = () => { window.printCalls++; }; });
    await print.reload();
    await print.locator('[data-report-print]').click(); await print.waitForFunction(() => window.printCalls === 1);
    await print.locator('[data-report-pdf]').click(); await print.waitForFunction(() => window.printCalls === 2);
    check(await print.locator('[data-report-pdf-hint]').isVisible(), 'Print and Save as PDF retain the browser workflow');
    // Exercise the retained JSON renderer too; the normal page uses automatic HTML refresh.
    const legacy = await context.newPage();
    await legacy.addInitScript(() => {
        window.liveRowsReplaced = false;
        new MutationObserver(changes => {
            if (changes.some(change => change.target.matches?.('[data-event-log-list]') && change.removedNodes.length)) window.liveRowsReplaced = true;
        }).observe(document, { subtree: true, childList: true });
    });
    await legacy.route('**/logs?plate_text=IMG', async route => {
        if (route.request().resourceType() !== 'document') return route.continue();
        const response = await route.fetch();
        await route.fulfill({ response, body: (await response.text()).replace('data-auto-list="logs-events"', 'data-legacy-list="logs-events"') });
    });
    await legacy.goto(base + '/logs?plate_text=IMG');
    await legacy.waitForFunction(() => window.liveRowsReplaced);
    check(await legacy.locator('[data-event-log-list] .activity-plate-image').count() === 4, 'Retained JSON live renderer displays all four occurrence crops');
    check((await legacy.locator('[data-event-log-list]').innerText()).match(/No plate image/g)?.length === 4, 'JSON live renderer retains every missing-crop placeholder');
    await legacy.getByRole('button', { name: 'Enlarge plate image of IMG1000', exact: true }).click();
    await legacy.locator('#snapshot-viewer').waitFor({ state: 'visible' });
    check(await legacy.locator('#snapshot-viewer-title').innerText() === 'Plate image', 'JSON-generated crop uses the same delegated image viewer');
    await legacy.close();
    check(errors.length === 0, 'No Activity Logs browser script errors');
    console.log(`Activity plate image browser checks: ${checks} passed.`);
} finally {
    if (browser) await browser.close();
    if (server) server.kill('SIGTERM');
    fs.rmSync(directory, { recursive: true, force: true });
}

