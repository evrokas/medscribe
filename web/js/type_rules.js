/*
 * The Case rules tab of the type editor (docs/plans/type-editor-ui.md §4.5): the type's `features` (the composer's chips)
 * with their operations written as sentences. A rule only sets what the doctor could set by hand (render_feature_add in
 * web/render.php); text it adds is the doctor's own wording. "Preview with this rule" asks the preview panel to apply it.
 */
(function () {
  'use strict';
  var E = window.MSTypeEditor, T = window.MSTypeTree;
  if (!E || !T) return;
  var h = E.h, el = h.el, button = h.button, S = h.S, arr = h.arr;

  function feats(root) { var f = T.get(root, 'features'); if (!f) { f = {t: 'o', e: []}; h.setKey(root, 'features', f); } return f; }
  // every block with where it is: id -> {node, section name, topic title, group}
  function blockIndex(root) {
    var idx = {}, fl = h.fieldLabels(root);
    T.sections(root).forEach(function (sec) {
      var sn = h.sectionName(sec, root, E.sharedLabels());
      arr(T.get(sec, 'blocks')).forEach(function (b) { idx[T.sval(b, 'id')] = {b: b, sec: sn, topic: '', group: T.sval(b, 'group'), text: h.plainText(T.sval(b, 'text'), fl)}; });
      arr(T.get(sec, 'topics')).forEach(function (tp) {
        arr(T.get(tp, 'blocks')).forEach(function (b) { idx[T.sval(b, 'id')] = {b: b, sec: sn, topic: T.sval(tp, 'title'), group: T.sval(b, 'group'), text: h.plainText(T.sval(b, 'text'), fl)}; });
      });
    });
    return idx;
  }
  // the places free text can be added: sections (not topics layout) and topics
  function textPlaces(root) {
    var out = [];
    T.sections(root).forEach(function (sec) {
      var sn = h.sectionName(sec, root, E.sharedLabels()), layout = T.sval(sec, 'layout');
      if (layout === 'topics') arr(T.get(sec, 'topics')).forEach(function (tp) { out.push({id: T.sval(tp, 'id'), name: sn + ' › ' + T.sval(tp, 'title')}); });
      else if (layout !== 'medtable' && layout !== 'patient-info') out.push({id: T.sval(sec, 'id'), name: sn});
    });
    return out;
  }

  var view = {
    sel: null, previewOn: false,
    count: function (root) { var f = T.get(root, 'features'); return f ? f.e.length : 0; },
    hide: function () { if (this.previewOn) { this.previewOn = false; window.dispatchEvent(new CustomEvent('ms-preview-features', {detail: []})); } },
    render: function (host, sel) {
      var f = feats(E.root());
      if (sel && sel.id) this.sel = sel.id; else if (!this.sel) this.sel = E.recall('rule');
      if (this.sel && !T.get(f, this.sel)) this.sel = null;
      if (!this.sel && f.e.length) this.sel = f.e[0][0];
      host.textContent = '';
      var wrap = el('div', 'te-split te-rules');
      this.list = el('div', 'te-list'); this.props = el('div', 'te-props');
      wrap.appendChild(this.list); wrap.appendChild(this.props); host.appendChild(wrap);
      this.host = host;
      this.drawList(); this.drawProps();
    },
    select: function (id) { this.sel = id; E.remember('rule', id); this.drawList(); this.drawProps(); if (this.previewOn) this.preview(true); },
    drawList: function () {
      var self = this, f = feats(E.root()), list = this.list; list.textContent = '';
      f.e.forEach(function (p, i) {
        var id = p[0], n = arr(T.get(p[1], 'ops')).length;
        var row = el('div', 'te-item te-rule' + (self.sel === id ? ' on' : '')); row.tabIndex = 0;
        row.appendChild(el('span', 'te-ic', '◆'));
        var nm = el('span', 'te-name', T.sval(p[1], 'label') || id); nm.title = nm.textContent; row.appendChild(nm);
        row.appendChild(el('span', 'te-meta', n + ' operation' + (n === 1 ? '' : 's') + ' · ' + id));
        var tools = el('span', 'te-tools');
        tools.appendChild(button('↑', 'Move up (the order of the chips)', function () { if (i > 0) { var x = f.e.splice(i, 1)[0]; f.e.splice(i - 1, 0, x); E.commit(); self.drawList(); } }));
        tools.appendChild(button('↓', 'Move down', function () { if (i < f.e.length - 1) { var x = f.e.splice(i, 1)[0]; f.e.splice(i + 1, 0, x); E.commit(); self.drawList(); } }));
        tools.appendChild(button('✕', 'Delete the rule', function () {
          if (!confirm('Delete the case rule «' + (T.sval(p[1], 'label') || id) + '»?\nDocuments that already used it keep what it did.')) return;
          f.e.splice(i, 1); E.commit(); self.sel = null; self.render(self.host);
        }));
        row.appendChild(tools);
        row.addEventListener('click', function () { self.select(id); });
        row.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target === row) self.select(id); });
        list.appendChild(row);
      });
      if (!f.e.length) list.appendChild(el('p', 'muted', 'No case rules. A case rule is a chip in the composer that switches sentences on or off, sets fields or adds text in one click, and undoes it when removed.'));
      var add = el('div', 'te-addrow');
      add.appendChild(button('+ New case rule', 'A new chip for the composer', function () { self.add(); }, 'te-add'));
      list.appendChild(add);
    },
    add: function () {
      var f = feats(E.root()), label = (prompt('The chip\'s label (as the composer shows it):', '') || '').trim();
      if (!label) return;
      var n = 1; while (T.get(f, 'rule' + n)) n++;
      var id = (prompt('An id for the rule (a-z, 0-9, _ and -; documents record it):', 'rule' + n) || '').trim();
      if (!id) return;
      if (!/^[a-z][a-z0-9_-]*$/.test(id)) { alert('Use a-z, 0-9, _ and -, starting with a letter.'); return; }
      if (T.get(f, id)) { alert('There is a rule "' + id + '" already.'); return; }
      f.e.push([id, {t: 'o', e: [['label', S(label)], ['ops', {t: 'a', e: []}]]}]);
      E.commit('Case rule ' + id + ' added'); this.select(id);
    },
    preview: function (on) {
      this.previewOn = on;
      window.dispatchEvent(new CustomEvent('ms-preview-features', {detail: on && this.sel ? [this.sel] : []}));
    },
    drawProps: function () {
      var self = this, root = E.root(), p = this.props; p.textContent = '';
      if (!this.sel) { p.appendChild(el('p', 'muted', 'Select a case rule, or make one.')); return; }
      var rule = T.get(feats(root), this.sel), ops = T.get(rule, 'ops');
      if (!ops) { ops = {t: 'a', e: []}; h.setKey(rule, 'ops', ops); }
      p.appendChild(el('h3', '', T.sval(rule, 'label') || this.sel));
      h.prop(p, rule, 'label', {label: 'Chip label', keep: true, hint: 'What the composer shows; the doctor adds the chip to a document and removes it again.'}, function () { self.drawList(); });
      var pv = el('label', 'te-prop te-prop-bool'); pv.appendChild(el('span', 'te-plabel', 'Preview with this rule'));
      var cb = el('input'); cb.type = 'checkbox'; cb.checked = this.previewOn;
      cb.addEventListener('change', function () { self.preview(cb.checked); });
      pv.appendChild(cb); pv.appendChild(el('span', 'te-hint', 'The preview applies the rule to the sample: what it turns off is struck through, what it turns on, adds or sets is green.'));
      p.appendChild(pv);
      p.appendChild(el('h4', 'te-subhead', 'What it does (in order)'));
      var idx = blockIndex(root), fl = h.fieldLabels(root), places = textPlaces(root), ul = el('ol', 'te-ops');
      arr(ops).forEach(function (op, i) { ul.appendChild(self.opRow(ops, i, op, idx, fl, places)); });
      if (!ops.e.length) p.appendChild(el('p', 'muted', 'No operations yet: add one below.'));
      p.appendChild(ul);
      var adds = el('div', 'te-addrow te-opadd');
      adds.appendChild(button('+ Turn on a sentence', '', function () { self.pickBlock('enable', ops); }, 'te-add'));
      adds.appendChild(button('+ Turn off a sentence', '', function () { self.pickBlock('disable', ops); }, 'te-add'));
      adds.appendChild(button('+ Set a field', '', function () { self.pickField(ops); }, 'te-add'));
      adds.appendChild(button('+ Add text', '', function () { self.pickPlace(ops, places); }, 'te-add'));
      p.appendChild(adds);
      p.appendChild(el('p', 'te-hint', 'A rule only sets what the doctor could set by hand. Text it adds is the doctor\'s own wording, written here; nothing is generated.'));
    },
    opRow: function (ops, i, op, idx, fl, places) {
      var self = this, li = el('li', 'te-op'), kind = T.sval(op, 'op'), line = el('div', 'te-opline'), missing = false;
      if (kind === 'enable' || kind === 'disable') {
        var bid = T.sval(op, 'block'), info = idx[bid];
        line.appendChild(el('strong', '', kind === 'enable' ? 'Turns on ' : 'Turns off '));
        if (info) {
          line.appendChild(el('span', 'te-optext', '«' + h.cut(info.text || '(empty)', 120) + '»'));
          line.appendChild(el('span', 'te-meta', ' ' + info.sec + (info.topic ? ' › ' + info.topic : '')));
          if (info.group) line.appendChild(el('div', 'te-hint', kind === 'enable' ? 'It becomes the choice of its group «' + info.group + '».' : 'It clears the choice of group «' + info.group + '» if this one is chosen.'));
        } else { missing = true; line.appendChild(el('span', 'te-missing', 'block ' + bid + ': missing target')); }
      } else if (kind === 'set') {
        var fid = T.sval(op, 'field');
        line.appendChild(el('strong', '', 'Sets the field '));
        if (fl[fid]) line.appendChild(el('span', 'te-chip', fl[fid])); else { missing = true; line.appendChild(el('span', 'te-missing', '@' + fid + ': missing field')); }
        line.appendChild(el('span', '', ' to '));
        var v = el('input', 'te-input te-opval'); v.type = 'text'; v.value = T.sval(op, 'value');
        v.addEventListener('input', function () { h.setKey(op, 'value', S(v.value)); E.commit(); });
        line.appendChild(v);
      } else if (kind === 'add') {
        var sid = T.sval(op, 'section'), place = places.filter(function (x) { return x.id === sid; })[0];
        line.appendChild(el('strong', '', 'Adds text to '));
        if (place) line.appendChild(el('span', 'te-meta', place.name)); else { missing = true; line.appendChild(el('span', 'te-missing', sid + ': missing section')); }
        var ta = el('textarea', 'te-input'); ta.rows = Math.min(8, Math.max(2, Math.ceil(T.sval(op, 'text').length / 80))); ta.value = T.sval(op, 'text');
        ta.addEventListener('input', function () { h.setKey(op, 'text', S(ta.value)); E.commit(); });
        line.appendChild(ta);
      } else line.appendChild(el('span', 'te-missing', 'Unknown operation ' + kind + ' (edit in the JSON tab)'));
      if (missing) li.classList.add('te-op-missing');
      li.appendChild(line);
      var tools = el('span', 'te-tools');
      tools.appendChild(button('↑', 'Earlier', function () { if (i > 0) { var x = ops.e.splice(i, 1)[0]; ops.e.splice(i - 1, 0, x); E.commit(); self.drawProps(); self.refreshPreview(); } }));
      tools.appendChild(button('↓', 'Later', function () { if (i < ops.e.length - 1) { var x = ops.e.splice(i, 1)[0]; ops.e.splice(i + 1, 0, x); E.commit(); self.drawProps(); self.refreshPreview(); } }));
      tools.appendChild(button('✕', 'Remove this operation', function () { ops.e.splice(i, 1); E.commit(); self.drawProps(); self.drawList(); self.refreshPreview(); }));
      li.appendChild(tools);
      return li;
    },
    refreshPreview: function () { if (this.previewOn) this.preview(true); },
    addOp: function (ops, pairs) { ops.e.push({t: 'o', e: pairs}); E.commit(); this.drawProps(); this.drawList(); this.refreshPreview(); },
    // a dialog listing every sentence, grouped by section, searchable
    pickBlock: function (kind, ops) {
      var self = this, idx = blockIndex(E.root()), dlg = el('dialog', 'te-dialog te-picker');
      dlg.appendChild(el('h3', '', kind === 'enable' ? 'Turn on which sentence?' : 'Turn off which sentence?'));
      var q = el('input', 'te-input'); q.type = 'search'; q.placeholder = 'Search the sentences…'; dlg.appendChild(q);
      var list = el('div', 'te-pick-list'); dlg.appendChild(list);
      var acts = el('div', 'actions'); acts.appendChild(button('Cancel', '', function () { dlg.close(); dlg.remove(); })); dlg.appendChild(acts);
      function draw() {
        list.textContent = ''; var term = q.value.trim().toLowerCase(), last = '';
        Object.keys(idx).forEach(function (id) {
          var x = idx[id], where = x.sec + (x.topic ? ' › ' + x.topic : '');
          if (term && (x.text + ' ' + where + ' ' + id).toLowerCase().indexOf(term) < 0) return;
          if (where !== last) { list.appendChild(el('div', 'te-pick-head', where)); last = where; }
          var b = button(h.cut(x.text || '(empty)', 160), id + (x.group ? ' · group ' + x.group : ''), function () {
            dlg.close(); dlg.remove(); self.addOp(ops, [['op', S(kind)], ['block', S(id)]]);
          }, 'te-pick');
          list.appendChild(b);
        });
      }
      q.addEventListener('input', draw);
      document.querySelector('.te').appendChild(dlg); dlg.showModal(); draw(); q.focus();
    },
    pickField: function (ops) {
      var fl = h.fieldLabels(E.root()), keys = Object.keys(fl);
      if (!keys.length) { alert('The type has no fields.'); return; }
      var dlg = el('dialog', 'te-dialog'), self = this;
      dlg.appendChild(el('h3', '', 'Set which field?'));
      var sel = el('select', 'te-input'); keys.forEach(function (k) { var o = el('option', '', fl[k] + ' (@' + k + ')'); o.value = k; sel.appendChild(o); });
      var val = el('input', 'te-input'); val.type = 'text'; val.placeholder = 'Value';
      var r1 = el('label', 'te-prop'); r1.appendChild(el('span', 'te-plabel', 'Field')); r1.appendChild(sel); dlg.appendChild(r1);
      var r2 = el('label', 'te-prop'); r2.appendChild(el('span', 'te-plabel', 'Value')); r2.appendChild(val); dlg.appendChild(r2);
      var acts = el('div', 'actions');
      acts.appendChild(button('Add', '', function () { dlg.close(); dlg.remove(); self.addOp(ops, [['op', S('set')], ['field', S(sel.value)], ['value', S(val.value)]]); }, 'te-primary'));
      acts.appendChild(button('Cancel', '', function () { dlg.close(); dlg.remove(); }));
      dlg.appendChild(acts);
      document.querySelector('.te').appendChild(dlg); dlg.showModal(); sel.focus();
    },
    pickPlace: function (ops, places) {
      if (!places.length) { alert('No section takes free text.'); return; }
      var dlg = el('dialog', 'te-dialog'), self = this;
      dlg.appendChild(el('h3', '', 'Add text where?'));
      var sel = el('select', 'te-input'); places.forEach(function (x) { var o = el('option', '', x.name); o.value = x.id; sel.appendChild(o); });
      var txt = el('textarea', 'te-input'); txt.rows = 4; txt.placeholder = 'The text, in the block markup (@field, {Ο|Η}, **bold**). Your own wording.';
      var r1 = el('label', 'te-prop'); r1.appendChild(el('span', 'te-plabel', 'Section')); r1.appendChild(sel); dlg.appendChild(r1);
      var r2 = el('label', 'te-prop'); r2.appendChild(el('span', 'te-plabel', 'Text')); r2.appendChild(txt); dlg.appendChild(r2);
      var acts = el('div', 'actions');
      acts.appendChild(button('Add', '', function () { if (!txt.value.trim()) { txt.focus(); return; } dlg.close(); dlg.remove(); self.addOp(ops, [['op', S('add')], ['section', S(sel.value)], ['text', S(txt.value)]]); }, 'te-primary'));
      acts.appendChild(button('Cancel', '', function () { dlg.close(); dlg.remove(); }));
      dlg.appendChild(acts);
      document.querySelector('.te').appendChild(dlg); dlg.showModal(); sel.focus();
    }
  };
  E.register('rules', view);
})();
