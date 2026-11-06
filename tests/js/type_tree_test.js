// Node test of web/js/type_tree.js: every type file survives the tree editor unchanged (values and key
// order), and renaming a field carries @tokens and feature operations. Run by tests/static/type_tree_test.php.
'use strict';
const fs = require('fs'), path = require('path');
const tt = require(path.join(__dirname, '../../web/js/type_tree.js'));
let n = 0, fail = 0;
function ok(c, label) { n++; if (!c) { fail++; console.log('FAIL  ' + label); } }
const dir = path.join(__dirname, '../../types');
const files = fs.readdirSync(dir).filter(f => f.endsWith('.json')).map(f => path.join(dir, f))
  .concat(fs.readdirSync(path.join(dir, 'shared')).filter(f => f.endsWith('.json')).map(f => path.join(dir, 'shared', f)));
for (const f of files) {
  const text = fs.readFileSync(f, 'utf8');
  const out = tt.ser(tt.parse(text));
  ok(out === JSON.stringify(JSON.parse(text), null, 2), 'round trip ' + path.basename(f));
}
// key order is kept even for keys that look like numbers (JSON.parse would move them first)
ok(tt.ser(tt.parse('{"b":1,"2":2}')) === '{\n  "b": 1,\n  "2": 2\n}', 'numeric-looking keys keep their place');
// a field rename
const root = tt.parse(JSON.stringify({fields: {f3: {type: 'text'}, f30: {type: 'text'}},
  sections: [{blocks: [{text: 'a @f3, b @f30, c \\@f3, d x@f3, e @f3_x @f3.'}]}],
  features: {k: {ops: [{op: 'set', field: 'f3', value: '1'}, {op: 'set', field: 'f30', value: '2'}]}}}));
const c = tt.renameField(root, 'f3', 'proc');
const j = JSON.parse(tt.ser(root));
ok(j.sections[0].blocks[0].text === 'a @proc, b @f30, c \\@f3, d x@f3, e @f3_x @proc.', 'rename: only whole, unescaped @f3 tokens');
ok(j.features.k.ops[0].field === 'proc' && j.features.k.ops[1].field === 'f30', 'rename: feature operations');
ok(c === 3, 'rename: count of uses (' + c + ')');
let bad = false; try { tt.parse('{"a": 1,}'); } catch (e) { bad = /line 1/.test(e.message); }
ok(bad, 'a JSON error names its line');
console.log((n - fail) + '/' + n + ' type tree checks passed');
process.exit(fail ? 1 : 0);
