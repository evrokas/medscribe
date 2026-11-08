#!/usr/bin/env node
// Dev tool: screenshots of app pages with a session cookie (headless Chromium over the DevTools protocol, Node 22's
// built-in WebSocket; no npm packages). Used by tools/shots.php, which makes a temporary account and logs it in.
//   node tools/shot.js <cookie-name>=<value> <width>x<height> <url> <out.png> [<url> <out.png> ...]
// CHROME=... overrides the browser (else the Playwright Chromium in ~/.cache/ms-playwright).
const fs = require('fs'), path = require('path'), os = require('os'), {spawn} = require('child_process');
function findChrome() {
  if (process.env.CHROME) return process.env.CHROME;
  const base = path.join(os.homedir(), '.cache/ms-playwright');
  try {
    for (const d of fs.readdirSync(base).sort().reverse())
      for (const rel of ['chrome-headless-shell-linux64/chrome-headless-shell', 'chrome-linux64/chrome', 'chrome-linux/chrome']) {
        const p = path.join(base, d, rel); if (fs.existsSync(p)) return p;
      }
  } catch (e) {}
  return '/usr/bin/chromium';
}
const [cookie, size, ...rest] = process.argv.slice(2);
const [cname, ...cv] = cookie.split('='); const [w, h] = size.split('x').map(Number);
const prof = fs.mkdtempSync(path.join(os.tmpdir(), 'shot-'));
const port = 9300 + Math.floor(Math.random() * 500);
const ch = spawn(findChrome(), ['--headless', '--no-sandbox', '--disable-gpu', '--hide-scrollbars', `--remote-debugging-port=${port}`,
  `--user-data-dir=${prof}`, `--window-size=${w},${h}`, 'about:blank'], {stdio: 'ignore'});
const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => {
  let tabs;
  for (let i = 0; i < 50; i++) { try { tabs = await (await fetch(`http://127.0.0.1:${port}/json`)).json(); break; } catch (e) { await sleep(100); } }
  const ws = new WebSocket(tabs.find(t => t.type === 'page').webSocketDebuggerUrl);
  await new Promise(r => ws.onopen = r);
  let id = 0; const wait = {}, events = [];
  ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && wait[d.id]) { wait[d.id](d); delete wait[d.id]; } else events.push(d.method); };
  const send = (method, params = {}) => new Promise(r => { const i = ++id; wait[i] = r; ws.send(JSON.stringify({id: i, method, params})); });
  await send('Page.enable'); await send('Network.enable');
  await send('Emulation.setDeviceMetricsOverride', {width: w, height: h, deviceScaleFactor: 1, mobile: false});
  for (let i = 0; i < rest.length; i += 2) {
    const url = rest[i], out = rest[i + 1], u = new URL(url.split('#')[0]);
    await send('Network.setCookie', {name: cname, value: cv.join('='), domain: u.hostname, path: '/'});
    events.length = 0;
    await send('Page.navigate', {url});
    for (let k = 0; k < 100 && !events.includes('Page.loadEventFired'); k++) await sleep(100);
    await sleep(Number(process.env.SHOT_WAIT || 600));
    if (process.env.SHOT_PDFIMG && fs.existsSync(process.env.SHOT_PDFIMG)) {
      const img = 'data:image/png;base64,' + fs.readFileSync(process.env.SHOT_PDFIMG).toString('base64');
      await send('Runtime.evaluate', {expression: `document.querySelectorAll('.cx-pv iframe').forEach(f => { const i = document.createElement('img'); i.src = ${JSON.stringify(img)}; i.style.cssText = 'width:100%;height:auto;display:block;border-top:1px solid #ddd'; const w = document.createElement('div'); w.style.cssText = 'flex:1 1 auto;overflow:auto;background:#E7EEF0;padding:12px'; w.appendChild(i); f.replaceWith(w); }); 1`});
      await sleep(300);
    }
    if (process.env.SHOT_JS) {
      const ev = await send('Runtime.evaluate', {expression: process.env.SHOT_JS, awaitPromise: true, returnByValue: true});
      const v = ev.result && ev.result.result ? ev.result.result.value : undefined;
      if (v !== undefined) console.log('js: ' + (typeof v === 'string' ? v : JSON.stringify(v)));
      if (ev.result && ev.result.exceptionDetails) console.log('js error: ' + JSON.stringify(ev.result.exceptionDetails.exception && ev.result.exceptionDetails.exception.description));
    }
    if (process.env.SHOT_JS) await sleep(400);
    const r = await send('Page.captureScreenshot', {format: 'png', captureBeyondViewport: false});
    fs.writeFileSync(out, Buffer.from(r.result.data, 'base64'));
    console.log('wrote ' + out);
  }
  ws.close(); ch.kill(); await sleep(300); try { fs.rmSync(prof, {recursive: true, force: true}); } catch (e) {}
})().catch(e => { console.error(e); ch.kill(); process.exit(1); });
