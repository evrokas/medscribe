// Node test of the chip editor's core (web/js/type_chips.js) and the editor's naming rule (web/js/type_editor.js).
// The DOM half (build/read in a real contenteditable) is checked in Chromium by bin/check_chips_dom.js.
'use strict';
const fs = require('fs'), path = require('path');
const T = require(path.join(__dirname, '../../web/js/type_tree.js'));
const C = require(path.join(__dirname, '../../web/js/type_chips.js'));
const E = require(path.join(__dirname, '../../web/js/type_editor.js'));
let n = 0, fail = 0;
function ok(c, label) { n++; if (!c) { fail++; console.log('FAIL  ' + label); } }
const dir = path.join(__dirname, '../../types');
const files = fs.readdirSync(dir).filter(f => f.endsWith('.json')).map(f => path.join(dir, f))
  .concat(fs.readdirSync(path.join(dir, 'shared')).filter(f => f.endsWith('.json')).map(f => path.join(dir, 'shared', f)));
const builtins = {patient: 'P', age: 'A', amka: 'M', clinic: 'C', date: 'D'};
let texts = 0, chips = 0;
for (const f of files) {
  const root = T.parse(fs.readFileSync(f, 'utf8')), known = Object.assign({}, builtins);
  const fl = T.get(root, 'fields'); if (fl) fl.e.forEach(p => { known[p[0]] = T.sval(p[1], 'label'); });
  let bad = 0;
  T.walk(root, (node, p) => {
    if (node.t !== 's' || p[p.length - 1] !== 'text') return;
    texts++;
    const segs = C.split(node.v, known);
    chips += segs.filter(s => s.t === 'chip').length;
    if (C.join(segs) !== node.v) bad++;
    // every chip is a whole token the rename rule would also see
    segs.filter(s => s.t === 'chip').forEach(s => { if (!T.tokenRe(s.id).test(node.v)) bad++; });
  });
  ok(bad === 0, 'split/join is byte-identical for every text of ' + path.basename(f));
}
ok(texts > 400 && chips > 50, 'texts seen: ' + texts + ', chips: ' + chips);
const k = {x: 'X', x_longer: 'XL'};
const cases = {
  '\\@x': [], '@x_longer': ['x_longer'], 'x@x': [], '@x.': ['x'], '{μόνος|μόνη} @x': ['x'], '**@x**': ['x'], '@x{br}@x': ['x', 'x'],
  'α@x': [], '@xy': [], '@x @y': ['x'], '@@x': [], 'a\\ b @x ': ['x']
};
for (const t of Object.keys(cases)) {
  const segs = C.split(t, k);
  ok(C.join(segs) === t, 'join(split) ' + JSON.stringify(t));
  ok(JSON.stringify(segs.filter(s => s.t === 'chip').map(s => s.id)) === JSON.stringify(cases[t]), 'chips of ' + JSON.stringify(t) + ': ' + JSON.stringify(segs));
}
ok(JSON.stringify(C.unknownTokens('@x @y \\@z @y', k)) === '["y"]', 'unknown tokens are named once; escaped ones are not tokens');
ok(C.unsafe('a b') && C.unsafe('a\r\nb') && C.unsafe('a​b') && !C.unsafe('a\nb\n\nc') && !C.unsafe('Ελληνικά «» – …'), 'unsafe characters');
// the naming rule (plan §3): label, heading, then what the section holds
const kyf = T.parse(fs.readFileSync(path.join(dir, 'odigies-kyfoplastiki.json'), 'utf8'));
const names = T.sections(kyf).map(s => E.sectionName(s, kyf, {}));
ok(names.length === 10 && names.every(x => x && !/^import\./.test(x)), 'every section of kyfoplastiki has a readable name');
ok(names[0].indexOf('Patient info') === 0 && names[2] === 'Γενικές Οδηγίες' && /^«.+»$/.test(names[1]), 'patient info, heading, first sentence: ' + names.slice(0, 3).join(' | '));
const mk = o => T.parse(JSON.stringify(o));
ok(E.sectionName(mk({label: 'L', heading: 'H'}), kyf, {}) === 'L', 'the label wins over the heading (as on the form)');
ok(E.sectionName(mk({use: 'purpose', layout: 'paragraphs'}), kyf, {purpose: 'Σκοπός'}) === 'Shared: Σκοπός', 'a shared section');
ok(E.sectionName(mk({layout: 'medtable'}), kyf, {}) === 'Medication table', 'a medication table');
ok(E.sectionName(mk({layout: 'itemize', blocks: [{text: '**Μην** οδηγείτε {μόνος|μόνη} @surgery_date.'}]}), kyf, {}) === '«Μην οδηγείτε μόνος [Ημ. χειρουργείου].»', 'markup stripped, fields by label');
ok(E.sectionName(mk({layout: 'paragraphs', blocks: []}), kyf, {}) === 'paragraphs section', 'an empty section');
console.log(fail ? fail + ' of ' + n + ' chip checks FAILED' : n + '/' + n + ' chip checks passed');
process.exit(fail ? 1 : 0);
