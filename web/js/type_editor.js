/*
 * The type editor's shell (docs/plans/type-editor-ui.md §3): tabs over one definition, the unsaved marker, the Save
 * dialog, and the Structure tab. The Content and Fields tabs register themselves from type_content.js / type_fields.js.
 *
 * One source of truth: the form's textarea[name=text]. A tab parses it when it is shown (type_tree.js's parser, which
 * keeps the key order), edits that tree, and writes it back with commit(). The JSON tab is the old editor (tree + text).
 * Nothing here decides validity: the server's checks do, on save.
 */
(function () {
  'use strict';
  var T, ed = {ta: null, root: null, tab: '', views: {}, order: [], panels: {}, buttons: {}, baseline: '', name: '', kind: '', sharedLabels: {}};

  // ---------- small helpers on the tree nodes ----------
  function el(tag, cls, text) { var x = document.createElement(tag); if (cls) x.className = cls; if (text !== undefined) x.textContent = text; return x; }
  function button(label, title, fn, cls) {
    var b = el('button', 'te-btn' + (cls ? ' ' + cls : ''), label); b.type = 'button'; if (title) b.title = title;
    b.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); fn(e); });
    return b;
  }
  function S(v) { return {t: 's', v: v}; }
  function arr(n) { return n && n.t === 'a' ? n.e : []; }
  function keyIndex(obj, k) { for (var j = 0; j < obj.e.length; j++) if (obj.e[j][0] === k) return j; return -1; }
  // set a key; a new key goes before the object's lists (blocks/topics/ops) so the JSON reads in a natural order
  function setKey(obj, k, node) {
    var j = keyIndex(obj, k);
    if (j >= 0) { obj.e[j][1] = node; return; }
    var at = obj.e.length;
    ['blocks', 'topics', 'ops', 'default_rows'].forEach(function (lk) { var i = keyIndex(obj, lk); if (i >= 0 && i < at) at = i; });
    obj.e.splice(at, 0, [k, node]);
  }
  function delKey(obj, k) { var j = keyIndex(obj, k); if (j >= 0) obj.e.splice(j, 1); }
  function bval(n, k) { var x = T.get(n, k); return !!(x && x.t === 'b' && x.v); }

  // the text of a block without its markup, for names and lists
  function plainText(s, fields) {
    return String(s || '')
      .replace(/\{v:([^}]*)\}/g, '$1').replace(/\{br\}/g, ' ').replace(/\{([^{}|]*)\|[^{}]*\}/g, '$1')
      .replace(/\*\*|\*/g, '').replace(/\\ /g, ' ').replace(/\\([@*{}])/g, '$1')
      .replace(/(^|[^\\\p{L}\p{N}_@])@([a-z][a-z0-9_]*)/gu, function (m, a, id) { return a + (fields && fields[id] ? '[' + fields[id] + ']' : '@' + id); })
      .replace(/\s+/g, ' ').trim();
  }
  function cut(s, n) { return s.length > n ? s.slice(0, n - 1).replace(/\s+\S*$/, '') + '…' : s; }
  function fieldLabels(root) { var out = {}; (T.get(root, 'fields') || {e: []}).e.forEach(function (p) { out[p[0]] = T.sval(p[1], 'label') || p[0]; }); return out; }

  /*
   * The name of a section wherever the editor lists one (plan §3): its name on the composer's form (label, else heading:
   * the composer's own rule, so the two never disagree), else a description of what it holds.
   */
  function sectionName(sec, root, sharedLabels) {
    var label = T.sval(sec, 'label'), heading = T.sval(sec, 'heading'), layout = T.sval(sec, 'layout');
    if (label) return label;
    if (heading) return heading;
    var use = T.sval(sec, 'use');
    if (use) return 'Shared: ' + ((sharedLabels || {})[use] || use);
    if (layout === 'medtable') return T.sval(sec, 'title_page') || 'Medication table';
    var blocks = arr(T.get(sec, 'blocks')), f = fieldLabels(root);
    if (layout === 'patient-info') {
      var rows = blocks.map(function (b) { return T.sval(b, 'label'); }).filter(Boolean);
      return cut('Patient info' + (rows.length ? ' · ' + rows.join(', ') : ''), 60);
    }
    if (layout === 'topics') {
      var t = arr(T.get(sec, 'topics'));
      if (t.length) return cut('Topics: ' + t.map(function (x) { return T.sval(x, 'title'); }).filter(Boolean).join(', '), 60);
    }
    for (var j = 0; j < blocks.length; j++) {
      var txt = plainText(T.sval(blocks[j], 'text'), f);
      if (txt && T.sval(blocks[j], 'format') !== 'latex') return '«' + cut(txt, 45) + '»';
    }
    return (layout || 'empty') + ' section';
  }
  var ICONS = {paragraphs: '¶', itemize: '•', enumerate: '1.', description: '≡', 'patient-info': '◍', urgentbox: '!', medtable: '℞', topics: '☰'};
  function sectionMeta(sec) {
    var layout = T.sval(sec, 'layout'), parts = [layout];
    if (layout === 'topics') { var n = arr(T.get(sec, 'topics')).length; parts.push(n + ' topic' + (n === 1 ? '' : 's')); }
    else if (T.sval(sec, 'library')) parts.push(T.sval(sec, 'library'));
    else if (T.sval(sec, 'use')) parts.push('shared');
    else { var b = arr(T.get(sec, 'blocks')).length; parts.push(b + ' block' + (b === 1 ? '' : 's')); }
    if (bval(sec, 'optional')) parts.push('optional');
    return parts.join(' · ');
  }

  // ---------- the shell ----------
  function canonical(text) { try { return T.ser(T.parse(text)); } catch (e) { return text; } }
  function load() {
    try { ed.root = T.parse(ed.ta.value); return null; } catch (e) { ed.root = null; return e.message; }
  }
  // a view changed the tree: write it to the text (the preview and the unsaved marker follow)
  function commit(note) {
    ed.ta.value = T.ser(ed.root) + '\n';
    ed.ta.dispatchEvent(new Event('tt-change'));
    markUnsaved();
    if (note) say(note);
  }
  function markUnsaved() {
    var dirty = canonical(ed.ta.value) !== ed.baseline;
    document.querySelectorAll('.te-unsaved').forEach(function (x) { x.hidden = !dirty; });
    ed.dirty = dirty;
  }
  function say(msg) {
    var s = document.querySelector('.te-say'); if (!s) return;
    s.textContent = msg; clearTimeout(ed.sayT); ed.sayT = setTimeout(function () { s.textContent = ''; }, 6000);
  }
  function storeKey(what) { return 'medscribe.te.' + ed.kind + '.' + ed.name + '.' + what; }
  function remember(what, v) { try { localStorage.setItem(storeKey(what), JSON.stringify(v)); } catch (e) {} }
  function recall(what) { try { return JSON.parse(localStorage.getItem(storeKey(what)) || 'null'); } catch (e) { return null; } }

  function register(name, view) { ed.views[name] = view; }

  function show(name, sel) {
    if (!ed.views[name]) name = 'json';
    if (name !== 'json') {
      var err = load();
      if (err) {
        name = 'json';
        say('The JSON has an error, fix it first: ' + err);
        alert('The JSON has an error, fix it first: ' + err);
      }
    }
    if (ed.tab && ed.tab !== name && ed.views[ed.tab] && ed.views[ed.tab].hide) ed.views[ed.tab].hide();
    ed.tab = name;
    Object.keys(ed.panels).forEach(function (k) { ed.panels[k].hidden = k !== name; ed.buttons[k].classList.toggle('on', k === name); ed.buttons[k].setAttribute('aria-selected', k === name ? 'true' : 'false'); });
    if (name === 'json') T.refresh();
    else ed.views[name].render(ed.panels[name], sel);
    counts();
    remember('tab', name);
  }
  function counts() {
    var root = ed.root;
    if (!root || ed.tab === 'json') { try { root = T.parse(ed.ta.value); } catch (e) { return; } }
    ed.order.forEach(function (k) {
      var c = ed.views[k] && ed.views[k].count ? ed.views[k].count(root) : '';
      var b = ed.buttons[k].querySelector('.te-count'); if (b) b.textContent = c === '' ? '' : c;
    });
  }

  // ---------- the Structure tab (plan §4.1) ----------
  var structure = {
    count: function (root) { return T.sections(root).length; },
    sel: null,
    render: function (host, sel) {
      if (sel) this.sel = sel;
      if (!this.sel) this.sel = recall('structure') || {kind: 'settings'};
      host.textContent = '';
      var wrap = el('div', 'te-split');
      var list = el('div', 'te-list'), props = el('div', 'te-props');
      wrap.appendChild(list); wrap.appendChild(props); host.appendChild(wrap);
      this.list = list; this.props = props;
      this.drawList(); this.drawProps();
    },
    select: function (sel) { this.sel = sel; remember('structure', sel); this.drawList(); this.drawProps(); },
    find: function () {   // the selected node, by id (survives moves)
      var secs = T.sections(ed.root), s = this.sel || {};
      for (var i = 0; i < secs.length; i++) {
        if (s.kind === 'section' && T.sval(secs[i], 'id') === s.id) return {sec: secs[i], i: i};
        var tops = arr(T.get(secs[i], 'topics'));
        for (var j = 0; j < tops.length; j++) if (s.kind === 'topic' && T.sval(tops[j], 'id') === s.id) return {sec: secs[i], i: i, topic: tops[j], j: j};
      }
      return null;
    },
    drawList: function () {
      var self = this, list = this.list, secs = T.sections(ed.root);
      list.textContent = '';
      var settings = el('div', 'te-item te-settings' + (this.sel.kind === 'settings' ? ' on' : ''));
      settings.appendChild(el('span', 'te-ic', '⚙'));
      settings.appendChild(el('span', 'te-name', 'Document settings'));
      settings.appendChild(el('span', 'te-meta', secs.length + ' sections · ' + (T.get(ed.root, 'fields') || {e: []}).e.length + ' fields'));
      settings.addEventListener('click', function () { self.select({kind: 'settings'}); });
      list.appendChild(settings);
      var secArr = T.get(ed.root, 'sections');
      secs.forEach(function (sec, i) {
        var id = T.sval(sec, 'id');
        var row = self.item(secArr, i, ICONS[T.sval(sec, 'layout')] || '§', sectionName(sec, ed.root, ed.sharedLabels), sectionMeta(sec),
                            self.sel.kind === 'section' && self.sel.id === id, function () { self.select({kind: 'section', id: id}); }, 'section:' + id);
        list.appendChild(row);
        if (T.sval(sec, 'layout') === 'topics') {
          var tArr = T.get(sec, 'topics'), sub = el('div', 'te-sub');
          arr(tArr).forEach(function (tp, j) {
            var tid = T.sval(tp, 'id'), nb = arr(T.get(tp, 'blocks')).length;
            sub.appendChild(self.item(tArr, j, '–', T.sval(tp, 'title') || '(no title)', nb + ' block' + (nb === 1 ? '' : 's'),
                            self.sel.kind === 'topic' && self.sel.id === tid, function () { self.select({kind: 'topic', id: tid}); }, 'topic:' + tid));
          });
          sub.appendChild(button('+ Add topic', 'A new topic at the end of this section', function () { self.addTopic(sec); }, 'te-add'));
          list.appendChild(sub);
        }
      });
      var add = el('div', 'te-addrow'), pick = el('select');
      T.ENUMS.layout.forEach(function (l) { var o = el('option', '', (ICONS[l] || '') + ' ' + l); o.value = l; pick.appendChild(o); });
      add.appendChild(button('+ Add section', 'A new section at the end, with the layout chosen beside', function () { self.addSection(pick.value); }, 'te-add'));
      add.appendChild(pick);
      list.appendChild(add);
    },
    // one row of the list: drag handle, icon, name, meta, tools
    item: function (parentArr, idx, icon, name, meta, on, onclick, src) {
      var self = this, row = el('div', 'te-item' + (on ? ' on' : '')), node = parentArr.e[idx];
      row.tabIndex = 0;
      var h = el('span', 'te-handle', '⠿'); h.draggable = true; h.title = 'Drag to move';
      h.addEventListener('dragstart', function (e) { self.drag = {arr: parentArr, idx: idx}; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', name); row.classList.add('te-dragging'); });
      h.addEventListener('dragend', function () { self.drag = null; row.classList.remove('te-dragging'); });
      row.addEventListener('dragover', function (e) { if (self.drag && self.drag.arr === parentArr && self.drag.idx !== idx) { e.preventDefault(); row.classList.add('te-over'); } });
      row.addEventListener('dragleave', function () { row.classList.remove('te-over'); });
      row.addEventListener('drop', function (e) { e.preventDefault(); row.classList.remove('te-over'); var d = self.drag; if (!d || d.arr !== parentArr) return; self.move(parentArr, d.idx, idx); });
      row.appendChild(h);
      row.appendChild(el('span', 'te-ic', icon));
      var nm = el('span', 'te-name', name); nm.title = name; row.appendChild(nm);
      row.appendChild(el('span', 'te-meta', meta));
      var tools = el('span', 'te-tools');
      if (src) tools.appendChild(button('⌖', 'Show this in the preview', function () { window.dispatchEvent(new CustomEvent('ms-show', {detail: src})); }));
      tools.appendChild(button('↑', 'Move up', function () { if (idx > 0) self.move(parentArr, idx, idx - 1); }));
      tools.appendChild(button('↓', 'Move down', function () { if (idx < parentArr.e.length - 1) self.move(parentArr, idx, idx + 1); }));
      tools.appendChild(button('⧉', 'Duplicate (the copy gets new ids)', function () { self.duplicate(parentArr, idx); }));
      tools.appendChild(button('✕', 'Delete', function () { self.remove(parentArr, idx); }));
      row.appendChild(tools);
      row.addEventListener('click', onclick);
      row.addEventListener('keydown', function (e) {
        if (e.target !== row) return;
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onclick(); }
        if (e.altKey && e.key === 'ArrowUp' && idx > 0) { e.preventDefault(); self.move(parentArr, idx, idx - 1); }
        if (e.altKey && e.key === 'ArrowDown' && idx < parentArr.e.length - 1) { e.preventDefault(); self.move(parentArr, idx, idx + 1); }
      });
      void node;
      return row;
    },
    move: function (a, from, to) { var x = a.e.splice(from, 1)[0]; a.e.splice(to, 0, x); commit(); this.drawList(); },
    duplicate: function (a, idx) {
      var c = T.clone(a.e[idx]); T.freshIds(ed.root, c);
      var slot = T.get(c, 'slot'), id = T.sval(c, 'id');
      if (slot && slot.t === 's' && T.sections(ed.root).indexOf(a.e[idx]) >= 0) slot.v = T.sval(ed.root, 'name') + '.' + id;
      a.e.splice(idx + 1, 0, c); commit('Copied with new ids: ' + id);
      this.select({kind: T.sections(ed.root).indexOf(c) >= 0 ? 'section' : 'topic', id: id});
    },
    remove: function (a, idx) {
      var n = a.e[idx], ids = {};
      T.walk(n, function (x, p) { if (p[p.length - 1] === 'id' && x.t === 's') ids[x.v] = 1; });
      var refs = T.allOps(ed.root).filter(function (o) { return ids[T.sval(o, 'block')] || ids[T.sval(o, 'section')]; }).length;
      var what = T.sections(ed.root).indexOf(n) >= 0 ? 'section «' + sectionName(n, ed.root, ed.sharedLabels) + '»' : 'topic «' + (T.sval(n, 'title') || T.sval(n, 'id')) + '»';
      if (!confirm('Delete ' + what + ' with everything in it?' + (refs ? '\n' + refs + ' case-rule operation(s) point into it and will be left without a target.' : ''))) return;
      a.e.splice(idx, 1); commit(); this.select({kind: 'settings'});
    },
    addSection: function (layout) {
      var secArr = T.get(ed.root, 'sections'); if (!secArr) { secArr = {t: 'a', e: []}; setKey(ed.root, 'sections', secArr); }
      var id = T.nextId(ed.root, '', 's'), pairs = [['id', S(id)], ['slot', S(T.sval(ed.root, 'name') + '.' + id)], ['layout', S(layout)]];
      if (layout === 'topics') pairs.push(['topics', {t: 'a', e: []}]);
      else if (layout !== 'medtable') pairs.push(['blocks', {t: 'a', e: []}]);
      secArr.e.push({t: 'o', e: pairs}); commit('Section ' + id + ' added');
      this.select({kind: 'section', id: id});
    },
    addTopic: function (sec) {
      var tArr = T.get(sec, 'topics'), sid = T.sval(sec, 'id'), id = T.nextId(ed.root, '', sid + '_t');
      tArr.e.push({t: 'o', e: [['id', S(id)], ['slot', S(T.sval(ed.root, 'name') + '.' + id)], ['title', S('')], ['style', S('itemize')], ['blocks', {t: 'a', e: []}]]});
      commit('Topic ' + id + ' added'); this.select({kind: 'topic', id: id});
    },
    drawProps: function () {
      var p = this.props, self = this; p.textContent = '';
      if (this.sel.kind === 'settings') return settingsForm(p, function () { self.drawList(); });
      var f = this.find();
      if (!f) { p.appendChild(el('p', 'muted', 'Select a section or a topic on the left.')); return; }
      if (f.topic) return topicForm(p, f.topic, function () { self.drawList(); });
      return sectionForm(p, f.sec, function () { self.drawList(); }, function () { self.drawProps(); });
    }
  };

  // ---------- property forms ----------
  /*
   * One property row. spec: {label, type: text|long|select|bool|list|ro, options, hint, keep (never remove the key)}
   * An emptied optional text removes its key; a bool that was absent and stays off stays absent: the JSON changes only
   * where the doctor changed something.
   */
  function prop(host, obj, key, spec, after) {
    var row = el('label', 'te-prop'), cur = T.get(obj, key);
    row.appendChild(el('span', 'te-plabel', spec.label));
    var input;
    function done() { commit(); if (after) after(); }
    if (spec.type === 'bool') {
      input = el('input'); input.type = 'checkbox'; input.checked = !!(cur && cur.v === true);
      input.addEventListener('change', function () { if (input.checked || T.get(obj, key)) setKey(obj, key, {t: 'b', v: input.checked}); done(); });
      row.classList.add('te-prop-bool');
    } else if (spec.type === 'select') {
      input = el('select');
      var opts = spec.options.slice(), now = cur ? (cur.t === 's' ? cur.v : JSON.stringify(cur.v)) : '';
      if (opts.indexOf(now) < 0) opts.push(now);
      opts.forEach(function (v) { var o = el('option', '', v === '' ? (spec.empty || '(not set)') : v); o.value = v; input.appendChild(o); });
      input.value = now;
      input.addEventListener('change', function () {
        if (spec.check && !spec.check(input.value)) { input.value = now; return; }
        if (input.value === '' && !spec.keep) delKey(obj, key);
        else setKey(obj, key, spec.node ? spec.node(input.value) : S(input.value));
        now = input.value; done();
      });
    } else if (spec.type === 'ro') {
      input = el('input', 'te-ro'); input.readOnly = true; input.value = cur && cur.t === 's' ? cur.v : (cur ? T.ser(cur) : '');
    } else if (spec.type === 'list') {   // a list of strings, one per line (titles, closings)
      input = el('textarea'); input.rows = 3;
      input.value = arr(cur).map(function (x) { return x.t === 's' ? x.v : T.ser(x); }).join('\n');
      input.addEventListener('input', function () {
        var lines = input.value.split('\n').filter(function (l) { return l.trim() !== ''; });
        if (!lines.length && !spec.keep) delKey(obj, key); else setKey(obj, key, {t: 'a', e: lines.map(S)});
        commit();
      });
      input.addEventListener('change', function () { if (after) after(); });
    } else {
      var long = spec.type === 'long';
      input = el(long ? 'textarea' : 'input'); if (!long) input.type = 'text'; else input.rows = 3;
      input.value = cur && cur.t === 's' ? cur.v : '';
      input.spellcheck = false;
      input.addEventListener('input', function () {
        if (input.value === '' && !spec.keep) delKey(obj, key); else setKey(obj, key, S(input.value));
        commit();
      });
      input.addEventListener('change', function () { if (after) after(); });
    }
    input.classList.add('te-input');
    row.appendChild(input);
    if (spec.hint) row.appendChild(el('span', 'te-hint', spec.hint));
    host.appendChild(row);
    return input;
  }
  // every key not shown by a form: plain values editable, lists/objects named with a pointer to the JSON tab
  function advanced(host, obj, shown, open) {
    var rest = obj.e.filter(function (p) { return shown.indexOf(p[0]) < 0; });
    var det = el('details', 'te-adv'); det.open = !!open;
    det.appendChild(el('summary', '', 'Advanced (' + rest.length + ' more key' + (rest.length === 1 ? '' : 's') + ')'));
    rest.forEach(function (p) {
      var k = p[0], n = p[1];
      if (n.t === 's') prop(det, obj, k, {label: k, type: n.v.length > 60 || n.v.indexOf('\n') >= 0 ? 'long' : 'text', keep: true});
      else if (n.t === 'b') prop(det, obj, k, {label: k, type: 'bool'});
      else {
        var r = el('div', 'te-prop'); r.appendChild(el('span', 'te-plabel', k));
        r.appendChild(el('span', 'te-hint', (n.t === 'a' ? n.e.length + ' item(s)' : n.t === 'o' ? n.e.length + ' key(s)' : T.ser(n)) + ': edit in the JSON tab'));
        det.appendChild(r);
      }
    });
    if (!rest.length) det.appendChild(el('p', 'muted', 'No other keys.'));
    host.appendChild(det);
    return det;
  }
  function idRow(host, obj, kind, after) {
    var row = el('div', 'te-prop'), id = T.sval(obj, 'id');
    row.appendChild(el('span', 'te-plabel', 'id'));
    var inp = el('input', 'te-input te-ro'); inp.readOnly = true; inp.value = id; row.appendChild(inp);
    row.appendChild(button('Rename…', 'Rename this id; the case-rule operations that point to it follow', function () {
      var nv = (prompt('New id for ' + kind + ' "' + id + '":', id) || '').trim();
      if (!nv || nv === id) return;
      if (!/^[A-Za-z0-9_.-]+$/.test(nv)) { alert('Use letters, digits, _ . - only.'); return; }
      if (T.allIds(ed.root)[nv]) { alert('The id "' + nv + '" is already used.'); return; }
      T.get(obj, 'id').v = nv;
      var c = kind === 'section' || kind === 'block' ? T.renameRef(ed.root, kind, id, nv) : 0;
      commit(c ? c + ' case-rule operation(s) updated' : 'Renamed');
      if (after) after(nv);
    }));
    host.appendChild(row);
  }

  function settingsForm(p, after) {
    var r = ed.root;
    p.appendChild(el('h3', '', 'Document settings'));
    prop(p, r, 'label', {label: 'Name of the type', keep: true, hint: 'Shown in the list of types and on new documents.'}, after);
    prop(p, r, 'title', {label: 'Title', keep: true, hint: 'The title printed on the document.'});
    prop(p, r, 'titles', {label: 'Other titles', type: 'list', hint: 'One per line: the composer offers them instead of the title.'});
    prop(p, r, 'closing', {label: 'Closing', keep: true, hint: 'Empty = no doctor block at the end.'});
    prop(p, r, 'closings', {label: 'Other closings', type: 'list', hint: 'One per line.'});
    prop(p, r, 'lang', {label: 'Language', type: 'select', options: ['gr', 'en'], keep: true});
    prop(p, r, 'paper', {label: 'Paper', type: 'select', options: ['a4', 'a5'], keep: true});
    prop(p, r, 'stamp', {label: 'Stamp', type: 'select', options: ['yes', 'no'], keep: true});
    prop(p, r, 'has_patient', {label: 'Patient', type: 'select', options: ['', 'true', 'false', '"optional"'], empty: 'required (default)',
      node: function (v) { return v === '"optional"' ? S('optional') : {t: 'b', v: v === 'true'}; },
      hint: 'false = a practice document with no patient; "optional" = the name is not required.'});
    prop(p, r, 'page_numbers', {label: 'Page numbers', type: 'select', options: ['', 'all', 'yes', 'no'], empty: '(kit default)'});
    prop(p, r, 'recipient', {label: 'Recipient line', type: 'bool', hint: 'The composer asks for a recipient.'});
    prop(p, r, 'reviewed', {label: 'Reviewed on', hint: 'The date the wording was reviewed (YYYY-MM-DD).'});
    advanced(p, r, ['label', 'title', 'titles', 'closing', 'closings', 'lang', 'paper', 'stamp', 'has_patient', 'page_numbers', 'recipient',
                    'reviewed', 'sections', 'fields', 'features', 'shared']);
  }

  function sectionForm(p, sec, afterList, redraw) {
    var layout = T.sval(sec, 'layout');
    p.appendChild(el('h3', '', sectionName(sec, ed.root, ed.sharedLabels)));
    prop(p, sec, 'heading', {label: 'Heading', hint: 'Printed above the section. Empty = no heading.'}, afterList);
    prop(p, sec, 'label', {label: 'Name on the input form', hint: 'Wins over the heading on the form; needed only when there is no heading.'}, afterList);
    prop(p, sec, 'layout', {label: 'Layout', type: 'select', options: T.ENUMS.layout, keep: true, check: function (v) {
      if ((v === 'topics') !== (layout === 'topics')) { alert('A topics section owns its blocks through its topics: it cannot be turned into another layout, or the other way round. Make a new section and move the blocks.'); return false; }
      if (v === 'medtable' || layout === 'medtable') { alert('A medication table is a different kind of section: make a new section instead.'); return false; }
      return true;
    }}, function () { afterList(); redraw(); });
    if (T.sval(sec, 'heading')) {
      prop(p, sec, 'heading_level', {label: 'Heading level', type: 'select', options: ['', 'section', 'subsection'], empty: 'section (default)'});
      prop(p, sec, 'numbered', {label: 'Numbered heading', type: 'bool'});
    }
    prop(p, sec, 'optional', {label: 'Optional', type: 'bool', hint: 'The composer can switch the whole section off.'}, function () { afterList(); redraw(); });
    if (bval(sec, 'optional')) prop(p, sec, 'default', {label: 'On by default', type: 'bool'});
    if (layout !== 'medtable' && layout !== 'patient-info') prop(p, sec, 'placeholder', {label: 'Free-text hint', type: 'long', hint: 'Grey hint in the composer\'s free-text box; never printed.'});
    if (layout === 'medtable') {
      prop(p, sec, 'title_page', {label: 'Title above the table'}, afterList);
      prop(p, sec, 'intro', {label: 'Text above the table', type: 'long'});
      if (window.MSMeds) window.MSMeds.form(p, sec, afterList);   // library and default rows (type_meds.js)
      else prop(p, sec, 'library', {label: 'Drug library', hint: 'A shared drugs component (key under "shared").'}, afterList);
    }
    if (T.sval(sec, 'use')) {
      prop(p, sec, 'use', {label: 'Shared component', keep: true}, afterList);
      prop(p, sec, 'default_block', {label: 'Default choice', hint: 'The id of the component\'s sentence that is on by default.'});
    }
    var adv = advanced(p, sec, ['heading', 'label', 'layout', 'heading_level', 'numbered', 'optional', 'default', 'placeholder', 'title_page', 'intro',
                                'library', 'use', 'default_block', 'id', 'blocks', 'topics'].concat(layout === 'medtable' && window.MSMeds ? ['default_rows'] : []));
    idRow(adv, sec, 'section', function (nv) { structure.sel = {kind: 'section', id: nv}; remember('structure', structure.sel); afterList(); redraw(); });
    var go = el('p', 'te-goto');
    go.appendChild(button('Edit the content →', 'Open this section in the Content tab', function () { show('content', {id: T.sval(sec, 'id')}); }));
    p.appendChild(go);
  }

  function topicForm(p, tp, afterList) {
    p.appendChild(el('h3', '', T.sval(tp, 'title') || '(no title)'));
    prop(p, tp, 'title', {label: 'Title', keep: true}, afterList);
    prop(p, tp, 'style', {label: 'Style', type: 'select', options: ['itemize', 'paragraph'], keep: true, hint: 'itemize = bullets; paragraph = running text (blocks with "item" make a list under it).'});
    var adv = advanced(p, tp, ['title', 'style', 'id', 'blocks']);
    idRow(adv, tp, 'topic', function (nv) { structure.sel = {kind: 'topic', id: nv}; remember('structure', structure.sel); afterList(); structure.drawProps(); });
    var go = el('p', 'te-goto');
    go.appendChild(button('Edit the content →', 'Open this topic in the Content tab', function () { show('content', {id: T.sval(tp, 'id')}); }));
    p.appendChild(go);
  }

  // ---------- page wiring ----------
  function init() {
    var root = document.querySelector('.te');
    T = window.MSTypeTree;
    if (!root || !T) return;
    ed.ta = root.querySelector('textarea[name=text]');
    ed.kind = root.getAttribute('data-kind'); ed.name = root.getAttribute('data-name');
    try { ed.sharedLabels = JSON.parse(root.getAttribute('data-shared-labels') || '{}'); } catch (e) {}
    var saved = root.querySelector('.te-saved-text');   // the stored version's text (the box may hold text handed back unsaved)
    ed.baseline = canonical(saved ? saved.value : ed.ta.value);
    var tabs = root.querySelector('.te-tabs');
    ed.order = [];
    tabs.querySelectorAll('[data-tab]').forEach(function (b) {
      var k = b.getAttribute('data-tab');
      if (k !== 'json' && !ed.views[k]) { b.hidden = true; return; }   // a tab whose view is not built yet
      ed.order.push(k); ed.buttons[k] = b; ed.panels[k] = root.querySelector('.te-panel[data-tab="' + k + '"]');
      b.addEventListener('click', function () {
        if (ed.tab === 'json' && k !== 'json') { try { T.parse(ed.ta.value); } catch (e) { alert('The JSON has an error, fix it first: ' + e.message); return; } }
        show(k);
      });
    });
    ed.ta.addEventListener('input', markUnsaved);
    ed.ta.addEventListener('tt-change', function () { markUnsaved(); if (ed.tab === 'json') counts(); });
    // ⌖ in the preview: the tab that owns the source shows it (the JSON tab: type_tree.js does it)
    window.addEventListener('ms-reveal', function (e) {
      if (ed.tab === 'json') return;
      var src = e.detail || '';
      if (/^field:/.test(src) && ed.views.fields) show('fields', {src: src});
      else if (ed.views.content && /^(block|topic|section|extra):/.test(src)) show('content', {src: src});
      else if (/^(section|topic):/.test(src)) show('structure', {kind: src.split(':')[0], id: src.split(':')[1]});
      else if (/^(title|closing)$/.test(src)) show('structure', {kind: 'settings'});
      else show('json');
      if (ed.tab === 'json') setTimeout(function () { window.dispatchEvent(new CustomEvent('ms-reveal', {detail: src})); }, 0);
    });
    // the Save dialog
    var dlg = root.querySelector('.te-save');
    root.querySelectorAll('.te-open-save').forEach(function (b) {
      b.addEventListener('click', function () { if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', ''); var n = dlg.querySelector('input[name=note]'); if (n) n.focus(); });
    });
    root.querySelectorAll('.te-close-save').forEach(function (b) { b.addEventListener('click', function () { if (dlg.close) dlg.close(); else dlg.removeAttribute('open'); }); });
    var hist = root.querySelector('.te-history'), hb = root.querySelector('.te-open-history');
    if (hb && hist) hb.addEventListener('click', function () { hist.hidden = !hist.hidden; hb.classList.toggle('on', !hist.hidden); });
    var form = ed.ta.form, submitting = false;
    form.addEventListener('submit', function () { submitting = true; });
    window.addEventListener('beforeunload', function (e) { if (ed.dirty && !submitting) { e.preventDefault(); e.returnValue = ''; } });
    markUnsaved();
    show(recall('tab') || (ed.kind === 'typedef' ? 'structure' : 'content'));
  }

  if (typeof window !== "undefined") window.MSTypeEditor = {
    register: register, show: show, commit: commit, say: say, remember: remember, recall: recall,
    jsonActive: function () { return ed.tab === 'json' || !ed.tab; },
    root: function () { return ed.root; }, sharedLabels: function () { return ed.sharedLabels; },
    h: {el: el, button: button, S: S, arr: arr, setKey: setKey, delKey: delKey, bval: bval, prop: prop, advanced: advanced, idRow: idRow,
        plainText: plainText, cut: cut, fieldLabels: fieldLabels, sectionName: sectionName, ICONS: ICONS}
  };
  if (typeof module !== 'undefined') {   // node tests: the naming rule
    T = require('./type_tree.js');
    module.exports = {sectionName: sectionName, plainText: plainText, setKey: setKey};
  } else if (typeof document !== 'undefined') {
    register('structure', structure);
    // after the other tab scripts (deferred to the end of the page) have registered
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 0); }); else setTimeout(init, 0);
  }
})();
