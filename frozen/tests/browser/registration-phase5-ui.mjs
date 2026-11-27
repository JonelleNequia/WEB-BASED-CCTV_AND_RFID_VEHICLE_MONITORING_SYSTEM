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
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-phase5-'));
fs.chmodSync(directory, 0o700);
const env = {
    ...process.env, APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_URL: '',
    DB_DATABASE: directory + '/ui.sqlite', SESSION_DRIVER: 'database', CACHE_STORE: 'array',
    LOG_CHANNEL: 'testing', CAMERA_FILES_PATH: directory + '/camera', DEVICE_FILES_PATH: directory + '/devices',
    ARGON_MEMORY: '1024', ARGON_TIME: '1', DETECTOR_API_KEY: 'phase5-browser-key', PYTHONDONTWRITEBYTECODE: '1',
    APP_KEY: 'base64:' + randomBytes(32).toString('base64'),
    APP_CONFIG_CACHE: directory + '/config.php', APP_ROUTES_CACHE: directory + '/routes.php',
};
let server;
let browser;
let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; };

try {
    const fixture = spawnSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/registration-phase5-ui.php', directory], {
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

    const artifacts = path.join(root, 'storage/app/ui-review/registration-phase5');
    fs.mkdirSync(artifacts, { recursive: true });
    if (process.env.PHASE5_BEFORE === '1') {
        await page.goto(base + '/registry?q=ABC');
        const trigger = page.locator('[data-vehicle-row] details summary').last();
        await trigger.scrollIntoViewIfNeeded();
        const measure = () => page.locator('.table-responsive').evaluate((node) => ({ height: node.getBoundingClientRect().height, scroll: node.scrollHeight, client: node.clientHeight }));
        const before = await measure();
        await trigger.click();
        await page.screenshot({ path: path.join(artifacts, 'before-registry-menu.png') });
        console.log('Before menu geometry:', JSON.stringify({ before, after: await measure() }));
    } else {
        const records = JSON.parse(fs.readFileSync(directory + '/phase5.json', 'utf8'));
        const ready = () => page.waitForFunction(() => document.querySelector('[data-auto-list]')?.dataset.autoState === 'ready');
        const search = async (selector, value) => { await page.locator(selector).fill(value); await ready(); };
        const menu = () => page.locator('[data-row-menu-panel][aria-hidden="false"]');
        const triggers = () => page.locator('[data-vehicle-row] details summary');
        const closed = async () => { await page.waitForFunction(() => !document.querySelector('[data-row-menu-panel][aria-hidden="false"]')); };
        const measure = () => page.locator('.table-responsive').first().evaluate((node) => ({
            height: node.getBoundingClientRect().height, width: node.getBoundingClientRect().width,
            scrollHeight: node.scrollHeight, scrollWidth: node.scrollWidth, clientHeight: node.clientHeight, clientWidth: node.clientWidth,
            rows: [...node.querySelectorAll('tr')].map((row) => row.getBoundingClientRect().height),
        }));
        async function openChecked(trigger, label) {
            await trigger.evaluate((node) => node.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'instant' }));
            await page.waitForTimeout(100);
            const before = await measure();
            await trigger.click();
            await menu().waitFor({ state: 'visible' });
            check(JSON.stringify(await measure()) === JSON.stringify(before), label + ': table geometry and scroll area unchanged');
            check(await menu().count() === 1 && await trigger.getAttribute('aria-expanded') === 'true', label + ': one accessible open menu');
            const bounds = await menu().evaluate((node) => {
                const r = node.getBoundingClientRect();
                const hit = document.elementFromPoint(r.left + r.width / 2, r.top + Math.min(r.height / 2, 12));
                return { left: r.left, top: r.top, right: r.right, bottom: r.bottom, width: document.documentElement.clientWidth, height: innerHeight,
                    hit: hit?.outerHTML.slice(0, 180), inside: node.contains(hit), expanded: node.getAttribute('aria-hidden') };
            });
            check(bounds.left >= 7 && bounds.top >= 7 && bounds.right <= bounds.width - 7 && bounds.bottom <= bounds.height - 7 && bounds.inside,
                label + ': menu is in viewport and escapes table clipping: ' + JSON.stringify(bounds));
        }
        async function options(selector) { await page.locator(selector).waitFor({ state: 'attached' }); return page.locator(selector + ' option').evaluateAll((nodes) => nodes.map((node) => node.value)); }

        for (const [url, field] of [['/logs', 'gate'], ['/visitors', 'gate'], ['/logs?tab=scans', 'scan_location']]) {
            await page.goto(base + url);
            const values = await options('[data-auto-filter] select[name="' + field + '"]');
            check(values.includes(records.inactive) && !values.includes(records.archived), url + ': inactive offered, archived excluded');
            check(await page.getByRole('link', { name: 'Guests', exact: true }).count() === 1, 'Guest sidebar label with existing /visitors route');
            await page.goto(base + url + (url.includes('?') ? '&' : '?') + field + '=' + records.archived);
            check((await page.locator('[data-archived-gate-context]').textContent()).includes('Archived fixture gate (archived)'), 'Bookmark explains archived criterion');
            check(await page.locator('input[type=hidden][name="' + field + '"]').inputValue() === records.archived
                && await page.locator('[data-auto-filter] select[name="' + field + '"]').count() === 0, 'Historical criterion is read-only, never reinserted as option');
            check((await page.locator('[data-auto-update="results"]').innerText()).includes('HST'), 'Archived history remains visible');
            await page.getByRole('link', { name: 'Change gate', exact: true }).click();
            await ready();
            check((await options('[data-auto-filter] select[name="' + field + '"]')).includes(records.inactive), 'Change gate restores retained options');
        }
        await page.goto(base + '/logs?gate=' + records.archived);
        await search('#plate_text', 'HST-9001');
        check(new URL(page.url()).searchParams.get('gate') === records.archived, 'Automatic filtering retains historical criterion');
        const csv = await page.request.get(await page.locator('[data-auto-link="csv"]').getAttribute('href'));
        check((await csv.text()).includes('Archived fixture gate'), 'Filtered CSV retains archived gate name');
        const printURL = new URL(await page.locator('[data-auto-link="report-current"]').getAttribute('href'));
        printURL.searchParams.set('autoprint', '0');
        const print = await page.request.get(printURL.href);
        check((await print.text()).includes('Archived fixture gate'), 'Historical print remains accessible');
        await page.getByRole('link', { name: 'Change gate', exact: true }).click();
        check(new URL(page.url()).searchParams.get('plate_text') === 'HST-9001', 'Change gate preserves other applied criteria');
        await page.getByRole('link', { name: 'Reset', exact: true }).click();
        await ready();
        check(!new URL(page.url()).searchParams.has('gate') && await page.locator('[data-archived-gate-context]').count() === 0, 'Reset clears archived criterion');
        await page.goto(base + '/visitors');
        await page.getByRole('button', { name: 'Add guest manually', exact: true }).click();
        const operational = await options('#add-visitor-drawer select[name="gate"]');
        check(!operational.includes(records.archived) && !operational.includes(records.inactive), 'New-record selector preserves active-only eligibility');
        check(await page.getByRole('button', { name: 'Save guest', exact: true }).isVisible(), 'Guest form wording updated');
        await page.keyboard.press('Escape');
        await page.goto(base + '/settings?tab=gates');
        check(await page.locator('[data-gate-card="' + records.archived + '"]').count() === 0
            && await page.locator('[data-gate-card="' + records.inactive + '"]').count() === 1, 'Settings retains inactive configuration, hides archived gate');
        console.log('Gate filters, old bookmarks, Reset, exports, selectors and Guest labels passed.');

        // Short-list geometry, native top layer and viewport/zoom-equivalent layouts.
        for (const [name, width, height] of [['1366x768',1366,768], ['1920x1080',1920,1080], ['narrow',390,844], ['125-percent-layout',1093,614]]) {
            await page.setViewportSize({ width, height });
            await page.goto(base + '/registry?q=ABC');
            await openChecked(triggers().first(), name + ' first short row');
            await page.keyboard.press('Escape');
            check(await triggers().first().evaluate((node) => node === document.activeElement), name + ': Escape returns focus');
            await openChecked(triggers().last(), name + ' last short row');
            check(await menu().evaluate((node) => node.matches(':popover-open')), 'Native popover uses browser top layer');
            await page.screenshot({ path: path.join(artifacts, 'after-menu-' + name + '.png') });
            await page.keyboard.press('Escape');
        }
        await page.setViewportSize({ width: 1366, height: 768 });
        await page.goto(base + '/registry?q=ABC');
        const last = triggers().last();
        await last.focus();
        await page.keyboard.press('Enter');
        await menu().waitFor({ state: 'visible' });
        check(await menu().locator('[role=menuitem]').first().evaluate((node) => node === document.activeElement), 'Enter opens and focuses first action');
        await page.keyboard.press('End');
        check(await menu().locator('[role=menuitem]').last().evaluate((node) => node === document.activeElement), 'End selects final action');
        await page.keyboard.press('Home');
        await page.keyboard.press('ArrowDown');
        check(await menu().locator('[role=menuitem]').nth(1).evaluate((node) => node === document.activeElement), 'Arrow navigation preserves action order');
        await page.keyboard.press('Tab');
        await closed();
        await last.focus();
        await page.keyboard.press('Space');
        await menu().waitFor({ state: 'visible' });
        await page.keyboard.press('Escape');
        check(await last.evaluate((node) => node === document.activeElement), 'Space opens; Escape restores summary focus');
        await openChecked(last, 'Outside click');
        await page.locator('h1').click();
        await closed();
        await openChecked(last, 'First of two');
        // The taller status-control rows may put this trigger behind the
        // preceding popup; keyboard activation still verifies menu switching.
        await triggers().first().focus();
        await page.keyboard.press('Enter');
        check(await menu().count() === 1 && await last.getAttribute('aria-expanded') === 'false', 'Opening another row dismisses first');
        await page.keyboard.press('Escape');
        await openChecked(last, 'Edit target');
        const targetURL = await menu().locator('[data-vehicle-action=edit]').getAttribute('data-vehicle-url');
        await menu().getByRole('menuitem', { name: 'Edit details', exact: true }).click();
        await page.locator('#edit-vehicle-drawer.is-open').waitFor({ state: 'visible' });
        check((await page.locator('[data-vehicle-form=edit]').getAttribute('action')).endsWith('/' + targetURL.split('/').at(-1)), 'Original edit handler loads selected row');
        await page.keyboard.press('Escape');
        check(await last.evaluate((node) => node === document.activeElement), 'Edit dialog returns focus to summary');
        await openChecked(last, 'Confirmation target');
        const targetPlate = await last.getAttribute('aria-label');
        await menu().getByRole('menuitem', { name: 'Deactivate vehicle…', exact: true }).click();
        await page.locator('#confirm-dialog.is-open').waitFor({ state: 'visible' });
        check((await page.locator('#confirm-dialog-message').textContent()).includes(targetPlate.replace('Actions for ', '')), 'Confirmation identifies selected row');
        await page.locator('#confirm-dialog').getByRole('button', { name: 'Cancel', exact: true }).click();
        check(await last.evaluate((node) => node === document.activeElement), 'Confirmation returns focus to visible summary');
        await openChecked(last, 'Confirmed mutation');
        const statusURL = await menu().locator('form').getAttribute('action');
        await menu().getByRole('menuitem', { name: 'Deactivate vehicle…', exact: true }).click();
        await page.locator('#confirm-dialog.is-open').waitFor({ state: 'visible' });
        const submitted = page.waitForResponse((response) => response.url() === statusURL && response.request().method() === 'POST');
        await page.locator('[data-confirm-yes]').click();
        check((await submitted).status() === 302, 'Confirmation submits original CSRF form to selected vehicle');
        await page.waitForLoadState('load');
        const confirmed = await page.request.get(targetURL, { headers: { Accept: 'application/json' } });
        check((await confirmed.json()).status === 'inactive', 'Only selected fixture vehicle is deactivated');
        await openChecked(last, 'Filter replaces rows');
        await search('#vehicle_q', 'ZED');
        await closed();
        check(await page.locator('[data-vehicle-row]').count() === 24, 'Filtered long list retains all matching registry rows');
        await openChecked(triggers().first(), 'Long first row');
        await page.evaluate(() => scrollBy({ top: 60, behavior: 'instant' }));
        await page.waitForTimeout(60);
        check(await menu().count() === 0 || await menu().evaluate(n => { const r = n.getBoundingClientRect(); return r.top >= 7 && r.bottom <= innerHeight - 7; }), 'Page scrolling keeps a visible menu anchored or closes it safely');
        await page.keyboard.press('Escape');
        await closed();
        await openChecked(triggers().last(), 'Long last row');
        await page.setViewportSize({ width: 1280, height: 720 });
        await closed();
        await page.goto(base + '/visitors?q=VIS');
        check(await page.locator('.table-responsive tbody tr').count() === 20, 'Guest table preserves first-page size');
        await openChecked(page.locator('.table-responsive details summary').last(), 'Last guest row on page one');
        await page.keyboard.press('Escape');
        await page.locator('.pagination-links a').filter({ hasText: 'Next' }).click();
        await ready();
        check(await page.locator('.table-responsive details summary').count() === 5, 'Pagination replaces menu rows');
        await openChecked(page.locator('.table-responsive details summary').last(), 'Last paginated guest row');
        const pageTwoTarget = await menu().locator('[data-visitor-action=type]').getAttribute('data-action-url');
        await menu().getByRole('menuitem', { name: 'Correct type', exact: true }).click();
        await page.locator('#visitor-type-modal.is-open').waitFor({ state: 'visible' });
        check(await page.locator('[data-visitor-form=type]').getAttribute('action') === pageTwoTarget, 'Pagination action targets new guest record');
        await page.keyboard.press('Escape');
        await page.goto(base + '/registry?q=ABC');
        await openChecked(triggers().last(), 'Simulated poll replacement');
        await page.evaluate(() => {
            const body = document.querySelector('[data-vehicle-row]').parentElement;
            body.replaceWith(body.cloneNode(true));
        });
        await closed();
        await openChecked(triggers().first(), 'Replaced row reinitialized');
        await page.keyboard.press('Escape');
        await page.evaluate(() => {
            const panel = document.querySelector('[data-vehicle-row] .menu-panel');
            for (let i = 0; i < 40; i++) { const b = document.createElement('button'); b.textContent = 'Oversized action ' + i; panel.append(b); }
        });
        await openChecked(triggers().first(), 'Oversized menu');
        check(await menu().evaluate((node) => node.scrollHeight > node.clientHeight), 'Oversized menu itself scrolls');
        await menu().evaluate((node) => { node.scrollTop = 100; node.dispatchEvent(new Event('scroll')); });
        check(await menu().count() === 1, 'Menu scrolling does not dismiss itself');
        await page.keyboard.press('Escape');
        await page.goto(base + '/registry?q=ABC');
        await openChecked(triggers().last(), 'Pending action response');
        const pendingURL = await menu().locator('[data-vehicle-action=edit]').getAttribute('data-vehicle-url');
        await page.evaluate((url) => {
            window.phase5Fetch = window.fetch.bind(window);
            window.fetch = (...args) => window.phase5Fetch(...args).then((response) => String(args[0]) === url
                ? new Promise((resolve) => { window.releasePhase5Vehicle = () => resolve(response); }) : response);
        }, pendingURL);
        await menu().getByRole('menuitem', { name: 'Edit details', exact: true }).click();
        await page.waitForFunction(() => typeof window.releasePhase5Vehicle === 'function');
        await page.evaluate(() => {
            const body = document.querySelector('[data-vehicle-row]').parentElement;
            body.replaceWith(body.cloneNode(true));
            window.releasePhase5Vehicle();
        });
        await page.waitForTimeout(100);
        check(await page.locator('#edit-vehicle-drawer').isHidden(), 'A late action response cannot open a replaced row');
        await page.evaluate(() => { window.fetch = window.phase5Fetch; });
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(base + '/registry?q=ABC');
        // Deliberately constrain a real table to exercise legitimate container overflow.
        await page.locator('.table-responsive').evaluate((node) => { node.style.maxWidth = '300px'; node.querySelector('table').style.minWidth = '900px'; });
        await openChecked(triggers().last(), 'Horizontal table scroll');
        check(await page.locator('.table-responsive').evaluate((node) => node.scrollWidth > node.clientWidth), 'Legitimate narrow table overflow remains');
        await page.locator('.table-responsive').evaluate((node) => { node.scrollLeft = 0; node.dispatchEvent(new Event('scroll')); });
        await closed();

        const zoom = await browser.newContext({ viewport: { width: 1093, height: 614 }, deviceScaleFactor: 1.25,
            storageState: await context.storageState() });
        await zoom.route('**/*', (route) => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
        const zp = await zoom.newPage();
        zp.on('pageerror', (error) => errors.push(error.message));
        await zp.goto(base + '/registry?q=ABC');
        await zp.locator('[data-vehicle-row] details summary').last().click();
        const zm = zp.locator('[data-row-menu-panel][aria-hidden=false]');
        check(await zm.isVisible() && await zp.evaluate(() => devicePixelRatio) === 1.25, '125% equivalent CSS viewport and DPR');
        check(await zm.evaluate((node) => { const r = node.getBoundingClientRect(); return r.top >= 8 && r.bottom <= innerHeight - 8; }), 'Zoom-equivalent menu fits viewport');
        await zp.screenshot({ path: path.join(artifacts, 'after-menu-125-percent-layout.png') });
        await zoom.close();

        // Exercise the offline fallback by removing Popover API before initialization.
        const fallback = await browser.newContext({ viewport: { width: 1366, height: 768 }, storageState: await context.storageState() });
        await fallback.route('**/*', (route) => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
        await fallback.addInitScript(() => { Object.defineProperty(HTMLElement.prototype, 'showPopover', { value: undefined }); });
        const fp = await fallback.newPage();
        fp.on('pageerror', (error) => errors.push(error.message));
        await fp.goto(base + '/registry?q=ABC');
        const ft = fp.locator('[data-vehicle-row] details summary').last();
        const fallbackGeometry = () => fp.locator('.table-responsive').evaluate((n) => [n.clientHeight, n.clientWidth, n.scrollHeight, n.scrollWidth]);
        const fallbackBefore = await fallbackGeometry();
        await ft.click();
        const portal = fp.locator('body > [data-row-menu-panel]');
        check(await portal.isVisible(), 'Fallback portals original panel outside table');
        check(JSON.stringify(await fallbackGeometry()) === JSON.stringify(fallbackBefore), 'Fallback leaves table dimensions and scrolling unchanged');
        await fp.keyboard.press('Escape');
        check(await ft.evaluate(n => n === document.activeElement), 'Fallback Escape restores summary focus');
        await ft.click();
        await portal.getByRole('menuitem', { name: 'Edit details', exact: true }).click();
        await fp.locator('#edit-vehicle-drawer.is-open').waitFor({ state: 'visible' });
        check(await fp.locator('body > [data-row-menu-panel]').count() === 0, 'Fallback restores nodes before original handler');
        await fp.keyboard.press('Escape');
        await ft.click();
        const blocked = await fp.evaluate(() => {
            const panel = document.querySelector('body > [data-row-menu-panel]');
            document.querySelector('[data-vehicle-row] details[open]').closest('tr').remove();
            // Same task: observer has not yet cleaned the stale portal.
            const event = new MouseEvent('click', { bubbles: true, cancelable: true });
            panel.querySelector('[data-vehicle-action=edit]').dispatchEvent(event);
            return event.defaultPrevented;
        });
        check(blocked && await fp.locator('body > [data-row-menu-panel]').count() === 0, 'Detached-row portal action is blocked before handlers run');
        check(await fp.locator('#edit-vehicle-drawer').isHidden(), 'Stale action never opens another record');
        await fallback.close();
        check(errors.length === 0, 'No browser script errors: ' + errors.join('; '));
        console.log(`Registration Phase 5 browser checks: ${checks} passed.`);
    }
} finally {
    if (browser) await browser.close();
    if (server) server.kill('SIGTERM');
    fs.rmSync(directory, { recursive: true, force: true });
}
