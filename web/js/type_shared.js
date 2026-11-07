/*
 * The Content and Used-by tabs of a shared component (docs/plans/type-editor-ui.md §4.6). A component holds either
 * sentences (`blocks`: the alternatives of one choice group, e.g. the "purpose" sentences) or a drug library (`drugs`).
 * Sentences that a type uses as its default choice cannot be deleted or renamed here (the server's check stays as is).
 * Used by is rendered by the server (type_edit.zetem); this file only registers it.
 */
(function () {
  'use strict';
  var E = window.MSTypeEditor, T = window.MSTypeTree, C = window.MSChips;
  if (!E || !T) return;
  var h = E.h, el = h.el, button = h.button, S = h.S, arr = h.arr;
  var usedBy = [];
  try { usedBy = JSON.parse(document.querySelector('.te').getAttribute('data-used-by') || '[]'); } catch (e) {}
  var BUILTIN = {patient: 'Patient name', age: 'Age', amka: 'AMKA', clinic: 'Clinic', date: 'Document date'};
  function defaultIn(id) { return usedBy.filter(function (u) { return u.defaults.indexOf(id) >= 0; }).map(function (u) { return u.type; }); }

  var content = {
    count: function () { return ''; },
    render: function (host) {
      var root = E.root(); host.textContent = '';
      h.prop(host, root, 'label', {label: 'Name', keep: true, hint: 'How the types\' sections that use it are named in the editor.'});
      if (T.get(root, 'drugs')) this.drugs(host, root); else this.blocks(host, root);
      h.advanced(host, root, ['label', 'blocks', 'drugs']);
    },

    // ---------- sentences ----------
    blocks: function (host, root) {
      var self = this, list = T.get(root, 'blocks');
      if (!list) { list = {t: 'a', e: []}; h.setKey(root, 'blocks', list); }
      host.appendChild(el('p', 'te-hint', 'These sentences are alternatives: a section that uses the component prints at most one of them, the type\'s default choice unless the composer picks another.'));
      var box = el('div', 'te-blocks te-group');
      list.e.forEach(function (b, i) {
        var id = T.sval(b, 'id'), types = defaultIn(id);
        var row = el('div', 'te-block'); row.setAttribute('data-block', id);
        row.appendChild(el('span', 'te-ic', (i + 1) + '.'));
        var main = el('div', 'te-block-main');
        var meta = el('div', 'te-flags');
        meta.appendChild(el('code', 'te-did', id));
        meta.appendChild(document.createTextNode(types.length ? ' · default in ' + types.length + ' type' + (types.length === 1 ? '' : 's') + ': ' + types.join(', ') : ' · default in no type'));
        main.appendChild(meta);
        if (C) {
          var ed = C.editor(document, {text: T.sval(b, 'text'), known: BUILTIN, id: id, onChange: function (t) { h.setKey(b, 'text', S(t)); E.commit(); }});
          main.appendChild(ed.el);
        }
        row.appendChild(main);
        var tools = el('div', 'te-tools');
        tools.appendChild(button('↑', 'Move up', function () { if (i > 0) { var x = list.e.splice(i, 1)[0]; list.e.splice(i - 1, 0, x); E.commit(); self.render(host); } }));
        tools.appendChild(button('↓', 'Move down', function () { if (i < list.e.length - 1) { var x = list.e.splice(i, 1)[0]; list.e.splice(i + 1, 0, x); E.commit(); self.render(host); } }));
        tools.appendChild(button('✎', 'Rename the id', function () {
          if (types.length) { alert('«' + id + '» is the default choice of: ' + types.join(', ') + '.\nRenaming it would break those types. Change their default first.'); return; }
          var nv = (prompt('New id for "' + id + '":', id) || '').trim(); if (!nv || nv === id) return;
          if (!/^[a-z][a-z0-9_]*$/.test(nv)) { alert('Use a-z, 0-9 and _, starting with a letter.'); return; }
          if (list.e.some(function (x) { return T.sval(x, 'id') === nv; })) { alert('"' + nv + '" exists already.'); return; }
          T.get(b, 'id').v = nv; E.commit('Renamed'); self.render(host);
        }));
        tools.appendChild(button('✕', 'Delete', function () {
          if (types.length) { alert('«' + id + '» is the default choice of: ' + types.join(', ') + '.\nIt cannot be deleted while they use it.'); return; }
          if (!confirm('Delete this sentence?')) return;
          list.e.splice(i, 1); E.commit(); self.render(host);
        }));
        row.appendChild(tools);
        box.appendChild(row);
      });
      host.appendChild(box);
      var add = el('div', 'te-addrow');
      add.appendChild(button('+ Sentence', 'A new alternative at the end', function () {
        var n = 1, ids = list.e.map(function (x) { return T.sval(x, 'id'); }), base = (T.sval(root, 'name') || 'item').replace(/-/g, '_') + '_';
        while (ids.indexOf(base + n) >= 0) n++;
        var nv = (prompt('An id for the new sentence (types name it as their default choice):', base + n) || '').trim(); if (!nv) return;
        if (!/^[a-z][a-z0-9_]*$/.test(nv) || ids.indexOf(nv) >= 0) { alert('Use a new id of a-z, 0-9 and _.'); return; }
        list.e.push({t: 'o', e: [['id', S(nv)], ['text', S('')]]}); E.commit(); self.render(host);
        var last = host.querySelector('.te-block:last-of-type .te-ce'); if (last) last.focus();
      }, 'te-add'));
      host.appendChild(add);
    },

    // ---------- the drug library ----------
    drugs: function (host, root) {
      var self = this, list = T.get(root, 'drugs'), COLS = ['name', 'dose', 'freq', 'days', 'notes'];
      host.appendChild(el('p', 'te-hint', 'The library the composer offers in a medication table. A type\'s default rows are copies: changing an entry here does not change them. In a cell, a new line is printed as a line break ({br}).'));
      var q = el('input', 'te-input te-search'); q.type = 'search'; q.placeholder = 'Search the library…'; q.value = this.q || '';
      host.appendChild(q);
      var table = el('table', 'list te-drugs'), head = el('tr');
      ['Group'].concat(COLS.map(function (c) { return c[0].toUpperCase() + c.slice(1); })).concat(['']).forEach(function (c) { head.appendChild(el('th', '', c)); });
      table.appendChild(head);
      // a cell: {br} shown as a new line; a cell that already holds a real new line is edited as it is
      function cell(row, key) {
        var td = el('td'), raw = T.sval(row, key), plain = /\n/.test(raw), ta = el('textarea', 'te-cell');
        ta.value = plain ? raw : raw.replace(/\{br\}/g, '\n');
        ta.rows = Math.max(1, ta.value.split('\n').length);
        ta.addEventListener('input', function () {
          var v = plain ? ta.value : ta.value.replace(/\r?\n/g, '{br}');
          h.setKey(row, key, S(v)); E.commit();
          ta.rows = Math.max(1, ta.value.split('\n').length);
        });
        td.appendChild(ta); return td;
      }
      var lastGroup = null;
      list.e.forEach(function (row, i) {
        var g = T.sval(row, 'group');
        var text = COLS.concat(['group', 'id']).map(function (k) { return T.sval(row, k); }).join(' ').toLowerCase();
        if (self.q && text.indexOf(self.q.toLowerCase()) < 0) return;
        if (g !== lastGroup) { var gh = el('tr', 'te-drug-group'); var th = el('th', '', g || '(no group)'); th.colSpan = COLS.length + 2; gh.appendChild(th); table.appendChild(gh); lastGroup = g; }
        var tr = el('tr'); tr.title = T.sval(row, 'id');
        tr.appendChild(cell(row, 'group'));
        COLS.forEach(function (k) { tr.appendChild(cell(row, k)); });
        var td = el('td', 'te-tools');
        td.appendChild(button('↑', 'Move up', function () { if (i > 0) { var x = list.e.splice(i, 1)[0]; list.e.splice(i - 1, 0, x); E.commit(); self.render(host); } }));
        td.appendChild(button('↓', 'Move down', function () { if (i < list.e.length - 1) { var x = list.e.splice(i, 1)[0]; list.e.splice(i + 1, 0, x); E.commit(); self.render(host); } }));
        td.appendChild(button('⧉', 'Duplicate (new id)', function () { var c = T.clone(row); h.setKey(c, 'id', S(self.freshId(list, T.sval(row, 'id')))); list.e.splice(i + 1, 0, c); E.commit(); self.render(host); }));
        td.appendChild(button('✕', 'Delete', function () { if (confirm('Delete ' + T.sval(row, 'name') + ' ' + T.sval(row, 'dose') + '?')) { list.e.splice(i, 1); E.commit(); self.render(host); } }));
        tr.appendChild(td);
        table.appendChild(tr);
      });
      host.appendChild(table);
      q.addEventListener('input', function () { self.q = q.value; var pos = q.selectionStart; self.render(host); var nq = host.querySelector('.te-search'); nq.focus(); nq.setSelectionRange(pos, pos); });
      var add = el('div', 'te-addrow');
      add.appendChild(button('+ Drug', 'A new entry at the end', function () {
        var last = list.e[list.e.length - 1], pairs = [['id', S(self.freshId(list, 'drug'))]];
        ['group'].concat(COLS).forEach(function (k) { pairs.push([k, S(k === 'group' && last ? T.sval(last, 'group') : '')]); });
        list.e.push({t: 'o', e: pairs}); E.commit(); self.q = ''; self.render(host);
      }, 'te-add'));
      host.appendChild(add);
    },
    freshId: function (list, base) {
      var ids = list.e.map(function (x) { return T.sval(x, 'id'); }), b = base.replace(/-\d+$/, ''), n = 2;
      while (ids.indexOf(b + '-' + n) >= 0) n++;
      return b + '-' + n;
    }
  };
  E.register('content', content);
  E.register('usedby', {count: function () { return usedBy.length; }, render: function () {}});
})();
