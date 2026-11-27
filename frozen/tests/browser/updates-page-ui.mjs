import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import http from 'node:http';
import {spawnSync} from 'node:child_process';
import {randomBytes} from 'node:crypto';
import {chromium} from 'playwright';
const root=path.resolve(import.meta.dirname,'../..'),directory=fs.mkdtempSync(path.join(os.tmpdir(),'philcst-ui-updates-'));fs.chmodSync(directory,0o700);
let html='',browser,checks=0;const server=http.createServer((req,res)=>{
 if(req.url==='/'){res.setHeader('Content-Type','text/html');res.end(html);return;}
 if(req.url?.startsWith('/css/')){res.setHeader('Content-Type','text/css');res.end(fs.readFileSync(path.join(root,'public',req.url.split('?')[0])));return;}
 if(req.url?.startsWith('/js/')){res.setHeader('Content-Type','text/javascript');res.end(fs.readFileSync(path.join(root,'public',req.url.split('?')[0])));return;}
 if(req.url==='/system/version'){res.setHeader('Content-Type','application/json');res.end('{"source":"fixture","version":"1.0.0"}');return;}
 res.writeHead(404);res.end();
});
try{
 await new Promise(r=>server.listen(0,'127.0.0.1',r));const base=`http://127.0.0.1:${server.address().port}`;
 const env={...process.env,APP_ENV:'testing',APP_URL:base,DB_CONNECTION:'sqlite',DB_DATABASE:directory+'/ui.sqlite',DB_URL:'',APP_KEY:'base64:'+randomBytes(32).toString('base64'),SESSION_DRIVER:'array',CACHE_STORE:'array',LOG_CHANNEL:'testing',MONITORING_SERVICES:'managed',APP_CONFIG_CACHE:directory+'/config.php',APP_ROUTES_CACHE:directory+'/routes.php',CAMERA_FILES_PATH:directory+'/camera',DEVICE_FILES_PATH:directory+'/devices',ARGON_MEMORY:'1024',ARGON_TIME:'1'};
 const fixture=spawnSync('php',['tests/fixtures/updates-page.php',directory],{cwd:root,env,encoding:'utf8',timeout:30000});assert.equal(fixture.status,0,fixture.stdout+fixture.stderr);assert.ok(fs.existsSync(directory+'/updates.html'),fixture.stdout+fixture.stderr);html=fs.readFileSync(directory+'/updates.html','utf8');
 browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'});const page=await browser.newPage();await page.route('**/*',route=>new URL(route.request().url()).origin===base?route.continue():route.abort());
 for(const width of [1366,390]){
  await page.setViewportSize({width,height:900});await page.goto(base);await page.getByRole('heading',{name:'Updates',exact:true}).waitFor();
  assert.ok(await page.getByRole('button',{name:'Check for Updates'}).isVisible());checks++;
  assert.ok(await page.getByRole('button',{name:'Request installation'}).isVisible());checks++;
  assert.ok(await page.locator('[data-updates]').innerText().then(s=>s.includes('Synthetic <script>')));checks++;
  assert.equal(await page.locator('script').evaluateAll(nodes=>nodes.some(n=>n.textContent.includes('untrusted release note'))),false);checks++;
  assert.ok(await page.locator('[name=maintenance_confirmed]').getAttribute('required')!==null);checks++;
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));checks++;
 }
 console.log(`${checks} rendered Laravel Updates page checks passed at desktop and 390px; no native installation.`);
}finally{await browser?.close();await new Promise(r=>server.close(r));fs.rmSync(directory,{recursive:true,force:true});}
