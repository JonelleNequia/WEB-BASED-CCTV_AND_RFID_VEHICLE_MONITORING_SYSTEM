import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { chromium } from 'playwright';

// Actual player/monitor scripts, synthetic HTTP frames/status. Never opens hardware.
const root = path.resolve(import.meta.dirname, '../..');
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lp8AAAAASUVORK5CYII=', 'base64');
const historical = {id:'visitor-1', plate_number:'Needs review: 395ISX', plate_candidate:'395ISX',
    result_title:'Needs review: 395ISX', result_detail:'Identifier type is unconfirmed.', category_label:'Review',
    direction_label:'IN', event_time:'2025-01-02T08:30:00+08:00', tone:'warning'};
let detector = true, configured = false, running = false, fail = false, delay = 0, requestStarted;
let browser, checks = 0;
const check = (condition, message) => {assert.ok(condition, message); checks++;};
const server = http.createServer((req, res) => {
    if(req.url === '/state') {
        const body = {detector_running:detector, gates:{'gate-1':{source_configured:configured,camera_running:running,
            camera_error:configured ? '' : 'No camera added for this gate.', logs:[historical],
            checking:[{event_key:'stale-open',detected_at:'2025-01-02T08:29:00+08:00'}]}}};
        const wait = delay; delay = 0; requestStarted?.(); requestStarted = null;
        setTimeout(() => {res.writeHead(fail ? 503 : 200, {'Content-Type':'application/json','Cache-Control':'no-store'});res.end(JSON.stringify(body));}, wait);
        return;
    }
    if(req.url.startsWith('/image')) {res.writeHead(200, {'Content-Type':'image/png'});res.end(png);return;}
    if(['/ui.js','/live-video.js','/gate-monitor.js'].includes(req.url)) {
        res.writeHead(200, {'Content-Type':'text/javascript'});res.end(fs.readFileSync(path.join(root,'public/js',req.url.slice(1))));return;
    }
    res.writeHead(200, {'Content-Type':'text/html'});
    res.end(`<meta name="csrf-token" content="fixture">
        <span data-live-indicator data-live-connected-label="Updates connected"><span data-live-label></span></span><span data-gate-detector></span>
        <section class="gate-card" data-gate="gate-1" data-source-configured="0" data-camera-running="0">
            <span data-gate-camera></span><div class="gate-feed"><div data-live-video data-gate="gate-1" data-webrtc="0" data-overlay="0" data-mjpeg-url="/image">
            <video></video><img hidden><span data-live-mode></span></div><div data-gate-feed-offline><span data-feed-offline-text></span></div></div>
            <span>Latest recorded activity</span><div data-gate-latest></div><ul data-gate-logs></ul></section>
        <script type="application/json" id="gate-monitor-data">{"stateUrl":"/state"}</script>
        <script src="/ui.js"></script><script src="/live-video.js"></script><script src="/gate-monitor.js"></script>`);
});
await new Promise(resolve => server.listen(0,'127.0.0.1',resolve));
const base='http://127.0.0.1:'+server.address().port;
try {
    const chrome='/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
    browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || (fs.existsSync(chrome)?chrome:undefined)});
    const page=await browser.newPage({viewport:{width:1366,height:768}});
    const errors=[];page.on('pageerror', e=>errors.push(e.message));
    await page.route('**/*', route=>new URL(route.request().url()).origin===base?route.continue():route.abort());
    await page.goto(base);
    const camera=page.locator('[data-gate-camera]');
    await page.waitForFunction(()=>window.LiveVideo.players[0]?.hasPicture());
    await page.waitForFunction(()=>document.querySelector('[data-gate-camera]').textContent.includes('Source unavailable'));
    check(await page.evaluate(()=>window.LiveVideo.players[0].hasPicture()),'A real decoded synthetic image is present');
    check((await camera.textContent()).includes('Source unavailable'),'A decoded placeholder cannot prove a live source');
    check(!(await page.locator('[data-live-mode]').textContent()).includes('Live'),'Transport badge does not claim camera health');
    check((await page.locator('[data-live-label]').textContent()).includes('Updates connected'),'Polling health is explicitly separate');
    check((await page.locator('[data-gate-latest]').textContent()).includes('395ISX'),'Review candidate remains visible without camera source');
    check((await page.locator('[data-gate-latest] time').textContent()).includes('2025'),'Historical activity retains its original date');
    check((await page.locator('[data-gate-logs]').textContent()).includes('Needs review: 395ISX'),'Recent rows retain candidate/review label');
    check(!(await page.locator('[data-gate-latest]').textContent()).includes('Checking'),'Unavailable source cannot revive a stale open pass');
    configured=true;running=true;
    await page.waitForFunction(()=>document.querySelector('[data-gate-camera]').textContent.trim()==='Live');
    check((await camera.textContent()).trim()==='Live','Healthy source status plus received MJPEG may be live');
    // Independent video can remain healthy while the OCR/detector process is down.
    detector=false;running=false;
    await page.evaluate(()=>{const p=window.LiveVideo.players[0];p.setMode('webrtc');p.pictureAt=Date.now();p.emit('live:ready');});
    await page.waitForFunction(()=>document.querySelector('[data-gate-detector]').textContent.includes('starting'));
    check((await camera.textContent()).trim()==='Live','Fresh independent video is retained through detector downtime');
    configured=false;
    await page.waitForFunction(()=>document.querySelector('[data-gate-camera]').textContent.includes('Source unavailable'));
    check((await camera.textContent()).includes('Source unavailable'),'Explicit missing source overrides a leftover video picture');
    await page.evaluate(()=>{const p=window.LiveVideo.players[0];p.setMode('mjpeg');p.mjpegFailed=false;});
    configured=true;running=true;detector=true;fail=true;
    await page.waitForFunction(()=>document.querySelector('[data-gate-camera]').textContent.includes('unverified') || document.querySelector('[data-live-label]').textContent.includes('Offline'));
    check((await camera.textContent()).trim()!=='Live','Unavailable status cannot preserve a stale MJPEG Live claim');
    check((await page.locator('[data-gate-logs]').textContent()).includes('2025'),'Status failure retains historical rows');
    fail=false;delay=3000;
    const started=new Promise(resolve=>{requestStarted=resolve;});await started;
    configured=false;running=false;
    await page.waitForFunction(()=>document.querySelector('[data-gate-camera]').textContent.includes('Source unavailable'));
    await page.waitForTimeout(3600);
    check((await camera.textContent()).includes('Source unavailable'),'Late older status response cannot replace the newer source decision');
    await page.setViewportSize({width:390,height:844});
    check((await page.locator('[data-gate-latest]').textContent()).includes('395ISX'),'Candidate and historical time remain available at narrow width');
    check(errors.length===0,'No browser script errors');
    console.log(`OCR/camera browser checks: ${checks} passed; synthetic local frames/status, no camera or native service test.`);
} finally {if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));}
