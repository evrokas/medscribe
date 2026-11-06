/*
 * Tree editor for type definitions (/types/{kind}/{name}): every key of the JSON is a row that can be
 * edited, renamed, moved (drag the handle or use the arrows), duplicated or deleted. It edits the same
 * JSON as the text box underneath; saving still goes through the server's checks and versioning.
 *
 * Renames that mean something to the engine are carried everywhere the name is used:
 *   a field (key under "fields")  -> every @field in the texts, feature operations {field}
 *   a block id                    -> feature operations {block}
 *   a section id                  -> feature operations {section}
 *   a choice group (block "group")-> every block of that group
 *   a shared component (key under "shared") -> sections' "use" / "library"
 *
 * The JSON is parsed with its own small parser so the order of keys is kept exactly (JSON.parse would
 * move keys that look like numbers to the front). Node: {t: 'o'|'a'|'s'|'n'|'b'|'z', e | v}; objects keep
 * [key, node] pairs in order. Properties starting with "_" are UI state and are never written out.
 */
(function () {
  'use strict';

  var state = {root: null, host: null, status: null, drag: null, taken: {}};
  var BUILTINS = ['patient', 'age', 'amka', 'clinic', 'date'];
  var ENUMS = {
    lang: ['gr', 'en'], paper: ['a4', 'a5'], stamp: ['yes', 'no'],
    layout: ['paragraphs', 'itemize', 'enumerate', 'description', 'patient-info', 'urgentbox', 'medtable', 'topics'],
    style: ['itemize', 'paragraph'], heading_level: ['section', 'subsection'], format: ['markup', 'latex'],
    op: ['enable', 'disable', 'set', 'add']
  };
  var LONG = ['text', 'intro', 'placeholder', 'list_preamble', 'title_page'];

  // ---------- JSON with key order ----------
  function parse(s) {
    var i = 0;
    var reStr = /"(?:[^"\\\u0000-\u001f]|\\(?:["\\\/bfnrt]|u[0-9a-fA-F]{4}))*"/y;
    var reNum = /-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/y;
    function err(m) { var line = s.slice(0, i).split('\n').length; throw new Error(m + ' (line ' + line + ')'); }
    function ws() { while (i < s.length && ' \t\n\r'.indexOf(s[i]) >= 0) i++; }
    function str() { reStr.lastIndex = i; var m = reStr.exec(s); if (!m) err('bad string'); i = reStr.lastIndex; return JSON.parse(m[0]); }
    function val() {
      ws(); var c = s[i], e;
      if (c === '{') {
        i++; e = []; ws(); if (s[i] === '}') { i++; return {t: 'o', e: e}; }
        for (;;) {
          ws(); if (s[i] !== '"') err('a key in quotes expected');
          var k = str(); ws(); if (s[i++] !== ':') err('":" expected');
          e.push([k, val()]); ws();
          if (s[i] === ',') { i++; continue; } if (s[i] === '}') { i++; return {t: 'o', e: e}; }
          err('"," or "}" expected');
        }
      }
      if (c === '[') {
        i++; e = []; ws(); if (s[i] === ']') { i++; return {t: 'a', e: e}; }
        for (;;) {
          e.push(val()); ws();
          if (s[i] === ',') { i++; continue; } if (s[i] === ']') { i++; return {t: 'a', e: e}; }
          err('"," or "]" expected');
        }
      }
      if (c === '"') return {t: 's', v: str()};
      if (s.startsWith('true', i)) { i += 4; return {t: 'b', v: true}; }
      if (s.startsWith('false', i)) { i += 5; return {t: 'b', v: false}; }
      if (s.startsWith('null', i)) { i += 4; return {t: 'z'}; }
      reNum.lastIndex = i; var m = reNum.exec(s); if (m) { i = reNum.lastIndex; return {t: 'n', v: m[0]}; }
      err('a value expected');
    }
    var root = val(); ws(); if (i < s.length) err('text after the end');
    return root;
  }
  function ser(n, ind) {
    ind = ind || ''; var ni = ind + '  ';
    switch (n.t) {
      case 'o': return n.e.length ? '{\n' + n.e.map(function (p) { return ni + JSON.stringify(p[0]) + ': ' + ser(p[1], ni); }).join(',\n') + '\n' + ind + '}' : '{}';
      case 'a': return n.e.length ? '[\n' + n.e.map(function (x) { return ni + ser(x, ni); }).join(',\n') + '\n' + ind + ']' : '[]';
      case 's': return JSON.stringify(n.v);
      case 'n': return n.v;
      case 'b': return n.v ? 'true' : 'false';
      default: return 'null';
    }
  }
  function get(n, k) { if (!n || n.t !== 'o') return null; for (var j = 0; j < n.e.length; j++) if (n.e[j][0] === k) return n.e[j][1]; return null; }
  function sval(n, k) { var x = get(n, k); return x && x.t === 's' ? x.v : ''; }
  function clone(n) { var c = {t: n.t}; if (n.t === 'o') c.e = n.e.map(function (p) { return [p[0], clone(p[1])]; }); else if (n.t === 'a') c.e = n.e.map(clone); else c.v = n.v; return c; }
  function walk(n, fn, path) {
    path = path || []; fn(n, path);
    if (n.t === 'o') n.e.forEach(function (p) { walk(p[1], fn, path.concat([p[0]])); });
    else if (n.t === 'a') n.e.forEach(function (x, j) { walk(x, fn, path.concat([j])); });
  }

  // ---------- what a node is ----------
  function kindOf(path) {
    var L = path.length, last = path[L - 1], prev = path[L - 2];
    if (L === 2 && path[0] === 'sections' && typeof last === 'number') return 'section';
    if (typeof last === 'number' && prev === 'blocks' && path[0] === 'sections') return 'block';
    if (typeof last === 'number' && prev === 'blocks' && L === 2) return 'block';           // a shared component
    if (typeof last === 'number' && prev === 'topics') return 'topic';
    if (L === 2 && path[0] === 'fields') return 'field';
    if (L === 2 && path[0] === 'features') return 'feature';
    if (L === 2 && path[0] === 'shared') return 'shared';
    if (typeof last === 'number' && prev === 'ops') return 'op';
    return '';
  }
  function sections(root) { var s = get(root, 'sections'); return s && s.t === 'a' ? s.e : []; }
  function allBlocks(root) {
    var out = [];
    walk(root, function (n, p) { if (kindOf(p) === 'block' && n.t === 'o') out.push(n); });
    return out;
  }
  function allOps(root) {
    var out = [], f = get(root, 'features');
    if (f && f.t === 'o') f.e.forEach(function (p) { var ops = get(p[1], 'ops'); if (ops && ops.t === 'a') ops.e.forEach(function (o) { if (o.t === 'o') out.push(o); }); });
    return out;
  }
  function allIds(root) { var ids = {}; walk(root, function (n, p) { if (p[p.length - 1] === 'id' && n.t === 's') ids[n.v] = true; }); return ids; }
  function tokenRe(name) {
    return new RegExp('(?<![\\\\\\p{L}\\p{N}_@])@' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![a-z0-9_])', 'gu');
  }
  function fieldUses(root, name) {
    var re = tokenRe(name), c = 0;
    walk(root, function (n) { if (n.t === 's') { var m = n.v.match(re); if (m) c += m.length; } });
    allOps(root).forEach(function (o) { if (sval(o, 'field') === name) c++; });
    return c;
  }
  function snippet(s, n) { s = (s || '').replace(/\s+/g, ' ').trim(); return s.length > n ? s.slice(0, n) + '…' : s; }
  function hint(n, path) {
    switch (kindOf(path)) {
      case 'section':
        var form = sval(n, 'label') || sval(n, 'heading') || sval(n, 'slot');
        return sval(n, 'id') + ' · on the form: «' + form + '» · ' + sval(n, 'layout');
      case 'block':
        var d = get(n, 'default'), g = sval(n, 'group');
        return sval(n, 'id') + (d && d.t === 'b' && !d.v ? ' (off)' : '') + (g ? ' [group ' + g + ']' : '') + ' · ' + snippet(sval(n, 'text'), 70);
      case 'topic': return sval(n, 'id') + ' · ' + sval(n, 'title');
      case 'field': return sval(n, 'label') + ' · ' + sval(n, 'type') + ' · used ' + fieldUses(state.root, path[1]) + '×';
      case 'feature': return sval(n, 'label');
      case 'op': return sval(n, 'op') + ' ' + (sval(n, 'block') || sval(n, 'field') || sval(n, 'section'));
    }
    if (n.t === 'a') return n.e.length + ' item' + (n.e.length === 1 ? '' : 's');
    if (n.t === 'o') return n.e.length + ' key' + (n.e.length === 1 ? '' : 's');
    return '';
  }

  // ---------- renames that carry ----------
  function ask(msg, def) { var v = window.prompt(msg, def); return v === null ? null : v.trim(); }

  function renameField(root, oldK, newK) {
    var re = tokenRe(oldK), c = 0;
    walk(root, function (n) { if (n.t === 's') n.v = n.v.replace(re, function () { c++; return '@' + newK; }); });
    allOps(root).forEach(function (o) { var f = get(o, 'field'); if (f && f.v === oldK) { f.v = newK; c++; } });
    return c;
  }
  function renameRef(root, key, oldV, newV) {
    var c = 0;
    allOps(root).forEach(function (o) { var x = get(o, key); if (x && x.t === 's' && x.v === oldV) { x.v = newV; c++; } });
    return c;
  }
  function renameKey(obj, path, idx) {
    var oldK = obj.e[idx][0], ctx = path.length === 1 ? path[0] : '';
    var newK = ask('New name for "' + oldK + '":', oldK);
    if (!newK || newK === oldK) return;
    if (obj.e.some(function (p) { return p[0] === newK; })) { alert('"' + newK + '" already exists here.'); return; }
    var note = '';
    if (ctx === 'fields') {
      if (!/^[a-z][a-z0-9_]*$/.test(newK)) { alert('A field name must start with a small Latin letter and use only a-z, 0-9 and _ (it is written @' + newK + ' in the texts).'); return; }
      if (BUILTINS.indexOf(newK) >= 0) { alert('@' + newK + ' is a built-in value.'); return; }
      var n = fieldUses(state.root, oldK);
      if (n && !confirm('Rename field "' + oldK + '" to "' + newK + '"?\n' + n + ' use(s) (@' + oldK + ' in texts, feature operations) change with it.')) return;
      note = renameField(state.root, oldK, newK) + ' use(s) updated';
    } else if (ctx === 'shared') {
      var c = 0;
      sections(state.root).forEach(function (s) { ['use', 'library'].forEach(function (k) { var x = get(s, k); if (x && x.v === oldK) { x.v = newK; c++; } }); });
      note = c + ' section(s) updated';
    }
    obj.e[idx][0] = newK;
    changed(note);
  }
  function renameId(item, path) {
    var kind = kindOf(path), idn = get(item, 'id'), oldV = idn.v;
    var newV = ask('New id for ' + kind + ' "' + oldV + '":', oldV);
    if (!newV || newV === oldV) return;
    if (allIds(state.root)[newV]) { alert('The id "' + newV + '" is already used.'); return; }
    idn.v = newV;
    var c = kind === 'block' ? renameRef(state.root, 'block', oldV, newV) : kind === 'section' ? renameRef(state.root, 'section', oldV, newV) : 0;
    changed(c ? c + ' feature operation(s) updated' : '');
  }
  function renameGroup(item) {
    var g = get(item, 'group'), oldV = g.v;
    var newV = ask('New name for choice group "' + oldV + '" (every block of the group follows):', oldV);
    if (!newV || newV === oldV) return;
    var c = 0;
    allBlocks(state.root).forEach(function (b) { var x = get(b, 'group'); if (x && x.v === oldV) { x.v = newV; c++; } });
    changed(c + ' block(s) updated');
  }

  // ---------- new items ----------
  function nextId(old, fallback) {
    var m = /^(.*?)(\d+)$/.exec(old || ''), prefix = m ? m[1] : fallback, ids = Object.assign(allIds(state.root), state.taken), max = 0;
    Object.keys(ids).forEach(function (id) { if (id.indexOf(prefix) === 0) { var r = id.slice(prefix.length); if (/^\d+$/.test(r)) max = Math.max(max, +r); } });
    return prefix + (max + 1);
  }
  function freshIds(n) {   // a copy gets new ids (and nothing else changes)
    walk(n, function (x, p) { if (p[p.length - 1] === 'id' && x.t === 's') { x.v = nextId(x.v, 'x'); state.taken[x.v] = 1; } });
  }
  function blankLike(n) {
    var c = {t: n.t};
    if (n.t === 'o') c.e = n.e.map(function (p) {
      var keep = p[1].t === 's' && (ENUMS[p[0]] || p[0] === 'type');
      return [p[0], keep ? clone(p[1]) : blankLike(p[1])];
    });
    else if (n.t === 'a') c.e = [];
    else if (n.t === 's') c.v = '';
    else c.v = n.v;
    return c;
  }
  function o(pairs) { return {t: 'o', e: pairs}; }
  function s(v) { return {t: 's', v: v}; }
  function newItem(arr, path) {
    var last = arr.e[arr.e.length - 1], key = path[path.length - 1], item;
    if (last) {
      item = blankLike(last);
      if (get(item, 'id')) get(item, 'id').v = nextId(get(last, 'id').v, 'x');
      if (get(item, 'default') && get(item, 'default').t === 'b') get(item, 'default').v = true;
      if (kindOf(path.concat([0])) === 'section' && get(item, 'slot')) get(item, 'slot').v = sval(state.root, 'name') + '.' + sval(item, 'id');
      return item;
    }
    if (key === 'blocks') return o([['id', s(nextId('', (path[0] === 'sections' ? (sval(sections(state.root)[path[1]], 'id') || 's') + '_b' : 'b')))], ['text', s('')], ['default', {t: 'b', v: true}]]);
    if (key === 'topics') return o([['id', s(nextId('', 't'))], ['title', s('')], ['style', s('itemize')], ['blocks', {t: 'a', e: []}]]);
    if (key === 'sections') { var id = nextId('', 's'); return o([['id', s(id)], ['slot', s(sval(state.root, 'name') + '.' + id)], ['layout', s('paragraphs')], ['blocks', {t: 'a', e: []}]]); }
    if (key === 'ops') return o([['op', s('enable')], ['block', s('')]]);
    return valueOfType(ask('Type of the new item: text, number, yes/no, object, list', 'text'));
  }
  function valueOfType(t) {
    if (t === null) return null;
    t = (t || 'text').toLowerCase();
    if (t.indexOf('num') === 0) return {t: 'n', v: '0'};
    if (t.indexOf('yes') === 0 || t === 'bool' || t === 'true') return {t: 'b', v: true};
    if (t.indexOf('obj') === 0) return {t: 'o', e: []};
    if (t.indexOf('list') === 0 || t === 'array') return {t: 'a', e: []};
    return s('');
  }
  function addKey(obj, path) {
    var ctx = path.length === 1 ? path[0] : '';
    var k = ask(ctx === 'fields' ? 'Name of the new field (written @name in the texts):' : 'Name of the new key:', '');
    if (!k) return;
    if (obj.e.some(function (p) { return p[0] === k; })) { alert('"' + k + '" already exists here.'); return; }
    var v;
    if (ctx === 'fields') {
      if (!/^[a-z][a-z0-9_]*$/.test(k) || BUILTINS.indexOf(k) >= 0) { alert('Use a-z, 0-9 and _, starting with a letter, and not a built-in name (' + BUILTINS.join(', ') + ').'); return; }
      v = o([['type', s('text')], ['label', s('')], ['placeholder', s('')]]);
    } else if (ctx === 'features') v = o([['label', s('')], ['ops', {t: 'a', e: []}]]);
    else if (ENUMS[k]) v = s(ENUMS[k][0]);
    else v = valueOfType(ask('Type of "' + k + '": text, number, yes/no, object, list', 'text'));
    if (!v) return;
    obj.e.push([k, v]); v._open = true; obj._open = true;
    changed();
  }

  // ---------- rendering ----------
  function changed(note) { render(); sync(); if (note) say(note); }
  function say(msg) { if (state.status) { state.status.textContent = msg; clearTimeout(state.t); state.t = setTimeout(function () { state.status.textContent = ''; }, 6000); } }
  function sync() { state.textarea.value = ser(state.root) + '\n'; }
  function el(tag, cls, text) { var x = document.createElement(tag); if (cls) x.className = cls; if (text !== undefined) x.textContent = text; return x; }
  function btn(label, title, fn) { var b = el('button', 'tt-btn', label); b.type = 'button'; b.title = title; b.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); fn(); }); return b; }

  function render() {
    var scroll = window.scrollY;
    state.host.textContent = '';
    state.host.appendChild(member(state.root, [], null, null));
    window.scrollTo(0, scroll);
  }

  function editor(n, key, path) {
    if (n.t === 'b') {
      var cb = el('input'); cb.type = 'checkbox'; cb.checked = n.v;
      cb.addEventListener('change', function () { n.v = cb.checked; sync(); refreshHints(); });
      return cb;
    }
    if (n.t === 'n') {
      var num = el('input', 'tt-num'); num.type = 'number'; num.step = 'any'; num.value = n.v;
      num.addEventListener('input', function () { if (num.value !== '' && isFinite(+num.value)) { n.v = String(+num.value); sync(); } });
      return num;
    }
    if (n.t === 'z') return el('span', 'muted', 'null');
    var opts = ENUMS[key] || (key === 'type' && path[0] === 'fields' ? ['text', 'date', 'number'] : null);
    if (opts) {
      var sel = el('select');
      opts.concat(opts.indexOf(n.v) < 0 ? [n.v] : []).forEach(function (v) { var op = el('option', '', v); op.value = v; sel.appendChild(op); });
      sel.value = n.v;
      sel.addEventListener('change', function () { n.v = sel.value; sync(); refreshHints(); });
      return sel;
    }
    var big = LONG.indexOf(key) >= 0 || n.v.length > 60 || n.v.indexOf('\n') >= 0;
    var inp = el(big ? 'textarea' : 'input', 'tt-str');
    if (big) { inp.rows = Math.min(12, Math.max(2, Math.ceil(n.v.length / 90) + (n.v.match(/\n/g) || []).length)); } else inp.type = 'text';
    inp.value = n.v; inp.spellcheck = false;
    inp.addEventListener('input', function () { n.v = inp.value; sync(); });
    inp.addEventListener('change', refreshHints);
    return inp;
  }

  // the hints in the summaries follow the edits without redrawing (redrawing would drop the focus)
  function refreshHints() {
    state.host.querySelectorAll('.tt-hint').forEach(function (h) { if (h._node) h.textContent = hint(h._node, h._path); });
  }

  function member(n, path, parent, idx) {
    var isRoot = !parent, key = path[path.length - 1];
    var row = el('div', 'tt-row tt-' + n.t);
    var head = el('div', 'tt-head');
    if (!isRoot) {
      var handle = el('span', 'tt-handle', '⠿'); handle.title = 'Drag to move'; handle.draggable = true;
      handle.addEventListener('dragstart', function (e) { state.drag = {parent: parent, idx: idx}; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(key)); row.classList.add('tt-dragging'); });
      handle.addEventListener('dragend', function () { row.classList.remove('tt-dragging'); state.drag = null; });
      head.addEventListener('dragover', function (e) { if (state.drag && state.drag.parent === parent && state.drag.idx !== idx) { e.preventDefault(); row.classList.add('tt-over'); } });
      head.addEventListener('dragleave', function () { row.classList.remove('tt-over'); });
      head.addEventListener('drop', function (e) {
        e.preventDefault(); row.classList.remove('tt-over');
        var d = state.drag; if (!d || d.parent !== parent) return;
        var moved = parent.e.splice(d.idx, 1)[0]; parent.e.splice(idx, 0, moved); changed();
      });
      head.appendChild(handle);
    }
    var isContainer = n.t === 'o' || n.t === 'a';
    if (isContainer) {
      if (n._open === undefined) n._open = path.length === 0 || (path.length === 1 && ['fields', 'sections', 'features'].indexOf(path[0]) >= 0);
      var tog = el('span', 'tt-toggle', n._open ? '▾' : '▸'); head.appendChild(tog);
      head.classList.add('tt-click');
      head.addEventListener('click', function (e) { if (e.target.closest('input,select,textarea,button')) return; n._open = !n._open; changed(); });
    }
    if (!isRoot) {
      var name = el('span', 'tt-key ' + (typeof key === 'number' ? 'tt-idx' : ''), typeof key === 'number' ? '#' + (key + 1) : key);
      head.appendChild(name);
    } else head.appendChild(el('span', 'tt-key', 'type definition'));
    if (isContainer) { var h = el('span', 'tt-hint', hint(n, path)); h._node = n; h._path = path; head.appendChild(h); }
    else {
      var ed = editor(n, key, path); head.appendChild(ed);
      // ids, choice groups: the value is renamed with its references, not typed over
      var pk = kindOf(path.slice(0, -1));
      if (key === 'id' && (pk === 'block' || pk === 'section' || pk === 'topic')) {
        ed.readOnly = true; ed.classList.add('tt-ro');
        head.appendChild(btn('✎', 'Rename this id (feature operations follow)', function () { renameId(parent, path.slice(0, -1)); }));
      }
      if (key === 'group' && pk === 'block' && n.v) head.appendChild(btn('✎ all', 'Rename this choice group in every block', function () { renameGroup(parent); }));
    }
    if (!isRoot) {
      var tools = el('span', 'tt-tools');
      if (parent.t === 'o') tools.appendChild(btn('✎', 'Rename this key' + (path.length === 2 && path[0] === 'fields' ? ' (every @' + key + ' follows)' : ''), function () { renameKey(parent, path.slice(0, -1), idx); }));
      tools.appendChild(btn('↑', 'Move up', function () { if (idx > 0) { var x = parent.e.splice(idx, 1)[0]; parent.e.splice(idx - 1, 0, x); changed(); } }));
      tools.appendChild(btn('↓', 'Move down', function () { if (idx < parent.e.length - 1) { var x = parent.e.splice(idx, 1)[0]; parent.e.splice(idx + 1, 0, x); changed(); } }));
      if (parent.t === 'a' && isContainer) tools.appendChild(btn('⧉', 'Duplicate (the copy gets new ids)', function () { var c = clone(n); state.taken = {}; freshIds(c); c._open = true; parent.e.splice(idx + 1, 0, c); changed(); }));
      tools.appendChild(btn('✕', 'Delete', function () {
        var what = typeof key === 'number' ? 'item #' + (key + 1) : '"' + key + '"';
        if (path.length === 2 && path[0] === 'fields') { var u = fieldUses(state.root, key); if (u) what += ' (still used ' + u + '×)'; }
        if (confirm('Delete ' + what + '?')) { parent.e.splice(idx, 1); changed(); }
      }));
      head.appendChild(tools);
    }
    row.appendChild(head);
    if (isContainer && n._open) {
      var body = el('div', 'tt-body');
      if (n.t === 'o') n.e.forEach(function (p, j) { body.appendChild(member(p[1], path.concat([p[0]]), n, j)); });
      else n.e.forEach(function (x, j) { body.appendChild(member(x, path.concat([j]), n, j)); });
      body.appendChild(btn(n.t === 'o' ? (path.length === 1 && path[0] === 'fields' ? '+ field' : '+ key') : '+ item', 'Add', function () {
        if (n.t === 'o') addKey(n, path);
        else { var it = newItem(n, path); if (it) { it._open = true; n.e.push(it); changed(); } }
      }));
      row.appendChild(body);
    }
    return row;
  }

  // ---------- page wiring ----------
  function init() {
    var ta = document.querySelector('.type-edit textarea[name=text]');
    if (!ta) return;
    var form = ta.form, KEY = 'medscribe.typeeditor.view';
    var bar = el('div', 'tt-tabs'), host = el('div', 'tt-tree'), status = el('span', 'tt-status');
    var bTree = btn('Tree', 'Edit as a tree', function () { show('tree'); }), bJson = btn('JSON', 'Edit the JSON text', function () { show('json'); });
    bar.appendChild(bTree); bar.appendChild(bJson); bar.appendChild(status);
    ta.parentNode.insertBefore(bar, ta); ta.parentNode.insertBefore(host, ta);
    state.host = host; state.status = status; state.textarea = ta;
    function show(view) {
      if (view === 'tree') {
        try { state.root = parse(ta.value); } catch (e) { alert('The JSON has an error, fix it first: ' + e.message); view = 'json'; }
      }
      if (view === 'tree') { render(); host.hidden = false; ta.hidden = true; } else { host.hidden = true; ta.hidden = false; }
      bTree.classList.toggle('active', view === 'tree'); bJson.classList.toggle('active', view === 'json');
      try { localStorage.setItem(KEY, view); } catch (e) {}
    }
    var start = 'tree'; try { start = localStorage.getItem(KEY) || 'tree'; } catch (e) {}
    show(start);
    // the textarea is always kept in step, so saving needs nothing more; this only guards a stale edit
    form.addEventListener('submit', function () { if (!host.hidden && state.root) sync(); });
  }
  if (typeof document === 'undefined') { /* node */ }
  else if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();

  // for tests (node): round-trip and renames
  if (typeof module !== 'undefined') module.exports = {parse: parse, ser: ser, renameField: renameField, tokenRe: tokenRe};
})();
