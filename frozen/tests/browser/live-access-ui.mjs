import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { chromium } from 'playwright';

// Local DOM/auth response fixture only; no camera, trust-store or hardware access.
const root=path.resolve(import.meta.dirname,'../..');let authorized=true;let browser;let checks=0;
const image=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lp8AAAAASUVORK5CYII=','base64');
const server=http.createServer((req,res)=>{
 if(req.url==='/live/proxy-auth'){res.writeHead(authorized?204:401,{'Cache-Control':'no-store'});res.end();return;}
 if(req.url.startsWith('/image')){res.writeHead(200,{'Content-Type':'image/png'});res.end(image);return;}
 if(req.url==='/live-video.js'){res.writeHead(200,{'Content-Type':'application/javascript'});res.end(fs.readFileSync(path.join(root,'public/js/live-video.js')));return;}
 res.writeHead(200,{'Content-Type':'text/html'});res.end('<meta name="csrf-token" content="fixture"><div data-live-video data-gate="gate-1" data-webrtc="0" data-overlay="0" data-mjpeg-url="/image" data-viewer-lease="25"><video></video><img hidden><span data-live-mode></span></div><script src="/live-video.js"></script>');
});
try{
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 browser=await chromium.launch({channel:'chrome',headless:true});const page=await browser.newPage();
 await page.goto('http://127.0.0.1:'+server.address().port);await page.waitForFunction(()=>window.LiveVideo?.players.length===1);
 const check=(value,message)=>{assert.ok(value,message);checks++;};
 check(await page.locator('[data-live-video]').count()===1,'One fixture player');
 await page.evaluate(()=>window.LiveVideo.players[0].checkAuthorization());
 check(await page.evaluate(()=>!window.LiveVideo.players[0].stopped),'Valid session keeps player active');
 authorized=false;
 await page.evaluate(()=>{
  const p=window.LiveVideo.players[0];window.closedPeerCount=0;p.pc={close(){window.closedPeerCount++;}};
  return p.checkAuthorization();
 });
 check(await page.evaluate(()=>window.LiveVideo.players[0].stopped),'Revoked session stops playback');
 check(await page.evaluate(()=>window.closedPeerCount===1),'Existing peer is closed');
 check(await page.locator('[data-live-mode]').innerText()==='Sign in again','Clear feedback');
 check(await page.locator('img').getAttribute('src')===null,'Image connection removed');
 check(await page.locator('video').getAttribute('src')===null,'Video URL removed');
 check(await page.evaluate(()=>window.LiveVideo.players[0].pc===null),'Peer cleared');
 console.log(`Live access browser checks: ${checks} passed (synthetic auth/media; HTTP DOM fixture, not native TLS acceptance).`);
}finally{if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));}
