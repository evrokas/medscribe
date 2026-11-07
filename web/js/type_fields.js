/*
 * The Fields tab of the type editor (docs/plans/type-editor-ui.md §4.4): the type's fields with their uses, a side editor,
 * and the rename dialog with a dry run. Renames go through type_tree.js's renameField (whole unescaped @tokens and the
 * case-rule operations), the same rule as bin/rename_fields.php.
 */
(function () {
  'use strict';
  var E = window.MSTypeEditor, T = window.MSTypeTree;
  if (!E || !T) return;
  var h = E.h, el = h.el, button = h.button, S = h.S, arr = h.arr;

  function fieldsObj(root) { var f = T.get(root, 'fields'); if (!f) { f = {t: 'o', e: []}; h.setKey(root, 'fields', f); } return f; }

  // where a field is used: [{where, text, src, block}] for texts, plus case-rule operations
  function usesOf(root, name) {
    var re = T.tokenRe(name), out = [], secs = T.sections(root);
    T.walk(root, function (n, path) {
      if (n.t !== 's') return;
      var m = n.v.match(re); if (!m) return;
      var where = path.join('/'), src = '', block = '';
      for (var i = path.length; i >= 1; i--) {
        var k = T.kindOf(path.slice(0, i));
        if (k === 'block') { var b = nodeAt(root, path.slice(0, i)); block = T.sval(b, 'id'); src = 'block:' + block; break; }
      }
      if (path[0] === 'sections') {
        var sec = secs[path[1]];
        where = h.sectionName(sec, root, E.sharedLabels()) + (block ? ' · ' + block : ' · ' + path.slice(2).join('/'));
      } else if (path[0] === 'features') where = 'Case rule «' + T.sval(nodeAt(root, path.slice(0, 2)), 'label') + '»';
      else where = path.join(' › ');
      out.push({where: where, text: n.v, n: m.length, src: src, block: block});
    });
    T.allOps(root).forEach(function (o) { if (T.sval(o, 'field') === name) out.push({where: 'Case rule: sets the field', text: 'value «' + T.sval(o, 'value') + '»', n: 1, src: '', block: ''}); });
    return out;
  }
  function nodeAt(root, path) { var n = root; path.forEach(function (k) { n = typeof k === 'number' ? n.e[k] : T.get(n, k); }); return n; }
  // the escaped or longer look-alikes a rename leaves alone
  function leftAlone(root, name) {
    var out = [], esc = new RegExp('\\\\@' + name + '(?![a-z0-9_])', 'g'), longer = new RegExp('(?<![\\\\\\p{L}\\p{N}_@])@' + name + '[a-z0-9_]+', 'gu');
    T.walk(root, function (n) { if (n.t !== 's') return; (n.v.match(esc) || []).forEach(function (x) { out.push(x); }); (n.v.match(longer) || []).forEach(function (x) { out.push(x); }); });
    return out;
  }

  var view = {
    sel: null,
    count: function (root) { var f = T.get(root, 'fields'); return f ? f.e.length : 0; },
    render: function (host, sel) {
      if (sel && sel.src) { var m = /^field:(.*)$/.exec(sel.src); if (m) this.sel = m[1]; }
      else if (!this.sel) this.sel = E.recall('field');
      var f = fieldsObj(E.root());
      if (this.sel && !T.get(f, this.sel)) this.sel = null;
      if (!this.sel && f.e.length) this.sel = f.e[0][0];
      host.textContent = '';
      var wrap = el('div', 'te-split te-fields');
      this.list = el('div', 'te-list'); this.props = el('div', 'te-props');
      wrap.appendChild(this.list); wrap.appendChild(this.props); host.appendChild(wrap);
      this.drawList(); this.drawProps();
    },
    select: function (k) { this.sel = k; E.remember('field', k); this.drawList(); this.drawProps(); },
    drawList: function () {
      var self = this, root = E.root(), f = fieldsObj(root), list = this.list;
      list.textContent = '';
      f.e.forEach(function (p, i) {
        var k = p[0], fd = p[1], uses = T.fieldUses(root, k);
        var row = el('div', 'te-item te-field' + (self.sel === k ? ' on' : '')); row.tabIndex = 0;
        var nm = el('span', 'te-name');
        nm.appendChild(el('span', '', T.sval(fd, 'label') || k));
        if (h.bval(fd, 'required')) nm.appendChild(el('span', 'te-req', 'required'));
        nm.appendChild(el('code', 'te-key', '@' + k));
        row.appendChild(nm);
        row.appendChild(el('span', 'te-meta', T.sval(fd, 'type') || 'text'));
        var u = el('span', 'te-uses' + (uses ? '' : ' te-unused'), uses ? 'used ' + uses + '×' : 'unused');
        u.title = uses ? '@' + k + ' in the texts and case-rule operations' : 'No text prints this field';
        row.appendChild(u);
        var tools = el('span', 'te-tools');
        tools.appendChild(button('↑', 'Move up (the order of the form)', function () { if (i > 0) { var x = f.e.splice(i, 1)[0]; f.e.splice(i - 1, 0, x); E.commit(); self.drawList(); } }));
        tools.appendChild(button('↓', 'Move down', function () { if (i < f.e.length - 1) { var x = f.e.splice(i, 1)[0]; f.e.splice(i + 1, 0, x); E.commit(); self.drawList(); } }));
        tools.appendChild(button('✕', 'Delete the field', function () {
          if (!confirm('Delete the field «' + (T.sval(fd, 'label') || k) + '» (@' + k + ')?' + (uses ? '\nIt is still used ' + uses + '×: those @' + k + ' would be unknown tokens and the save check refuses them.' : ''))) return;
          f.e.splice(i, 1); E.commit(); self.sel = null; self.render(self.list.parentNode.parentNode);
        }));
        row.appendChild(tools);
        row.addEventListener('click', function () { self.select(k); });
        row.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target === row) self.select(k); });
        list.appendChild(row);
      });
      if (!f.e.length) list.appendChild(el('p', 'muted', 'No fields yet. A field is a value the composer asks for and the texts print with @name.'));
      var add = el('div', 'te-addrow');
      add.appendChild(button('+ Add field', 'A new field (written @name in the texts)', function () { self.add(); }, 'te-add'));
      list.appendChild(add);
    },
    add: function () {
      var root = E.root(), f = fieldsObj(root);
      var k = (prompt('Key of the new field (a-z, 0-9 and _, starting with a letter; written @key in the texts):', '') || '').trim();
      if (!k) return;
      var bad = keyProblem(root, k, null); if (bad) { alert(bad); return; }
      f.e.push([k, {t: 'o', e: [['type', S('text')], ['label', S('')], ['placeholder', S('')]]}]);
      E.commit('Field @' + k + ' added'); this.select(k);
      var first = this.props.querySelector('.te-input:not(.te-ro)'); if (first) first.focus();
    },
    drawProps: function () {
      var self = this, root = E.root(), p = this.props; p.textContent = '';
      if (!this.sel) { p.appendChild(el('p', 'muted', 'Select a field.')); return; }
      var k = this.sel, fd = T.get(fieldsObj(root), k);
      p.appendChild(el('h3', '', T.sval(fd, 'label') || k));
      var after = function () { self.drawList(); };
      h.prop(p, fd, 'label', {label: 'Label', keep: true, hint: 'The name of the box on the input form; chips in the Content tab show it.'}, after);
      var kr = el('div', 'te-prop'); kr.appendChild(el('span', 'te-plabel', 'Key'));
      var ki = el('input', 'te-input te-ro'); ki.readOnly = true; ki.value = '@' + k; kr.appendChild(ki);
      kr.appendChild(button('Rename…', 'Rename the field: every @' + k + ' and case-rule operation follows', function () { renameDialog(k, function (nk) { self.sel = nk; E.remember('field', nk); self.drawList(); self.drawProps(); }); }));
      p.appendChild(kr);
      h.prop(p, fd, 'type', {label: 'Type', type: 'select', options: ['text', 'date', 'number'], keep: true}, function () { self.drawList(); self.drawProps(); });
      if (T.sval(fd, 'type') === 'date') {
        var df = h.prop(p, fd, 'datefmt', {label: 'Date format', hint: 'How the date prints everywhere it is used (it wins over a block\'s format). Empty = the block\'s format, else d/m/Y. ' +
          T.DATEFMTS.map(function (d) { return d[0] + ' → ' + d[1]; }).join(', ') + '; or any PHP date() pattern.'});
        df.setAttribute('list', 'tt-datefmts');
      }
      h.prop(p, fd, 'default', {label: 'Default', hint: T.sval(fd, 'type') === 'date' ? 'YYYY-MM-DD' : 'Filled in on a new document.'});
      h.prop(p, fd, 'placeholder', {label: 'Placeholder', hint: 'Grey hint in the empty box.'});
      h.prop(p, fd, 'required', {label: 'Required', type: 'bool', hint: 'A document cannot be issued without it.'}, after);
      h.advanced(p, fd, ['label', 'type', 'datefmt', 'default', 'placeholder', 'required']);
      // where it is used
      var uses = usesOf(root, k);
      p.appendChild(el('h4', 'te-subhead', 'Used in (' + uses.reduce(function (a, u) { return a + u.n; }, 0) + ')'));
      if (!uses.length) p.appendChild(el('p', 'te-warn', 'Not used: no text prints @' + k + '. Insert it with @ Field in the Content tab, or delete the field.'));
      var ul = el('ul', 'te-uselist');
      uses.forEach(function (u) {
        var li = el('li');
        li.appendChild(el('span', 'te-meta', u.where));
        li.appendChild(el('div', 'te-usetext', h.cut(h.plainText(u.text, h.fieldLabels(root)), 140)));
        if (u.block) { li.classList.add('te-link'); li.title = 'Open in the Content tab'; li.addEventListener('click', function () { E.show('content', {src: u.src}); }); }
        ul.appendChild(li);
      });
      p.appendChild(ul);
    }
  };

  function keyProblem(root, k, old) {
    if (!/^[a-z][a-z0-9_]*$/.test(k)) return 'A field key uses a-z, 0-9 and _, starting with a small Latin letter (it is written @' + k + ' in the texts).';
    if (T.BUILTINS.indexOf(k) >= 0) return '@' + k + ' is a built-in value (' + T.BUILTINS.join(', ') + ').';
    if (k !== old && T.get(fieldsObj(root), k)) return 'The type already has a field @' + k + '.';
    return '';
  }

  // the rename dialog: new key -> dry run on a copy -> rename everywhere
  function renameDialog(old, done) {
    var root = E.root(), dlg = el('dialog', 'te-dialog te-rename');
    dlg.appendChild(el('h3', '', 'Rename the field @' + old));
    var row = el('label', 'te-prop'); row.appendChild(el('span', 'te-plabel', 'New key'));
    var inp = el('input', 'te-input'); inp.type = 'text'; inp.value = old; inp.spellcheck = false; row.appendChild(inp);
    dlg.appendChild(row);
    var out = el('div', 'te-dry'); dlg.appendChild(out);
    dlg.appendChild(el('p', 'te-hint', 'Documents already made keep their own type version and values: nothing changes for them.'));
    var acts = el('div', 'actions');
    var go = button('Rename everywhere', '', function () {
      var nk = inp.value.trim(), bad = keyProblem(root, nk, old); if (bad || nk === old) return;
      var c = T.renameField(root, old, nk);
      var f = fieldsObj(root); f.e.forEach(function (p) { if (p[0] === old) p[0] = nk; });
      E.commit('@' + old + ' → @' + nk + ': ' + c + ' use(s) updated');
      dlg.close(); dlg.remove(); done(nk);
    }, 'te-primary');
    acts.appendChild(go);
    acts.appendChild(button('Cancel', '', function () { dlg.close(); dlg.remove(); }));
    dlg.appendChild(acts);
    function dry() {
      var nk = inp.value.trim(); out.textContent = '';
      var bad = nk === old ? 'Type the new key.' : keyProblem(root, nk, old);
      go.disabled = !!bad;
      if (bad) { out.appendChild(el('p', 'te-warn', bad)); return; }
      // the real function, on a copy
      var copy = T.clone(root), before = [], after = [];
      T.walk(copy, function (n, path) { if (n.t === 's') before.push([path.join('/'), n.v]); });
      var ops = T.allOps(copy).filter(function (o) { return T.sval(o, 'field') === old; }).length;
      var c = T.renameField(copy, old, nk);
      T.walk(copy, function (n, path) { if (n.t === 's') after.push([path.join('/'), n.v]); });
      var texts = c - ops, changed = [];
      after.forEach(function (a, i) { if (a[1] !== before[i][1]) changed.push(a[1]); });
      out.appendChild(el('p', '', 'Changes: ' + texts + ' @' + old + ' token(s) in ' + changed.length + ' text(s), ' + ops + ' case-rule operation(s) (' + c + ' in all).'));
      var ul = el('ul', 'te-uselist');
      changed.slice(0, 30).forEach(function (t) { ul.appendChild(el('li', 'te-usetext', h.cut(t.replace(/\s+/g, ' '), 160))); });
      if (changed.length > 30) ul.appendChild(el('li', 'muted', '… and ' + (changed.length - 30) + ' more'));
      out.appendChild(ul);
      var left = leftAlone(root, old);
      out.appendChild(el('p', 'te-hint', left.length ? 'Left as they are (not this field): ' + left.join(', ') : 'Nothing else looks like @' + old + ' (an escaped \\@' + old + ' or a longer @' + old + '_x would be left alone).'));
    }
    inp.addEventListener('input', dry);
    inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); if (!go.disabled) go.click(); } });
    document.querySelector('.te').appendChild(dlg);
    dlg.showModal(); inp.select(); dry();
  }

  E.register('fields', view);
  if (typeof module !== 'undefined') module.exports = {usesOf: usesOf, leftAlone: leftAlone};
})();
