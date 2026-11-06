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
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'philcst-ui-guest-filters-'));
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
    const fixture = spawnSync(process.env.PHP_BINARY || 'php', ['tests/fixtures/guest-quick-filters-ui.php', directory], {
        cwd: root, env, encoding: 'utf8', timeout: 30000,
    });
    assert.equal(fixture.status, 0, fixture.stderr || fixture.stdout);
    assert.ok(fs.existsSync(directory + '/guest-filters.json'), fixture.stderr || fixture.stdout);
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

    const data = JSON.parse(fs.readFileSync(directory + '/guest-filters.json', 'utf8'));
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    const artifacts = path.join(root, 'storage/app/ui-review/guest-quick-filters'); fs.mkdirSync(artifacts, { recursive: true });
    const requests = []; page.on('request', request => { if (request.headers()['x-auto-filter'] === '1') requests.push(request.url()); });
    const ready = async () => {
        try { await page.waitForFunction(() => document.querySelector('[data-auto-list]')?.dataset.autoState === 'ready'); }
        catch (error) { throw new Error(error.message + '\n' + JSON.stringify({ url: page.url(), errors,
            body: (await page.locator('body').innerText()).slice(0, 1500) })); }
    };
    const chips = page.locator('[data-auto-update=record-filters]');
    const chip = name => chips.getByRole('link', { name, exact: true });
    const selected = () => chips.locator('[aria-current=true]').innerText();
    const rows = () => page.locator('[data-auto-update=results] tbody tr').evaluateAll(rows => rows.map(row => Number(row.id.replace('visitor-record-', ''))).sort((a,b) => a-b));
    const eq = (actual, expected) => JSON.stringify(actual) === JSON.stringify([...expected].sort((a,b) => a-b));
    const click = async name => { await chip(name).click(); await ready(); };
    const choose = async (id, value) => { await page.locator(id).selectOption(value); await ready(); };
    const search = async value => { await page.locator('#visitor_q').fill(value); await ready(); };
    await page.goto(base + '/visitors');
    check(JSON.stringify(await chips.getByRole('link').allTextContents()) === JSON.stringify(['All', 'Registered', 'Unregistered Guests', 'Manual entries']), 'Four compact quick filters use accurate labels');
    check(await selected() === 'All' && await page.locator('[name=record_filter]').inputValue() === '', 'All is selected by default');
    check(await page.getByRole('link', { name: 'Plate Profiles', exact: true }).count() === 1 && await page.getByRole('link', { name: /^Review/ }).count() === 1, 'Plate Profiles and Review remain separate tabs');
    await click('Registered');
    check(eq(await rows(), [data.ids['registered-1'], data.ids['registered-2']]), 'Registered uses the saved occurrence decision, not the shared plate');
    check(await selected() === 'Registered' && await page.locator('[name=record_filter]').inputValue() === 'registered', 'Selected chip and hidden criterion stay synchronized');
    const selectedColor = await chip('Registered').evaluate(link => getComputedStyle(link).backgroundColor);
    const otherColor = await chip('All').evaluate(link => getComputedStyle(link).backgroundColor);
    check(selectedColor !== otherColor, 'Applied chip has a distinct visual state');
    const activityRegistered = await (await page.request.get(base + '/logs?log_type=registered', { headers: { Accept: 'application/json' } })).json();
    check(eq(await rows(), activityRegistered.logs.filter(row => row.record_type === 'visitor_record').map(row => row.id)), 'Registered IDs agree with Activity Logs for the same occurrences');
    await click('Unregistered Guests');
    check((await rows()).length === 20 && new URL(page.url()).searchParams.get('record_filter') === 'unregistered', 'Unregistered results paginate with the applied criterion');
    const guestIds = [...await rows()];
    await page.locator('.pagination-links').getByRole('link', { name: 'Next', exact: true }).click(); await ready(); guestIds.push(...await rows());
    check(new URL(page.url()).searchParams.get('page') === '2' && await selected() === 'Unregistered Guests', 'Pagination retains the quick filter');
    check(guestIds.includes(data.ids['historical-guest']) && !guestIds.includes(data.ids.review), 'Historical Guest stays Guest after later registration and Review is excluded');
    const activityGuests = await (await page.request.get(base + '/logs?log_type=unregistered_visitor', { headers: { Accept: 'application/json' } })).json();
    const activityGuestIds = activityGuests.logs.map(row => row.id);
    const activityPage2 = await (await page.request.get(base + '/logs?log_type=unregistered_visitor&page=2', { headers: { Accept: 'application/json' } })).json();
    activityGuestIds.push(...activityPage2.logs.map(row => row.id));
    check(eq(guestIds.sort((a,b)=>a-b), activityGuestIds), 'All Guest pages agree with Activity Logs for the same occurrences');
    await click('Manual entries');
    check(eq(await rows(), [data.ids['manual-1'], data.ids['manual-2'], data.ids['manual-archived']]), 'Manual entries uses source=manual and excludes staff-corrected camera records');
    check(!new URL(page.url()).searchParams.has('page'), 'Changing quick filter returns to page one');
    await choose('#visitor_status', 'duplicate');
    check(eq(await rows(), [data.ids.duplicate]) && await selected() === 'Manual entries', 'Show Duplicates is independent of Manual entries');
    await choose('#visitor_status', 'dismissed');
    check(eq(await rows(), [data.ids.dismissed]), 'Show Dismissed combines with the quick filter');
    await choose('#visitor_status', 'all');
    check((await rows()).length === 5, 'Show All includes all manual record statuses');
    await choose('#visitor_status', 'active'); await choose('#visitor_plate_status', 'corrected'); await choose('#visitor_gate', 'gate-2'); await search('man-0002');
    check(eq(await rows(), [data.ids['manual-2']]), 'Quick filter combines with normalized partial search, gate, plate result and Show');
    await click('All');
    check(await selected() === 'All' && await page.locator('#visitor_q').inputValue() === 'MAN-0002'
        && await page.locator('#visitor_gate').inputValue() === 'gate-2' && await page.locator('#visitor_plate_status').inputValue() === 'corrected'
        && await page.locator('#visitor_status').inputValue() === 'active', 'All clears only the quick criterion and keeps other controls');
    await page.goBack(); await ready();
    check(await selected() === 'Manual entries' && await page.locator('[name=record_filter]').inputValue() === 'manual', 'Back restores selected chip and criterion');
    await page.goForward(); await ready();
    check(await selected() === 'All' && await page.locator('#visitor_gate').inputValue() === 'gate-2', 'Forward restores selection and other criteria');
    await page.locator('[data-auto-reset]').click(); await ready();
    check(await selected() === 'All' && await page.locator('#visitor_q').inputValue() === '' && await page.locator('#visitor_gate').inputValue() === ''
        && await page.locator('#visitor_plate_status').inputValue() === '' && await page.locator('#visitor_status').inputValue() === 'active', 'Reset restores existing defaults and clears quick selection');
    await chip('Registered').focus(); await page.keyboard.press('Enter'); await ready();
    check(await selected() === 'Registered', 'Quick filters work with keyboard Enter and expose aria-current');
    await search('NO-MATCH');
    check(await page.locator('[data-auto-update=results]').getAttribute('data-auto-empty') === 'true'
        && await selected() === 'Registered', 'Empty results preserve the selected filter');
    await search('');
    await page.evaluate(() => {
        window.savedFetch = window.fetch;
        window.fetch = async (...args) => {
            const response = await window.savedFetch(...args);
            if (new URL(args[0], location.href).searchParams.get('record_filter') === 'unregistered') {
                await new Promise(resolve => setTimeout(resolve, 650));
            }
            return response;
        };
    });
    await chip('Unregistered Guests').click();
    await page.waitForFunction(() => document.querySelector('[data-auto-list]').dataset.autoState === 'loading');
    await click('Manual entries'); await page.waitForTimeout(800);
    check(await selected() === 'Manual entries' && new URL(page.url()).searchParams.get('record_filter') === 'manual'
        && eq(await rows(), [data.ids['manual-1'], data.ids['manual-2'], data.ids['manual-archived']]), 'Delayed older response cannot restore an old selection or rows');
    await page.evaluate(() => { window.fetch = window.savedFetch; });
    await page.locator('#visitor_q').fill('MAN 0002'); await click('All');
    check(await page.locator('#visitor_q').inputValue() === 'MAN 0002' && eq(await rows(), [data.ids['manual-2']]), 'Chip click preserves text still waiting for debounce');
    await page.locator('[data-auto-reset]').click(); await ready(); await click('Manual entries');
    // Archived gates remain available through historical bookmarks, not new selectors.
    await page.goto(base + '/visitors?record_filter=manual&gate=' + data.archived_gate);
    check(eq(await rows(), [data.ids['manual-archived']]) && await page.locator('[data-archived-gate-context]').isVisible(), 'Archived-gate history is preserved with quick filters');
    await click('All');
    check(await page.locator('[data-archived-gate-context]').isVisible() && eq(await rows(), [data.ids['manual-archived']]), 'All retains archived-gate context');
    await page.locator('[data-auto-reset]').click(); await ready(); await click('Manual entries');
    const first = page.locator('#visitor-record-' + data.ids['manual-1']);
    for (const [action, modal] of [['plate', 'visitor-plate-modal'], ['type', 'visitor-type-modal'], ['note', 'visitor-note-modal'], ['dismiss', 'visitor-dismiss-modal']]) {
        await first.locator('summary').click(); await first.locator('[data-visitor-action=' + action + ']').click();
        check(await page.locator('#' + modal).isVisible() && (await page.locator('[data-visitor-form=' + action + ']').getAttribute('action')).includes(action === 'note' ? '/plates/' : '/records/'), 'Refreshed ' + action + ' action opens its existing dialog with the correct target');
        await page.locator('#' + modal).getByRole('button', { name: 'Close', exact: true }).click();
    }
    await first.locator('summary').click(); await first.locator('[data-visitor-action=note]').click();
    await page.locator('#visitor_note_value').fill('Synthetic note after automatic refresh');
    await page.locator('[data-visitor-form=note] button[type=submit]').click(); await page.waitForLoadState('networkidle');
    await click('Manual entries');
    await first.locator('summary').click(); await first.locator('[data-visitor-action=note]').click();
    check(await page.locator('#visitor_note_value').inputValue() === 'Synthetic note after automatic refresh', 'Row action still saves and reloads data after results replacement');
    await page.locator('#visitor-note-modal').getByRole('button', { name: 'Close', exact: true }).click();
    if (await page.locator('.toast-close').isVisible()) await page.locator('.toast-close').click();
    await page.screenshot({ path: path.join(artifacts, 'desktop-manual.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    const bounds = await chips.getByRole('link').evaluateAll(links => links.map(link => { const rect = link.getBoundingClientRect(); return { left: rect.left, right: rect.right, top: rect.top, viewport: innerWidth }; }));
    check(bounds.every(rect => rect.left >= 0 && rect.right <= rect.viewport) && new Set(bounds.map(rect => rect.top)).size > 1, 'Chips wrap within the narrow viewport');
    const inputs = await page.locator('form[data-auto-filter]').evaluate(form => [...form.querySelectorAll('input:not([type=hidden]), select')].map(input => { const r = input.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth; }));
    check(inputs.every(Boolean), 'Existing filter controls fit at 390px');
    check(await page.locator('.guest-records-table .table-responsive').evaluate(node => node.scrollWidth > node.clientWidth), 'Narrow table scrolls horizontally without crushing the plate and row text');
    await page.screenshot({ path: path.join(artifacts, 'mobile-manual.png'), fullPage: true });
    check(errors.length === 0, 'No browser script errors');
    check(requests.some(url => new URL(url).searchParams.get('record_filter') === 'manual'), 'Quick changes use the existing automatic refresh requests');
    console.log(`Guests quick-filter browser checks: ${checks} passed.`);
} finally {
    if (browser) await browser.close();
    if (server) server.kill('SIGTERM');
    fs.rmSync(directory, { recursive: true, force: true });
}

