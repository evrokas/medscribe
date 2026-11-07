#!/usr/bin/env node
// The chip editor's DOM half in a real browser (docs/plans/type-editor-ui.md §4.3): every block text of every type file
// survives text -> contenteditable -> text byte for byte (or is refused as unsafe and kept as plain text), and edits made
// through the editor (typing, a chip, Enter, bold) come back as the expected markup.
//   node bin/check_chips_dom.js            (CHROME=/path/to/chrome-or-headless-shell; skipped when none is found)
'use strict';
const fs = require('fs'), path = require('path'), os = require('os'), {execFileSync} = require('child_process');
const app = path.join(__dirname, '..');
function findChrome() {
  if (process.env.CHROME) return process.env.CHROME;
  const base = path.join(os.homedir(), '.cache/ms-playwright');
  try {
    for (const d of fs.readdirSync(base).sort().reverse()) {
      for (const rel of ['chrome-headless-shell-linux64/chrome-headless-shell', 'chrome-linux/chrome', 'chrome-linux64/chrome']) {
        const p = path.join(base, d, rel); if (fs.existsSync(p)) return p;
      }
    }
  } catch (e) {}
  for (const p of ['/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome']) if (fs.existsSync(p)) return p;
  return null;
}
const chrome = findChrome();
if (!chrome) { console.log('SKIP  no Chromium found (set CHROME=...)'); process.exit(0); }
const dir = path.join(app, 'types');
const files = fs.readdirSync(dir).filter(f => f.endsWith('.json')).map(f => path.join(dir, f))
  .concat(fs.readdirSync(path.join(dir, 'shared')).filter(f => f.endsWith('.json')).map(f => path.join(dir, 'shared', f)));
