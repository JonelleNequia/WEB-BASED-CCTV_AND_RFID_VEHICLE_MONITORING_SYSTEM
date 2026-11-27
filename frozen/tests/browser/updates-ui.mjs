import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {chromium} from 'playwright';
const root=new URL('../..',import.meta.url);let source='old',maintenance=false,denied=false,disconnected=false,fail=false,checks=0,requests=[];
const html=`<!doctype html><meta name="csrf-token" content="fixture-csrf"><body data-version-url="/version"><input id="unsaved" value="keep me"><form data-update-import data-url="/import"><input type="hidden" name="_token" value="fixture-csrf"><input name="package" type="file"><input name="manifest" type="file"><button>Import complete package</button><p role="status" data-update-progress></p></form><script>const original=window.setInterval;window.setInterval=fn=>original(fn,100);</script><script src="/updates.js"></script><script src="/deployment-refresh.js"></script>`;
const server=http.createServer(async(req,res)=>{
 if(req.url==='/'){res.setHeader('Content-Type','text/html');res.end(html);return;}
 if(req.url==='/version'){if(disconnected){req.socket.destroy();return;}res.writeHead(denied?401:(maintenance?503:200),{'Content-Type':'application/json'});res.end(JSON.stringify({source,version:source==='old'?'1.0.0':'1.1.0'}));return;}
 if(req.url.endsWith('.js')){res.setHeader('Content-Type','text/javascript');res.end(fs.readFileSync(new URL('public/js'+req.url,root)));return;}
 let bytes=[];for await(const block of req)bytes.push(block);if(req.method==='POST')requests.push({url:req.url,csrf:req.headers['x-csrf-token'],body:Buffer.concat(bytes)});
 res.writeHead(fail?422:200,{'Content-Type':'application/json'});res.end(JSON.stringify({id:'fixture',chunk_url:'/chunk',complete:true}));
});
await new Promise(r=>server.listen(0,'127.0.0.1',r));const base=`http://127.0.0.1:${server.address().port}`;
const executable='/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';let browser;
const check=(condition,message)=>{assert.ok(condition,message);checks++;};
try{
 browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||(fs.existsSync(executable)?executable:undefined)});
 const page=await browser.newPage({viewport:{width:1366,height:768}});await page.goto(base);await page.waitForTimeout(200);
 await page.locator('#unsaved').fill('unsaved administrator input');maintenance=true;await page.getByRole('status').filter({hasText:'Server maintenance'}).waitFor();
 check(await page.locator('#unsaved').inputValue()==='unsaved administrator input','Maintenance retains entered values');
 maintenance=false;source='new';await page.getByRole('button',{name:'Refresh when ready'}).waitFor();
 check(await page.locator('#unsaved').inputValue()==='unsaved administrator input','Version change does not reload');
 page.once('dialog',d=>d.dismiss());await page.getByRole('button',{name:'Refresh when ready'}).click();check(await page.locator('#unsaved').inputValue()==='unsaved administrator input','Cancel preserves data');
 await page.locator('[name=package]').setInputFiles({name:'complete.zip',mimeType:'application/zip',buffer:Buffer.alloc(4*1024**2+7,65)});
 await page.locator('[name=manifest]').setInputFiles({name:'signed.update.json',mimeType:'application/json',buffer:Buffer.from('{"signed":{}}')});
 await page.getByRole('button',{name:'Import complete package'}).click();await page.locator('[data-update-progress]').filter({hasText:'Native validation is queued'}).waitFor();
 check(requests.length===3,'One metadata request and two bounded chunks');check(requests.every(r=>r.csrf==='fixture-csrf'),'Every mutation sends CSRF');
 check(requests[1].body.includes(Buffer.from('name="offset"\r\n\r\n0')),'First offset is zero');check(requests[2].body.includes(Buffer.from('4194304')),'Second offset follows 4 MiB chunk');
 check(await page.getByRole('button',{name:'Import complete package'}).isEnabled(),'Upload button recovers after completion');
 fail=true;await page.getByRole('button',{name:'Import complete package'}).click();await page.locator('[data-update-progress]').filter({hasText:'could not start'}).waitFor();
 check(await page.getByRole('button',{name:'Import complete package'}).isEnabled(),'Validation failure permits correction/retry');
 denied=true;await page.getByRole('status').filter({hasText:'Your session changed'}).waitFor();check(await page.locator('#unsaved').inputValue()==='unsaved administrator input','Session revocation preserves unsaved values');
 denied=false;disconnected=true;await page.getByRole('status').filter({hasText:'temporarily unavailable'}).waitFor();check(await page.locator('#unsaved').inputValue()==='unsaved administrator input','Disconnected maintenance preserves input and shows feedback');
 await page.setViewportSize({width:390,height:844});check(await page.locator('[data-update-import]').isVisible(),'Import remains available at narrow width');
 check(await page.locator('#unsaved').inputValue()==='unsaved administrator input','No reload loop after upload/failure');
 console.log(`${checks} isolated Chrome update/refresh checks passed; DOM fixtures, no OS trust or native updater execution.`);
}finally{await browser?.close();await new Promise(r=>server.close(r));}
