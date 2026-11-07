/*
 * The type editor's preview panel: the unsaved definition (the JSON text box, which the tree keeps in step)
 * shown as the composer's input form ("Form") or as the printed document in HTML ("Print"). The server
 * renders both (POST /types/typedef/{name}/preview); it refreshes a moment after each edit.
 */
(function () {
  'use strict';
  var panel = document.querySelector('.type-preview'); if (!panel) return;
  var ta = document.querySelector('.type-edit textarea[name=text]'), form = ta.form;
  var body = panel.querySelector('.pv-body'), msgs = panel.querySelector('.pv-msgs'), status = panel.querySelector('.pv-status');
  var gender = panel.querySelector('.pv-gender'), KEY = 'medscribe.typeedit.preview';
  var mode = 'print', timer = null, seq = 0, pending = null, features = [];
  // the Case rules tab asks for a preview with its rule applied (an empty list = without)
  window.addEventListener('ms-preview-features', function (e) {
    features = (e.detail || []).slice();
    if (features.length && mode !== 'print') setMode('print'); else refresh();
  });
  try { mode = localStorage.getItem(KEY) || 'print'; } catch (e) {}
  function el(tag, cls, text) { var x = document.createElement(tag); if (cls) x.className = cls; if (text !== undefined) x.textContent = text; return x; }
  // one message; "Go to source" when the issue knows where it was found (plan §6.1)
  function msg(level, text, src) {
    var d = el('div', 'check check-' + level, text);
    if (src) {
      var go = el('button', 'tt-btn pv-goto', 'Go to source'); go.type = 'button'; go.title = src;
      go.addEventListener('click', function () { window.dispatchEvent(new CustomEvent('ms-reveal', {detail: src})); });
      d.appendChild(go);
    }
    return d;
  }
  function show(r) {
    msgs.textContent = '';
    // the server's validation names its place as "block <id>: …" / "section <id>: …" / "field <id>: …"
    (r.errors || []).forEach(function (m) { var w = /^(block|section|topic|field) ([^\s:]+):/.exec(m); msgs.appendChild(msg('error', m, w ? w[1] + ':' + w[2] : '')); });
    (r.checks || []).forEach(function (c) { msgs.appendChild(msg(c.level, c.msg, c.src)); });
    var lints = r.lints || [], notes = lints.filter(function (l) { return l.level === 'note'; });
    lints.filter(function (l) { return l.level !== 'note'; }).forEach(function (l) { msgs.appendChild(msg('warn', l.msg, l.src)); });
    if (notes.length) {
      var det = el('details', 'pv-notes'); det.appendChild(el('summary', '', notes.length + ' note' + (notes.length === 1 ? '' : 's')));
      notes.forEach(function (l) { det.appendChild(msg('note', l.msg, l.src)); });
      msgs.appendChild(det);
    }
    if (!r.ok) return;   // keep the last good preview under the errors
    status.textContent = 'updated ' + new Date().toLocaleTimeString();
    body.innerHTML = mode === 'form' ? '<fieldset disabled class="form-preview">' + r.html + '</fieldset>'
      : '<div class="pv-paper">' + r.html + '</div><p class="pv-approx muted">HTML approximation: no letterhead, QR code or page breaks.' + (pdfBtn ? ' The PDF tab is the reference.' : '') + '</p>';
    body.appendChild(target); place(null);
    if (r.marks) {   // a preview with case rules: what they turn off is struck through, what they turn on, add or set is green
      var m = r.marks;
      (m.del || []).forEach(function (x) { body.querySelectorAll('[data-src="' + cssq(x) + '"]').forEach(function (e) { e.classList.add('pv-del'); }); });
      (m.ins || []).concat(m.set || []).forEach(function (x) { body.querySelectorAll('[data-src="' + cssq(x) + '"]').forEach(function (e) { e.classList.add('pv-ins'); }); });
      var lg = el('div', 'pv-legend', 'With the case rule' + (features.length > 1 ? 's' : '') + ': ');
      lg.appendChild(el('span', 'pv-del', 'turned off')); lg.appendChild(document.createTextNode(' · '));
      lg.appendChild(el('span', 'pv-ins', 'turned on, added or set'));
      if (!(m.del || []).length && !(m.ins || []).length && !(m.set || []).length) lg.appendChild(document.createTextNode(' · no visible change with the sample values'));
      body.insertBefore(lg, body.firstChild);
    }
    if (pending) { var p = pending; pending = null; highlight(p); }
  }
  function refresh() {
    clearTimeout(timer);
    if (mode === 'pdf') { pdfView(); return; }
    var my = ++seq, fd = new FormData(form);
    fd.set('text', ta.value); fd.set('mode', mode); fd.set('gender', gender.value);
    features.forEach(function (f) { fd.append('features[]', f); });
    status.textContent = '…';
    fetch(panel.dataset.url, {method: 'POST', body: fd, credentials: 'same-origin'})
      .then(function (r) { return r.json(); })
      .then(function (r) { if (my !== seq) return; status.textContent = ''; show(r); })
      .catch(function () { if (my === seq) status.textContent = 'not reachable'; });
  }
  function later() { if (mode === 'pdf') { pdfStale(); return; } clearTimeout(timer); timer = setTimeout(refresh, 700); }
  // ---------- the PDF tab (plan §6.4): built on request (a XeLaTeX run takes ~12 s), marked stale after an edit ----------
  var pdfBtn = panel.querySelector('[data-mode=pdf]'), pdf = {url: '', text: '', gender: '', busy: false};
  function pdfStale() { var n = body.querySelector('.pv-pdf-note'); if (n && pdf.url && (pdf.text !== ta.value || pdf.gender !== gender.value)) n.textContent = 'The text changed since this PDF was built: build it again to see the change.'; }
  function pdfView() {
    msgs.textContent = ''; body.textContent = '';
    var bar = el('div', 'pv-pdf-bar'), b = el('button', 'tt-btn pv-pdf-build', pdf.busy ? 'Building…' : (pdf.url ? 'Build again' : 'Build the test PDF'));
    b.type = 'button'; b.disabled = pdf.busy;
    b.addEventListener('click', buildPdf);
    bar.appendChild(b);
    bar.appendChild(el('span', 'pv-pdf-note muted', pdf.url ? '' : 'The unsaved text with the sample values, compiled as a composer preview (draft mark, QR as an empty frame). Takes about 12 s.'));
    body.appendChild(bar);
    if (pdf.url) { var f = el('iframe', 'pv-pdf-frame'); f.src = pdf.url; f.title = 'Test PDF'; body.appendChild(f); }
    pdfStale();
  }
  function buildPdf() {
    if (pdf.busy) return;
    pdf.busy = true; pdfView();
    var fd = new FormData(form), t0 = Date.now(), sent = ta.value, g = gender.value;
    fd.set('text', sent); fd.set('gender', g);
    status.textContent = 'building the PDF…';
    fetch(panel.dataset.pdfUrl, {method: 'POST', body: fd, credentials: 'same-origin'})
      .then(function (r) { return r.json(); })
      .then(function (r) {
        pdf.busy = false;
        if (!r.ok) { status.textContent = ''; if (mode === 'pdf') { pdfView(); msgs.appendChild(msg('error', r.error || 'failed')); } return; }
        pdf.url = r.url; pdf.text = sent; pdf.gender = g;
        status.textContent = 'PDF built in ' + Math.round((Date.now() - t0) / 100) / 10 + ' s (' + r.passes + ' passes)';
        if (mode === 'pdf') pdfView();
      })
      .catch(function () { pdf.busy = false; status.textContent = 'not reachable'; if (mode === 'pdf') pdfView(); });
  }
  function setMode(m) {
    mode = m;
    panel.querySelectorAll('[data-mode]').forEach(function (b) { b.classList.toggle('active', b.dataset.mode === m); });
    try { localStorage.setItem(KEY, m); } catch (e) {}
    refresh();
  }
  panel.querySelectorAll('[data-mode]').forEach(function (b) { b.addEventListener('click', function () { setMode(b.dataset.mode); }); });
  panel.querySelector('.pv-refresh').addEventListener('click', refresh);
  // a target icon on the printed text under the mouse: it selects where that text comes from (tree or JSON)
  var target = document.createElement('button');
  target.type = 'button'; target.className = 'pv-target'; target.textContent = '\u2316'; target.hidden = true;
  target.title = 'Show the source of this text in the editor';
  body.appendChild(target);
  // The icon must be easy to reach: it stays a moment after the mouse leaves the text, stays while the mouse is on it,
  // and moves to other text only once the mouse rests there (so crossing other text on the way does not move it).
  var hovered = null, switchT = null, hideT = null;
  function place(elm) {
    clearTimeout(switchT); clearTimeout(hideT);
    hovered = elm;
    if (!elm) { target.hidden = true; return; }
    var r = elm.getClientRects()[0] || elm.getBoundingClientRect(), b = body.getBoundingClientRect();
    target.style.top = (r.top - b.top + body.scrollTop + Math.min(r.height, 22) / 2 - 11) + 'px';
    target.style.left = Math.max(0, r.left - b.left + body.scrollLeft - 24) + 'px';
    target.title = 'Show in the editor: ' + elm.dataset.src;
    target.hidden = false;
  }
  function hideSoon(ms) { clearTimeout(switchT); clearTimeout(hideT); hideT = setTimeout(function () { place(null); }, ms || 900); }
  // the icon follows the mouse at once; it waits only while the mouse is heading left towards the icon close by
  var lastX = 0, headingToIcon = false;
  body.addEventListener('mousemove', function (e) {
    var t = target.getBoundingClientRect();
    headingToIcon = !target.hidden && e.clientX < lastX && e.clientX - t.right < 80 && Math.abs(e.clientY - (t.top + t.height / 2)) < 30;
    lastX = e.clientX;
  });
  body.addEventListener('mouseover', function (e) {
    if (e.target === target) { clearTimeout(switchT); clearTimeout(hideT); return; }
    var elm = e.target.closest('[data-src]');
    if (!elm) { if (hovered) hideSoon(headingToIcon ? 900 : 250); return; }
    clearTimeout(hideT);
    if (elm === hovered) { clearTimeout(switchT); return; }
    if (target.hidden || !headingToIcon) { place(elm); return; }
    clearTimeout(switchT); switchT = setTimeout(function () { place(elm); }, 300);
  });
  target.addEventListener('mouseleave', hideSoon);
  body.addEventListener('mouseleave', function (e) { if (e.relatedTarget !== target) hideSoon(); });
  body.addEventListener('scroll', function () { place(null); });
  target.addEventListener('click', function (e) {
    e.preventDefault(); e.stopPropagation();
    if (!hovered) return;
    pick(hovered);
    window.dispatchEvent(new CustomEvent('ms-reveal', {detail: hovered.dataset.src}));
  });
  function cssq(x) { return window.CSS && CSS.escape ? CSS.escape(x) : x; }
  function pick(elm) {
    body.querySelectorAll('.pv-picked').forEach(function (x) { x.classList.remove('pv-picked'); });
    if (elm) elm.classList.add('pv-picked');
  }
  // the other way: the target icon of a tree row highlights its text in the preview
  window.addEventListener('ms-show', function (e) {
    if ((mode === 'form' && !body.querySelector('[data-src="' + cssq(e.detail) + '"]')) || mode === 'pdf') { pending = e.detail; setMode('print'); return; }
    highlight(e.detail);
  });
  function highlight(src) {
    var all = body.querySelectorAll('[data-src="' + cssq(src) + '"]');
    pick(null);
    if (!all.length) { status.textContent = mode === 'print' ? 'not printed with the sample values' : 'not on the form'; setTimeout(function () { status.textContent = ''; }, 4000); return; }
    all.forEach(function (x) { x.classList.add('pv-picked'); });
    var r = all[0].getBoundingClientRect(), b = body.getBoundingClientRect();
    body.scrollTop += r.top - b.top - body.clientHeight / 3;
  }
  gender.addEventListener('change', refresh);
  ta.addEventListener('input', later); ta.addEventListener('tt-change', later);
  setMode(mode === 'form' ? 'form' : mode === 'pdf' && pdfBtn ? 'pdf' : 'print');
})();