const docs = files.map(f => ({name: path.basename(f), text: fs.readFileSync(f, 'utf8')}));
const js = n => fs.readFileSync(path.join(app, 'web/js', n), 'utf8').replace(/<\/script/gi, '<\\/script');
const page = `<!doctype html><meta charset="utf-8"><body><div id="host"></div><pre id="out">running</pre>
<script>${js('type_tree.js')}</script><script>${js('type_chips.js')}</script>
<script>
var DOCS = ${JSON.stringify(docs).replace(/<\//g, '<\\/')};
var T = window.MSTypeTree, C = window.MSChips, res = [], n = 0, fail = 0, unsafe = 0;
function ok(c, l) { n++; if (!c) { fail++; res.push('FAIL  ' + l); } }
try {
  var B = {patient: 'P', age: 'A', amka: 'M', clinic: 'C', date: 'D'};
  DOCS.forEach(function (d) {
    var root = T.parse(d.text), known = Object.assign({}, B), bad = [];
    var fl = T.get(root, 'fields'); if (fl) fl.e.forEach(function (p) { known[p[0]] = T.sval(p[1], 'label'); });
    T.walk(root, function (node, p) {
      if (node.t !== 's' || p[p.length - 1] !== 'text') return;
      if (C.unsafe(node.v)) { unsafe++; return; }
      if (!C.roundTrips(document, node.v, known)) bad.push(JSON.stringify(node.v).slice(0, 80));
    });
    ok(!bad.length, d.name + ': every text round-trips through the contenteditable ' + bad.join(' | '));
  });
  // odd texts
  var k = {x: 'Χ'};
  ['a\\n\\nb', 'line\\n', '\\n', '@x\\n@x', '  two  spaces  ', 'trailing ', '<b>not html</b> & "q"', '{Ο|Η} \\\\@x @x_y **@x**'].forEach(function (t) {
    t = t.replace(/\\\\n/g, '\\n');
    ok(C.roundTrips(document, t, k), 'round trip ' + JSON.stringify(t));
  });
  ok(!C.roundTrips(document, 'a\\u00a0b', k), 'U+00A0 is refused (plain text)');
  // editing through the editor
  var host = document.getElementById('host'), got = null;
  var ed = C.editor(document, {text: 'Ημέρες @x.', known: k, id: 't1', onChange: function (t) { got = t; }});
  host.appendChild(ed.el); ed.focus();
  var ce = ed.el.querySelector('.te-ce');
  ok(ce.querySelector('.te-chip') && ce.querySelector('.te-chip').textContent === 'Χ' && ce.querySelector('.te-chip').getAttribute('data-tip') === '@x', 'a chip shows the label and carries the token');
  var r = document.createRange(); r.selectNodeContents(ce); r.collapse(false); var s = getSelection(); s.removeAllRanges(); s.addRange(r);
  ed.insertText(' Και '); ok(got === 'Ημέρες @x. Και ', 'insert text at the caret: ' + JSON.stringify(got));
  ed.insertChip('x'); ok(got === 'Ημέρες @x. Και @x', 'insert a chip: ' + JSON.stringify(got));
  ed.insertText('\\n'); ok(got === 'Ημέρες @x. Και @x\\n', 'Enter is a newline: ' + JSON.stringify(got));
  document.execCommand('insertText', false, 'τέλος'); ok(got === 'Ημέρες @x. Και @x\\nτέλος', 'typing (execCommand): ' + JSON.stringify(got));
  r = document.createRange(); r.selectNodeContents(ce); s.removeAllRanges(); s.addRange(r);
  ed.wrap('**', '**'); ok(got === '**Ημέρες @x. Και @x\\nτέλος**', 'bold wraps the selection, chips kept: ' + JSON.stringify(got));
  ok(!ed.isPlain(), 'still chips after the edits');
  // a browser-made element (as a paste of HTML or an Enter without our handler would make) turns the row plain, nothing written
  var before = got; var div = document.createElement('div'); div.textContent = 'x'; ce.appendChild(div); ce.dispatchEvent(new Event('input'));
  ok(ed.isPlain() && got === before, 'a foreign element: shown as plain text, nothing written');
  ed.el.querySelector('textarea').value = 'fixed @x'; ed.el.querySelector('textarea').dispatchEvent(new Event('input'));
  ok(got === 'fixed @x', 'editing the plain text writes it');
  // markup colours: spans, read back as text; the caret stays where it was after the re-colouring
  var got3 = null, ed3 = C.editor(document, {text: '**Μην** οδηγείτε {μόνος|μόνη} @x {v:L4-5}.', known: k, id: 't3', onChange: function (t) { got3 = t; }});
  host.appendChild(ed3.el);
  var ce3 = ed3.el.querySelector('.te-ce');
  ok(ce3.querySelector('.te-mk-gender') && ce3.querySelector('.te-mk-gender').textContent === '{μόνος|μόνη}' && ce3.querySelector('.te-mk-level')
     && ce3.querySelectorAll('.te-mk-mark').length === 2 && ce3.querySelector('.te-mk-b').textContent === 'Μην', 'markup is coloured; the text between ** is bold');
  ce3.focus(); C.placeAt(ce3, 8);   // after "**Μην** "
  document.execCommand('insertText', false, 'Χ'); document.execCommand('insertText', false, 'Ψ');
  ok(got3 === '**Μην** ΧΨοδηγείτε {μόνος|μόνη} @x {v:L4-5}.', 'typing in the middle keeps the caret through the re-colouring: ' + JSON.stringify(got3));
  var sl = getSelection(); ok(C.offsetOf(ce3, sl.focusNode, sl.focusOffset) === 10, 'caret offset after typing: ' + C.offsetOf(ce3, sl.focusNode, sl.focusOffset));
  C.placeAt(ce3, got3.length); document.execCommand('insertText', false, ' *τέλος*');
  ok(got3 === '**Μην** ΧΨοδηγείτε {μόνος|μόνη} @x {v:L4-5}. *τέλος*' && ce3.querySelector('.te-mk-i') && ce3.querySelector('.te-mk-i').textContent === 'τέλος', 'typed markup is coloured at once: ' + JSON.stringify(got3));
  ok(C.roundTrips(document, got3, k), 'the coloured text still round-trips');
  var wrapped = document.createElement('div'); wrapped.innerHTML = 'a<span style="font-weight:700">b<b>c</b></span>d';
  ok(C.read(wrapped).text === 'abcd' && !C.read(wrapped).strange, 'an inline wrapper from the browser is read as its text');
  var ed2 = C.editor(document, {text: 'odd\\u00a0space', known: k, id: 't2', onChange: function () {}});
  ok(ed2.isPlain() && /plain text/.test(ed2.el.querySelector('.te-plain-note').textContent), 'an unsafe text opens as plain text with the note');
} catch (e) { fail++; res.push('ERROR ' + e.message + ' ' + (e.stack || '')); }
document.getElementById('out').textContent = res.join('\\n') + '\\nRESULT ' + (n - fail) + '/' + n + ' unsafe=' + unsafe;
</script>`;
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'chipsdom-'));
try {
  const f = path.join(tmp, 'page.html'); fs.writeFileSync(f, page);
  const out = execFileSync(chrome, ['--headless', '--no-sandbox', '--disable-gpu', '--user-data-dir=' + path.join(tmp, 'prof'), '--virtual-time-budget=10000', '--dump-dom', 'file://' + f],
                           {encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], timeout: 60000, maxBuffer: 64 << 20});
  const m = /<pre id="out">([\s\S]*?)<\/pre>/.exec(out);
  const txt = m ? m[1].replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&amp;/g, '&') : 'no result';
  console.log(txt.trim());
  const r = /RESULT (\d+)\/(\d+)/.exec(txt);
  process.exit(r && r[1] === r[2] ? 0 : 1);
} finally { fs.rmSync(tmp, {recursive: true, force: true}); }
