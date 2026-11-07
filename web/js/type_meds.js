/*
 * The medication-table section editor (docs/plans/type-editor-ui.md §4.7), used by the Structure and Content tabs for a
 * `medtable` section: its drug library and its default rows (`default_rows`), with the library to copy rows from.
 * A default row is a copy of a library entry: later changes to the library do not change it.
 */
(function () {
  'use strict';
  var E = window.MSTypeEditor, T = window.MSTypeTree;
  if (!E || !T) return;
  var h = E.h, el = h.el, button = h.button, S = h.S, arr = h.arr;
  var COLS = ['name', 'dose', 'freq', 'days', 'notes'];
  var libs = {};
  try { libs = JSON.parse(document.querySelector('.te').getAttribute('data-libraries') || '{}'); } catch (e) {}

  // a cell: {br} shown as a new line (a value that already holds a real new line is edited as it is)
  function cell(row, key) {
    var td = el('td'), raw = T.sval(row, key), plain = /\n/.test(raw), ta = el('textarea', 'te-cell');
    ta.value = plain ? raw : raw.replace(/\{br\}/g, '\n'); ta.rows = Math.max(1, ta.value.split('\n').length);
    ta.setAttribute('aria-label', key);
    ta.addEventListener('input', function () { h.setKey(row, key, S(plain ? ta.value : ta.value.replace(/\r?\n/g, '{br}'))); E.commit(); ta.rows = Math.max(1, ta.value.split('\n').length); });
    td.appendChild(ta); return td;
  }

  function form(host, sec, after) {
    var box = el('div', 'te-meds');
    host.appendChild(box);
    function draw() {
      box.textContent = '';
      // the library
      var names = Object.keys(libs), cur = T.sval(sec, 'library');
      var lr = el('label', 'te-prop'); lr.appendChild(el('span', 'te-plabel', 'Drug library'));
      var sel = el('select', 'te-input');
      names.concat(cur && names.indexOf(cur) < 0 ? [cur] : []).forEach(function (n) { var o = el('option', '', (libs[n] ? libs[n].label + ' ' : '') + '(' + n + ')'); o.value = n; sel.appendChild(o); });
      sel.value = cur;
      sel.addEventListener('change', function () { h.setKey(sec, 'library', S(sel.value)); E.commit(); if (after) after(); draw(); });
      lr.appendChild(sel);
      lr.appendChild(el('span', 'te-hint', names.length ? 'The composer offers its entries; a library is a shared component the type pins.' : 'The type pins no drug library (the "shared" map in the JSON tab).'));
      box.appendChild(lr);
      // the default rows
      var rows = T.get(sec, 'default_rows');
      box.appendChild(el('h4', 'te-subhead', 'Default rows (' + (rows ? rows.e.length : 0) + ')'));
      box.appendChild(el('p', 'te-hint', 'Every new document starts with these rows; the composer edits them per document. In a cell, a new line prints as a line break.'));
      var cols = el('div', 'te-meds-cols'), left = el('div', 'te-meds-rows'), right = el('div', 'te-meds-lib');
      cols.appendChild(left); cols.appendChild(right); box.appendChild(cols);
      var table = el('table', 'list te-drugs'), head = el('tr');
      COLS.concat(['']).forEach(function (c) { head.appendChild(el('th', '', c ? c[0].toUpperCase() + c.slice(1) : '')); });
      table.appendChild(head);
      arr(rows).forEach(function (r, i) {
        var tr = el('tr');
        COLS.forEach(function (k) { tr.appendChild(cell(r, k)); });
        var td = el('td', 'te-tools');
        td.appendChild(button('↑', 'Move up', function () { if (i > 0) { var x = rows.e.splice(i, 1)[0]; rows.e.splice(i - 1, 0, x); E.commit(); draw(); } }));
        td.appendChild(button('↓', 'Move down', function () { if (i < rows.e.length - 1) { var x = rows.e.splice(i, 1)[0]; rows.e.splice(i + 1, 0, x); E.commit(); draw(); } }));
        td.appendChild(button('✕', 'Remove the row', function () { rows.e.splice(i, 1); if (!rows.e.length) h.delKey(sec, 'default_rows'); E.commit(); draw(); }));
        tr.appendChild(td);
        table.appendChild(tr);
      });
      if (rows && rows.e.length) left.appendChild(table); else left.appendChild(el('p', 'muted', 'No default rows: the table starts empty (it is printed only when it has rows).'));
      left.appendChild(button('+ Empty row', 'A row to fill in by hand', function () { addRow({}); }, 'te-add'));
      // the library to copy from
      var lib = libs[T.sval(sec, 'library')];
      if (lib) {
        right.appendChild(el('div', 'te-meds-libhead', lib.label + ' · ' + lib.drugs.length + ' entries'));
        var q = el('input', 'te-input te-search'); q.type = 'search'; q.placeholder = 'Search…'; right.appendChild(q);
        var list = el('div', 'te-meds-list'); right.appendChild(list);
        var drawLib = function () {
          list.textContent = ''; var term = q.value.trim().toLowerCase(), groups = {};
          lib.drugs.forEach(function (d) {
            if (term && COLS.concat(['group']).map(function (k) { return d[k] || ''; }).join(' ').toLowerCase().indexOf(term) < 0) return;
            (groups[d.group || ''] = groups[d.group || ''] || []).push(d);
          });
          Object.keys(groups).forEach(function (g) {
            list.appendChild(el('div', 'te-pick-head', (g || '(no group)') + ' · ' + groups[g].length));
            groups[g].forEach(function (d) {
              list.appendChild(button('+ ' + [d.name, d.dose, d.freq].filter(Boolean).join(' · '), 'Copy as a default row' + (d.notes ? ': ' + d.notes : ''), function () { addRow(d); }, 'te-pick'));
            });
          });
        };
        q.addEventListener('input', drawLib); drawLib();
        right.appendChild(el('p', 'te-hint', 'A default row is a copy: later library changes do not change it.'));
      }
    }
    function addRow(d) {
      var rows = T.get(sec, 'default_rows');
      if (!rows) { rows = {t: 'a', e: []}; h.setKey(sec, 'default_rows', rows); }
      rows.e.push({t: 'o', e: COLS.map(function (k) { return [k, S(String(d[k] || ''))]; })});
      E.commit(); draw();
    }
    draw();
  }
  window.MSMeds = {form: form};
})();
