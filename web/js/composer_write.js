/*
 * The composer's Write view (docs/plans/composer-ui.md §4.2): the document as it will read. It is the same form as the
 * Form view, shown differently; it adds no form control, so what the form posts is the same in both views:
 *   - each block shows its rendered sentence (.cx-say, made on the server by web/sentences.php) instead of its textarea;
 *     the checkbox or radio stays in the margin; sentences that are off are dimmed and folded behind "Show N more";
 *   - a click on a sentence edits it with the type editor's chip editor (type_chips.js, with its self-check: a text
 *     that does not round-trip is edited as plain text); Done writes the block's textarea, Esc leaves it as it was;
 *     "Restore original" puts the type's text back (the server then stores no edit);
 *   - a value in a sentence is a chip; a click opens a small box bound to the real field of the Values list;
 *   - after a change of a value, a choice or the gender, the sentences and the checks are fetched again from
 *     POST /documents/{id}/sentences (saves nothing).
 * The view (Write or Form) is remembered in this browser.
 */
(function () {
  'use strict';
  var form = document.getElementById('composer-form');
  if (!form || !window.MSChips) return;
  var C = window.MSChips, locked = !!form.dataset.locked, known = {};
  try { known = JSON.parse(form.dataset.known || '{}'); } catch (e) {}
  var KEY = 'medscribe.composer.view';
  var el = function (tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };
  var blocks = function () { return [].slice.call(form.querySelectorAll('.block[data-block]')); };
  var ta = function (b) { return b.querySelector('textarea[name^="btext["]'); };
  var pick = function (b) { return b.querySelector('.block-pick input'); };

  /* ---------- views ---------- */
  function setView(v) {
    form.classList.toggle('cx-write', v === 'write');
    form.querySelectorAll('.cx-views [data-view]').forEach(function (b) { b.setAttribute('aria-selected', b.dataset.view === v ? 'true' : 'false'); });
    try { localStorage.setItem(KEY, v); } catch (e) {}
    if (v === 'write') {
      // the document reads top to bottom: every section open except optional ones switched off
      form.querySelectorAll('fieldset.section').forEach(function (f) {
        var opt = f.querySelector('input[name^="sections_on["]');
        f.classList.toggle('collapsed', !!(opt && !opt.checked));
      });
      fold();
    }
  }
  form.querySelectorAll('.cx-views [data-view]').forEach(function (b) { b.addEventListener('click', function () { setView(b.dataset.view); }); });

  /* ---------- on/off state, fold of the sentences that are off ---------- */
  function syncOn() {
    blocks().forEach(function (b) { var i = pick(b); if (i) { b.classList.toggle('on', i.checked); b.classList.toggle('off', !i.checked); } });
  }
  function fold() {
    form.querySelectorAll('fieldset.section').forEach(function (f) {
      var body = f.querySelector('.sec-body'); if (!body) return;
      var hide = [].slice.call(body.querySelectorAll(':scope > .block.off:not(.in-group)'));
      var btn = body.querySelector(':scope > .cx-more');
      if (f.dataset.unfolded || !hide.length) { if (btn) btn.remove(); body.querySelectorAll('.cx-folded').forEach(function (b) { b.classList.remove('cx-folded'); }); return; }
      hide.forEach(function (b) { b.classList.add('cx-folded'); });
      if (!btn) {
        btn = el('button', 'cx-more'); btn.type = 'button';
        btn.addEventListener('click', function () { f.dataset.unfolded = '1'; fold(); });
        var last = body.querySelector(':scope > .block:last-of-type');
        (last && last.nextSibling) ? body.insertBefore(btn, last.nextSibling) : body.appendChild(btn);
      }
      btn.textContent = '+ ' + hide.length + (hide.length === 1 ? ' more optional sentence' : ' more optional sentences');
    });
  }

  /* ---------- edited marker, restore ---------- */
  function syncEdited(b) {
    var t = ta(b); if (!t || t.dataset.original === undefined) return;
    var edited = t.value.replace(/\r/g, '').trim() !== t.dataset.original.trim();
    var m = b.querySelector('.edited');
    if (!m) { m = el('span', 'edited', 'edited for this document'); b.appendChild(m); }
    m.hidden = !edited;
    if (edited && !locked && !m.querySelector('.cx-restore')) {
      var r = el('button', 'cx-restore', 'Restore original'); r.type = 'button';
      r.addEventListener('click', function () { t.value = t.dataset.original; t.dispatchEvent(new Event('input', {bubbles: true})); syncEdited(b); refresh(true); });
      m.appendChild(document.createTextNode(' · ')); m.appendChild(r);
    }
  }

  /* ---------- editing a sentence ---------- */
  var editing = null;
  function startEdit(b) {
    if (locked || editing || b.closest('fieldset[disabled]')) return;
    var t = ta(b); if (!t) return;
    var before = t.value, text = before;
    var host = el('div', 'cx-editor te');
    var ed = C.editor(document, {text: before, known: known, id: b.dataset.block, onChange: function (x) { text = x; }});
    var bar = el('div', 'cx-ed-bar');
    var bold = el('button', 'ms-btn', 'B'); bold.type = 'button'; bold.title = 'Bold (Ctrl+B)'; bold.style.fontWeight = '800';
    bold.addEventListener('mousedown', function (e) { e.preventDefault(); ed.wrap('**', '**'); });
    var sel = el('select', 'ms-input cx-ed-field'); sel.setAttribute('aria-label', 'Insert a field');
    sel.appendChild(new Option('@ Field', ''));
    Object.keys(known).forEach(function (k) { sel.appendChild(new Option(known[k] || k, k)); });
    sel.addEventListener('change', function () { if (sel.value) { ed.focus(); ed.insertChip(sel.value); } sel.value = ''; });
    var done = el('button', 'ms-btn ms-primary', 'Done'); done.type = 'button';
    var cancel = el('button', 'ms-btn', 'Cancel'); cancel.type = 'button';
    var hint = el('span', 'ms-sub', 'Changes apply to this document only');
    bar.append(bold, sel, hint, cancel, done);
    host.append(ed.el, bar);
    b.classList.add('cx-editing');
    b.insertBefore(host, b.querySelector('.cx-say'));
    editing = {b: b, host: host};
    function finish(keep) {
      if (keep && text !== before) { t.value = text; t.dispatchEvent(new Event('input', {bubbles: true})); }
      host.remove(); b.classList.remove('cx-editing'); editing = null;
      syncEdited(b);
      if (keep && text !== before) refresh(true);
      var say = b.querySelector('.cx-say'); if (say) say.focus();
    }
    done.addEventListener('click', function () { finish(true); });
    cancel.addEventListener('click', function () { finish(false); });
    host.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.preventDefault(); finish(false); }
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); finish(true); }
    });
    ed.focus();
  }
  form.addEventListener('click', function (e) {
    if (!form.classList.contains('cx-write')) return;
    var chip = e.target.closest('.cx-say .cx-v');
    if (chip && !locked) { e.preventDefault(); openValue(chip); return; }
    var say = e.target.closest('.cx-say:not(.cx-say-latex)');
    if (say) startEdit(say.closest('.block'));
  });
  form.addEventListener('keydown', function (e) {
    var say = e.target.closest && e.target.closest('.cx-say:not(.cx-say-latex)');
    if (say && (e.key === 'Enter' || e.key === ' ') && form.classList.contains('cx-write')) { e.preventDefault(); startEdit(say.closest('.block')); }
  });

  /* ---------- values: a chip opens its field ---------- */
  var pop = null;
  function closeValue() { if (pop) { pop.remove(); pop = null; } }
  function openValue(chip) {
    closeValue();
    var id = chip.dataset.field, real = form.elements['fields[' + id + ']'];
    if (!real) return;
    var isDate = real.type === 'date' || (real._flatpickr && real.getAttribute('type') !== 'text');
    pop = el('div', 'cx-pop'); pop.setAttribute('role', 'dialog');
    var lab = el('label', 'ms-field', known[id] || id);
    var inp = el('input', 'ms-input'); inp.type = isDate ? 'date' : 'text'; inp.value = real.value;
    if (!isDate) { inp.placeholder = real.placeholder || ''; inp.maxLength = 1000; }
    lab.appendChild(inp);
    var ok = el('button', 'ms-btn ms-primary', 'Done'); ok.type = 'button';
    pop.append(lab, ok);
    chip.closest('.block').appendChild(pop);
    function put() {
      if (real._flatpickr) real._flatpickr.setDate(inp.value || null, false, 'Y-m-d'); else real.value = inp.value;
      real.dispatchEvent(new Event('input', {bubbles: true})); real.dispatchEvent(new Event('change', {bubbles: true}));
    }
    inp.addEventListener(isDate ? 'change' : 'input', put);
    ok.addEventListener('click', function () { put(); closeValue(); chip.focus && chip.focus(); });
    pop.addEventListener('keydown', function (e) { if (e.key === 'Escape' || e.key === 'Enter') { e.preventDefault(); put(); closeValue(); } });
    setTimeout(function () { var shown = inp._flatpickr && inp._flatpickr.altInput ? inp._flatpickr.altInput : inp; shown.focus(); }, 0);
  }
  document.addEventListener('mousedown', function (e) {
    if (pop && !pop.contains(e.target) && !e.target.closest('.cx-v') && !e.target.closest('.flatpickr-calendar')) closeValue();
  });

  /* ---------- the Values list: collapsed in the Write view, with a count ---------- */
  var vals = form.querySelector('fieldset.cx-values');
  function countValues() {
    if (!vals) return;
    var ins = [].slice.call(vals.querySelectorAll('input[name^="fields["]')), n = ins.filter(function (i) { return i.value.trim() !== ''; }).length;
    var c = vals.querySelector('.cx-values-count');
    if (!c) {
      c = el('button', 'cx-values-toggle'); c.type = 'button'; c.setAttribute('aria-expanded', 'false');
      c.addEventListener('click', function () { vals.classList.toggle('cx-open'); c.setAttribute('aria-expanded', vals.classList.contains('cx-open') ? 'true' : 'false'); });
      var span = el('span', 'cx-values-count'); c.appendChild(span);
      vals.querySelector('legend').appendChild(c);
    }
    vals.querySelector('.cx-values-count').textContent = n + ' of ' + ins.length + ' filled';
  }

  /* ---------- refresh the sentences and checks for the unsaved form ---------- */
  var timer = null, seq = 0;
  function refresh(now) {
    clearTimeout(timer);
    timer = setTimeout(function () {
      var my = ++seq, fd = new FormData(form);
      fd.delete('action');
      fetch(form.dataset.sentencesUrl, {method: 'POST', body: fd, credentials: 'same-origin'})
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (my !== seq || !j || !j.ok) return;
          blocks().forEach(function (b) {
            var s = j.blocks[b.dataset.block], say = b.querySelector('.cx-say');
            if (s && say) say.innerHTML = s.html;   // HTML made on the server from escaped text (web/sentences.php)
          });
          checks(j.checks || []);
        })
        .catch(function () {});
    }, now ? 0 : 350);
  }
  function checks(list) {
    var box = form.querySelector('.cx-checks'); if (!box) return;
    box.textContent = '';
    if (!list.length) { box.appendChild(el('span', 'cx-ok', '✓ All checks pass')); return; }
    var b = el('button', 'cx-checks-btn', '⚠ ' + list.length + (list.length === 1 ? ' needs attention' : ' need attention'));
    b.type = 'button'; b.setAttribute('aria-expanded', 'false'); b.setAttribute('aria-controls', 'cx-checks-list');
    var ul = el('ul', 'cx-checks-list'); ul.id = 'cx-checks-list'; ul.hidden = true;
    list.forEach(function (c) { ul.appendChild(el('li', 'check check-' + c.level, c.msg)); });
    b.addEventListener('click', function () { ul.hidden = !ul.hidden; b.setAttribute('aria-expanded', ul.hidden ? 'false' : 'true'); });
    box.append(b, ul);
  }
  var RELEVANT = /^(fields\[|patient\[|settings\[|blocks_on\[|groups\[|sections_on\[|btext\[)/;
  form.addEventListener('change', function (e) {
    var n = e.target.name || '';
    if (/^(blocks_on|groups)\[/.test(n)) { syncOn(); if (form.classList.contains('cx-write')) fold(); }
    if (/^fields\[/.test(n)) countValues();
    if (RELEVANT.test(n)) refresh();
  });
  form.addEventListener('input', function (e) {
    var n = e.target.name || '';
    if (/^fields\[/.test(n)) countValues();
    if (/^(fields|patient)\[/.test(n)) refresh();
    if (/^btext\[/.test(n)) syncEdited(e.target.closest('.block'));
  });

  blocks().forEach(syncEdited);
  countValues();
  var v = 'write'; try { v = localStorage.getItem(KEY) || 'write'; } catch (e) {}
  setView(v === 'form' ? 'form' : 'write');
})();
