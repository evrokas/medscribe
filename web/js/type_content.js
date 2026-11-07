/*
 * The Content tab of the type editor (docs/plans/type-editor-ui.md §4.2): an outline of sections and topics on the left,
 * the selected one as a document-like editor on the right. One row per block (the real unit: one sentence or bullet with
 * its own on/off, choice group and preview source); blocks of one choice group are drawn as an Alternatives box.
 * Texts are edited with the chip editor (type_chips.js). Raw LaTeX blocks are read-only here (Advanced edits them).
 */
(function () {
  'use strict';
  var E = window.MSTypeEditor, T = window.MSTypeTree, C = window.MSChips;
  if (!E || !T || !C) return;
  var h = E.h, el = h.el, button = h.button, S = h.S, arr = h.arr;

  var BUILTIN_LABELS = {patient: 'Patient name', age: 'Age', amka: 'AMKA', clinic: 'Clinic', date: 'Document date'};
  function known(root) { var k = {}; Object.keys(BUILTIN_LABELS).forEach(function (b) { k[b] = BUILTIN_LABELS[b]; }); var f = h.fieldLabels(root); Object.keys(f).forEach(function (x) { k[x] = f[x]; }); return k; }
  function isOn(b) { var d = T.get(b, 'default'); return !!(d && d.t === 'b' && d.v); }
  function onCount(blocks) { var on = 0; blocks.forEach(function (b) { if (isOn(b)) on++; }); return on + '/' + blocks.length; }
  function sectionBlocks(sec) {
    var out = arr(T.get(sec, 'blocks')).slice();
    arr(T.get(sec, 'topics')).forEach(function (tp) { out = out.concat(arr(T.get(tp, 'blocks'))); });
    return out;
  }

  var view = {
    sel: null, editors: [], active: null,
    count: function () { return ''; },
    render: function (host, sel) {
      var root = E.root();
      if (sel && sel.src) sel = this.fromSrc(root, sel.src) || {};
      if (sel && sel.id) this.sel = sel; else if (!this.sel) this.sel = E.recall('content') || {};
      if (!this.findUnit(root)) { var first = T.sections(root)[0]; this.sel = first ? {id: T.sval(first, 'id')} : {}; }
      host.textContent = '';
      var wrap = el('div', 'te-split te-content');
      this.outline = el('div', 'te-list'); this.pane = el('div', 'te-doc');
      wrap.appendChild(this.outline); wrap.appendChild(this.pane); host.appendChild(wrap);
      this.drawOutline(); this.drawPane();
      if (this.sel.block) this.focusBlock(this.sel.block);
    },
    // a preview source -> the unit that shows it (+ the block to focus)
    fromSrc: function (root, src) {
      var m = /^(block|topic|section|extra):(.*)$/.exec(src); if (!m) return null;
      var loc = T.locate(root, src); if (!loc) return null;
      var unit = null, block = null;
      for (var i = loc.path.length; i >= 1; i--) {
        var k = T.kindOf(loc.path.slice(0, i)), n = loc.chain[i];
        if (k === 'block' && !block) block = T.sval(n, 'id');
        if ((k === 'topic' || k === 'section') && !unit) unit = T.sval(n, 'id');
      }
      return unit ? {id: unit, block: block} : null;
    },
    findUnit: function (root) {
      var id = this.sel && this.sel.id, secs = T.sections(root);
      if (!id) return null;
      for (var i = 0; i < secs.length; i++) {
        if (T.sval(secs[i], 'id') === id) return {sec: secs[i]};
        var tops = arr(T.get(secs[i], 'topics'));
        for (var j = 0; j < tops.length; j++) if (T.sval(tops[j], 'id') === id) return {sec: secs[i], topic: tops[j]};
      }
      return null;
    },
    select: function (sel) { this.sel = sel; E.remember('content', {id: sel.id}); this.drawOutline(); this.drawPane(); },

    drawOutline: function () {
      var self = this, root = E.root(), o = this.outline; o.textContent = '';
      var secs = T.sections(root), nf = (T.get(root, 'fields') || {e: []}).e.length;
      o.appendChild(el('div', 'te-outline-head', secs.length + ' sections · ' + nf + ' fields'));
      secs.forEach(function (sec) {
        var id = T.sval(sec, 'id'), blocks = sectionBlocks(sec);
        var row = el('div', 'te-item' + (self.sel.id === id ? ' on' : '')); row.tabIndex = 0;
        row.appendChild(el('span', 'te-ic', h.ICONS[T.sval(sec, 'layout')] || '§'));
        var nm = el('span', 'te-name', h.sectionName(sec, root, E.sharedLabels())); nm.title = nm.textContent; row.appendChild(nm);
        row.appendChild(el('span', 'te-meta', T.sval(sec, 'use') ? 'shared' : T.sval(sec, 'layout') === 'medtable' ? 'table' : blocks.length ? onCount(blocks) : ''));
        function go() { self.select({id: id}); }
        row.addEventListener('click', go); row.addEventListener('keydown', function (e) { if (e.key === 'Enter') go(); });
        o.appendChild(row);
        var tops = arr(T.get(sec, 'topics'));
        if (tops.length) {
          var sub = el('div', 'te-sub');
          tops.forEach(function (tp) {
            var tid = T.sval(tp, 'id'), tb = arr(T.get(tp, 'blocks'));
            var r = el('div', 'te-item' + (self.sel.id === tid ? ' on' : '')); r.tabIndex = 0;
            r.appendChild(el('span', 'te-ic', '–'));
            var tn = el('span', 'te-name', T.sval(tp, 'title') || '(no title)'); tn.title = tn.textContent; r.appendChild(tn);
            r.appendChild(el('span', 'te-meta', onCount(tb)));
            function g2() { self.select({id: tid}); }
            r.addEventListener('click', g2); r.addEventListener('keydown', function (e) { if (e.key === 'Enter') g2(); });
            sub.appendChild(r);
          });
          o.appendChild(sub);
        }
      });
      o.appendChild(el('p', 'te-hint', 'The Structure tab adds, moves and removes sections and topics.'));
    },
    refreshOutlineCounts: function () { this.drawOutline(); },

    drawPane: function () {
      var self = this, root = E.root(), p = this.pane, u = this.findUnit(root);
      p.textContent = ''; this.editors = []; this.active = null;
      if (!u) { p.appendChild(el('p', 'muted', 'Choose a section on the left.')); return; }
      this.kn = known(root);
      var sec = u.sec, layout = T.sval(sec, 'layout'), textual = layout !== 'medtable' && !T.sval(sec, 'use');
      if (textual) p.appendChild(this.toolbar());
      var body = el('div', 'te-doc-body'); p.appendChild(body);
      if (u.topic) {
        this.heading(body, u.topic, 'title', 'Topic title', 'topic:' + T.sval(u.topic, 'id'));
        this.blockList(body, u.topic, layout, sec);
      } else {
        this.heading(body, sec, 'heading', 'Section heading (empty = none)', 'section:' + T.sval(sec, 'id'));
        if (T.sval(sec, 'use')) {
          var sh = el('div', 'te-callout');
          sh.appendChild(el('span', '', 'The sentences of this section come from the shared component «' + (E.sharedLabels()[T.sval(sec, 'use')] || T.sval(sec, 'use')) + '». '));
          var a = el('a', '', 'Edit the component'), crumb = document.querySelector('.te-crumb');
          a.href = (crumb ? crumb.getAttribute('href') : '/types') + '/shared/' + T.sval(sec, 'use');
          sh.appendChild(a);
          sh.appendChild(el('span', '', '. Default choice: ' + (T.sval(sec, 'default_block') || 'none') + ' (Structure tab).'));
          body.appendChild(sh);
        } else if (layout === 'medtable') {
          if (window.MSMeds) window.MSMeds.form(body, sec, function () { self.drawOutline(); });
          else body.appendChild(el('div', 'te-callout', 'A medication table: its rows come from the drug library «' + T.sval(sec, 'library') + '». Edit it in the JSON tab.'));
        } else if (layout === 'topics') {
          arr(T.get(sec, 'topics')).forEach(function (tp) {
            var box = el('div', 'te-topic');
            self.heading(box, tp, 'title', 'Topic title', 'topic:' + T.sval(tp, 'id'), true);
            self.blockList(box, tp, layout, sec);
            body.appendChild(box);
          });
        } else this.blockList(body, sec, layout, sec);
      }
      if (!textual) return;
      var all = u.topic ? arr(T.get(u.topic, 'blocks')) : sectionBlocks(sec);
      var ids = all.map(function (b) { return T.sval(b, 'id'); });
      p.appendChild(el('p', 'te-foot', all.length + ' block' + (all.length === 1 ? '' : 's') + ', ' + all.filter(isOn).length + ' on by default' +
        (ids.length ? ' · ids ' + ids[0] + (ids.length > 1 ? ' … ' + ids[ids.length - 1] : '') : '')));
    },

    heading: function (host, obj, key, ph, src, small) {
      var row = el('div', 'te-heading' + (small ? ' te-heading-sm' : ''));
      var inp = el('input', 'te-heading-input'); inp.type = 'text'; inp.value = T.sval(obj, key); inp.placeholder = ph;
      inp.addEventListener('input', function () { if (inp.value === '' && key === 'heading') h.delKey(obj, key); else h.setKey(obj, key, S(inp.value)); E.commit(); });
      inp.addEventListener('change', function () { view.drawOutline(); });
      row.appendChild(inp);
      row.appendChild(button('⌖', 'Show this in the preview', function () { window.dispatchEvent(new CustomEvent('ms-show', {detail: src})); }, 'te-target'));
      host.appendChild(row);
    },

    // the blocks of a section or topic; runs of one choice group are boxed as alternatives
    blockList: function (host, owner, layout, sec) {
      var self = this, list = T.get(owner, 'blocks');
      if (!list) { list = {t: 'a', e: []}; h.setKey(owner, 'blocks', list); }
      var box = el('div', 'te-blocks'), i = 0;
      while (i < list.e.length) {
        var g = T.sval(list.e[i], 'group');
        if (g) {
          var j = i; while (j < list.e.length && T.sval(list.e[j], 'group') === g) j++;
          box.appendChild(this.groupBox(list, i, j, g, layout, owner, sec));
          i = j;
        } else { box.appendChild(this.blockRow(list, i, layout, owner, sec, null)); i++; }
      }
      host.appendChild(box);
      var add = el('div', 'te-addrow');
      add.appendChild(button('+ Instruction', 'A new sentence at the end', function () { self.addBlock(list, list.e.length - 1, owner, sec, null); }, 'te-add'));
      host.appendChild(add);
    },
    groupBox: function (list, from, to, g, layout, owner, sec) {
      var self = this, box = el('div', 'te-group'), head = el('div', 'te-group-head');
      var name = 'te-g-' + g.replace(/\W/g, '_') + '-' + Math.random().toString(36).slice(2, 7);
      head.appendChild(el('span', 'te-group-title', 'Alternatives'));
      head.appendChild(el('span', 'te-meta', 'choice group «' + g + '» · at most one is printed'));
      head.appendChild(button('Rename group', 'Rename the group in every block of it', function () {
        var nv = (prompt('New name for choice group "' + g + '":', g) || '').trim(); if (!nv || nv === g) return;
        var c = 0; T.allBlocks(E.root()).forEach(function (b) { var x = T.get(b, 'group'); if (x && x.v === g) { x.v = nv; c++; } });
        E.commit(c + ' block(s) updated'); self.drawPane();
      }));
      box.appendChild(head);
      for (var k = from; k < to; k++) box.appendChild(this.blockRow(list, k, layout, owner, sec, {name: name, group: g}));
      // "none by default"
      var none = el('label', 'te-none'), r = el('input'); r.type = 'radio'; r.name = name;
      var anyOn = false; for (k = from; k < to; k++) if (isOn(list.e[k])) anyOn = true;
      r.checked = !anyOn;
      r.addEventListener('change', function () { self.setGroupDefault(g, null); });
      none.appendChild(r); none.appendChild(el('span', '', 'None of these by default'));
      box.appendChild(none);
      var add = button('+ Alternative', 'Another sentence in this group', function () { self.addBlock(list, to - 1, owner, sec, g); }, 'te-add');
      box.appendChild(add);
      return box;
    },
    setGroupDefault: function (g, id) {
      T.allBlocks(E.root()).forEach(function (b) { if (T.sval(b, 'group') === g) h.setKey(b, 'default', {t: 'b', v: T.sval(b, 'id') === id}); });
      E.commit(); this.drawOutline();
    },

    blockRow: function (list, idx, layout, owner, sec, grp) {
      var self = this, b = list.e[idx], id = T.sval(b, 'id'), latex = T.sval(b, 'format') === 'latex';
      var row = el('div', 'te-block' + (isOn(b) ? '' : ' te-off')); row.setAttribute('data-block', id);
      // on by default: a switch, or a radio in a choice group
      var sw;
      if (grp) {
        sw = el('input', 'te-radio'); sw.type = 'radio'; sw.name = grp.name; sw.checked = isOn(b); sw.title = 'The default choice of the group';
        sw.addEventListener('change', function () { self.setGroupDefault(grp.group, id); });
      } else {
        sw = el('input', 'te-switch'); sw.type = 'checkbox'; sw.checked = isOn(b); sw.title = 'On by default'; sw.setAttribute('role', 'switch');
        sw.addEventListener('change', function () { h.setKey(b, 'default', {t: 'b', v: sw.checked}); row.classList.toggle('te-off', !sw.checked); E.commit(); self.drawOutline(); });
      }
      row.appendChild(sw);
      var main = el('div', 'te-block-main');
      var labelled = layout === 'patient-info' || layout === 'description' || layout === 'urgentbox' || T.get(b, 'label');
      if (labelled) {
        var li = el('input', 'te-term'); li.type = 'text'; li.value = T.sval(b, 'label'); li.placeholder = layout === 'patient-info' ? 'Row label' : 'Term';
        li.addEventListener('input', function () { if (li.value === '' && !T.get(b, 'label')) return; h.setKey(b, 'label', S(li.value)); E.commit(); });
        main.appendChild(li);
      }
      var ed = null;
      if (latex) {
        var pre = el('pre', 'te-latex', T.sval(b, 'text'));
        main.appendChild(el('span', 'te-badge', 'Raw LaTeX: edit it under ⋯'));
        main.appendChild(pre);
      } else {
        ed = C.editor(document, {text: T.sval(b, 'text'), known: this.kn, id: id, onChange: function (t) { h.setKey(b, 'text', S(t)); E.commit(); }});
        ed.el.addEventListener('focusin', function () { self.active = {ed: ed, list: list, idx: idx, owner: owner, sec: sec, grp: grp ? grp.group : ''}; self.markActive(row); });
        main.appendChild(ed.el);
        this.editors.push(ed);
      }
      var flags = [];
      if (T.get(b, 'datefmt')) flags.push('dates ' + T.sval(b, 'datefmt'));
      if (h.bval(b, 'inline')) flags.push('continues the paragraph');
      if (h.bval(b, 'item')) flags.push('bullet under the paragraph');
      if (flags.length) main.appendChild(el('div', 'te-flags', flags.join(' · ')));
      row.appendChild(main);

      var tools = el('div', 'te-tools');
      tools.appendChild(button('⌖', 'Show this in the preview', function () { window.dispatchEvent(new CustomEvent('ms-show', {detail: 'block:' + id})); }));
      tools.appendChild(button('↑', 'Move up', function () { self.move(list, idx, -1); }));
      tools.appendChild(button('↓', 'Move down', function () { self.move(list, idx, 1); }));
      tools.appendChild(button('⧉', 'Duplicate (new id)', function () { var c = T.clone(b); T.freshIds(E.root(), c); h.setKey(c, 'default', {t: 'b', v: grp ? false : isOn(b)}); list.e.splice(idx + 1, 0, c); E.commit(); self.drawPane(); self.focusBlock(T.sval(c, 'id')); }));
      tools.appendChild(button('✕', 'Delete', function () { self.remove(list, idx); }));
      var more = button('⋯', 'More: plain text, choice group, id, date format, …', function () { adv.hidden = !adv.hidden; more.classList.toggle('on', !adv.hidden); });
      tools.appendChild(more);
      row.appendChild(tools);
      var adv = el('div', 'te-block-adv'); adv.hidden = true;
      this.blockAdvanced(adv, b, ed, list, idx, layout);
      row.appendChild(adv);
      return row;
    },
    blockAdvanced: function (adv, b, ed, list, idx, layout) {
      var self = this;
      if (ed) {
        var pt = el('label', 'te-prop te-prop-bool'); pt.appendChild(el('span', 'te-plabel', 'Edit as plain text'));
        var cb = el('input'); cb.type = 'checkbox'; cb.checked = ed.isPlain();
        cb.addEventListener('change', function () { if (!ed.plain(cb.checked)) { cb.checked = true; alert('This text cannot be shown with chips safely; it stays plain text.'); } });
        pt.appendChild(cb); adv.appendChild(pt);
      }
      h.idRow(adv, b, 'block', function () { self.drawPane(); });
      h.prop(adv, b, 'group', {label: 'Choice group', hint: 'Blocks with the same group are alternatives: at most one is printed. Empty = not in a group.'}, function () { self.drawPane(); });
      if (layout !== 'patient-info' && layout !== 'description' && layout !== 'urgentbox')
        h.prop(adv, b, 'label', {label: 'Label', hint: 'A term printed before the text (description / patient info rows; urgentbox: a heading line).'});
      var dfl = h.prop(adv, b, 'datefmt', {label: 'Date format', hint: 'For the dates in this block that have no format of their own (d/m/Y, j/n/Y, d-m-Y, long, …).'});
      dfl.setAttribute('list', 'tt-datefmts');
      if (layout === 'paragraphs') h.prop(adv, b, 'inline', {label: 'Continue the paragraph', type: 'bool', hint: 'Printed after the previous block, in the same paragraph.'});
      h.prop(adv, b, 'item', {label: 'Bullet under the paragraph', type: 'bool', hint: 'In a paragraph topic: consecutive such blocks make a list under the paragraph before them.'});
      h.prop(adv, b, 'format', {label: 'Format', type: 'select', options: ['', 'markup', 'latex'], empty: 'markup (default)', hint: 'latex = trusted raw LaTeX (no escaping): only for what the markup cannot express.'}, function () { self.drawPane(); });
      if (T.sval(b, 'format') === 'latex') h.prop(adv, b, 'text', {label: 'LaTeX', type: 'long', keep: true}, function () { self.drawPane(); });
      h.advanced(adv, b, ['id', 'text', 'default', 'group', 'label', 'datefmt', 'inline', 'item', 'format']);
    },
    markActive: function (row) { this.pane.querySelectorAll('.te-block.te-active').forEach(function (x) { x.classList.remove('te-active'); }); row.classList.add('te-active'); },
    move: function (list, idx, d) {
      var to = idx + d; if (to < 0 || to >= list.e.length) return;
      var x = list.e.splice(idx, 1)[0]; list.e.splice(to, 0, x); E.commit(); this.drawPane(); this.focusBlock(T.sval(x, 'id'));
    },
    remove: function (list, idx) {
      var b = list.e[idx], id = T.sval(b, 'id');
      var refs = T.allOps(E.root()).filter(function (o) { return T.sval(o, 'block') === id; }).length;
      if (!confirm('Delete this sentence?\n«' + h.cut(h.plainText(T.sval(b, 'text')), 80) + '»' + (refs ? '\n' + refs + ' case-rule operation(s) point to it and will be left without a target.' : ''))) return;
      list.e.splice(idx, 1); E.commit(); this.drawPane(); this.drawOutline();
    },
    // a new block after idx (in group g when given); its id follows the neighbours' ids
    addBlock: function (list, idx, owner, sec, g) {
      var near = list.e[idx] || list.e[list.e.length - 1], base = near ? T.sval(near, 'id') : '';
      var id = T.nextId(E.root(), base, (T.sval(owner, 'id') || T.sval(sec, 'id') || 's') + '_b');
      var pairs = [['id', S(id)], ['text', S('')]];
      if (g) pairs.push(['group', S(g)]);
      pairs.push(['default', {t: 'b', v: !g}]);
      list.e.splice(idx + 1, 0, {t: 'o', e: pairs});
      E.commit(); this.drawPane(); this.drawOutline(); this.focusBlock(id);
    },
    // + Alternative from the toolbar: the active block and a new one become a choice group
    addAlternative: function () {
      var a = this.active; if (!a) { alert('Click into a sentence first.'); return; }
      var b = a.list.e[a.idx], g = a.grp;
      if (!g) {
        g = T.sval(b, 'id') + '_choice';
        h.setKey(b, 'group', S(g));
      }
      this.addBlock(a.list, a.idx, a.owner, a.sec, g);
    },
    focusBlock: function (id) {
      var row = this.pane.querySelector('.te-block[data-block="' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]'); if (!row) return;
      row.scrollIntoView({block: 'center'});
      row.classList.add('te-hit'); setTimeout(function () { row.classList.remove('te-hit'); }, 2000);
      var ce = row.querySelector('.te-ce:not([hidden]), .te-plain:not([hidden])'); if (ce) ce.focus({preventScroll: true});
    },

    toolbar: function () {
      var self = this, tb = el('div', 'te-toolbar');
      function act(fn) { return function () { var a = self.active; if (!a) { alert('Click into a sentence first.'); return; } fn(a.ed); }; }
      function tbtn(label, title, fn, cls) {
        var b = button(label, title, fn, 'te-tbb' + (cls ? ' ' + cls : ''));
        b.addEventListener('mousedown', function (e) { e.preventDefault(); });   // keep the caret in the text
        return b;
      }
      tb.appendChild(tbtn('B', 'Bold: **text** (Ctrl+B)', act(function (ed) { ed.wrap('**', '**'); }), 'te-b'));
      tb.appendChild(tbtn('I', 'Italic: *text* (Ctrl+I)', act(function (ed) { ed.wrap('*', '*'); }), 'te-i'));
      tb.appendChild(el('span', 'te-sep'));
      var pick = el('select', 'te-fieldpick'); pick.title = 'Insert a field (printed as its value)';
      var o0 = el('option', '', '@ Field…'); o0.value = ''; pick.appendChild(o0);
      var fl = h.fieldLabels(E.root()), ogF = el('optgroup'); ogF.label = 'Fields of this type';
      Object.keys(fl).forEach(function (k) { var o = el('option', '', fl[k] + '  (@' + k + ')'); o.value = k; ogF.appendChild(o); });
      var ogB = el('optgroup'); ogB.label = 'Built-in';
      Object.keys(BUILTIN_LABELS).forEach(function (k) { var o = el('option', '', BUILTIN_LABELS[k] + '  (@' + k + ')'); o.value = k; ogB.appendChild(o); });
      pick.appendChild(ogF); pick.appendChild(ogB);
      pick.addEventListener('mousedown', function () { self.keep = self.active; });
      pick.addEventListener('change', function () {
        var a = self.active || self.keep, v = pick.value; pick.value = '';
        if (!v) return; if (!a) { alert('Click into a sentence first.'); return; }
        a.ed.focus(); a.ed.insertChip(v);
      });
      tb.appendChild(pick);
      tb.appendChild(tbtn('{Ο|Η}', 'Gender variant: {male|female}; the selected text becomes the male form', act(function (ed) { ed.wrap('{', '|}'); })));
      tb.appendChild(tbtn('L4-5', 'Spine level: {v:L4-5}', act(function (ed) { var v = prompt('Spine level (e.g. L4-5, C5-6):', 'L4-5'); if (v) ed.insertText('{v:' + v.trim() + '}'); })));
      tb.appendChild(el('span', 'te-sep'));
      tb.appendChild(tbtn('+ Instruction', 'A new sentence after the one with the caret', function () {
        var a = self.active; if (!a) { alert('Click into a sentence first (or use + Instruction under the list).'); return; }
        self.addBlock(a.list, a.idx, a.owner, a.sec, a.grp || null);
      }));
      tb.appendChild(tbtn('+ Alternative', 'An alternative to the sentence with the caret (they become a choice group)', function () { self.addAlternative(); }));
      return tb;
    }
  };
  E.register('content', view);
})();
