/*
 * Splitter between two panes (the composer's form and PDF; the type editor and its preview).
 * Markup: <div class="splitpane" data-split-key="..."> <div class="split-left"> <div class="composer-splitter">
 * with two buttons data-dir=left|right </div> <div class="split-right"> </div>. Drag the bar (20%-80%), or use
 * the buttons to step through left only / both / right only; double-click resets; arrow keys move it.
 * The layout is remembered per browser under data-split-key.
 */
(function () {
  'use strict';
  function init(comp) {
    var sp = comp.querySelector(':scope > .composer-splitter'); if (!sp) return;
    var KEY = comp.dataset.splitKey || 'medscribe.split', def = parseFloat(comp.dataset.splitDefault || '52');
    var states = ['left-max', 'split', 'right-max'], layout = {state: 'split', split: def};
    try { var saved = JSON.parse(localStorage.getItem(KEY) || 'null'); if (saved && states.indexOf(saved.state) >= 0 && isFinite(saved.split)) layout = saved; } catch (e) {}
    function apply() {
      comp.classList.remove('left-max', 'right-max');
      if (layout.state !== 'split') comp.classList.add(layout.state);
      comp.style.setProperty('--split', layout.split + '%');
      sp.querySelector('[data-dir=left]').disabled = layout.state === 'right-max';
      sp.querySelector('[data-dir=right]').disabled = layout.state === 'left-max';
      sp.setAttribute('aria-valuenow', layout.state === 'split' ? Math.round(layout.split) : (layout.state === 'right-max' ? 0 : 100));
      try { localStorage.setItem(KEY, JSON.stringify(layout)); } catch (e) {}
    }
    function step(dir) { var i = states.indexOf(layout.state) + (dir === 'left' ? 1 : -1); layout.state = states[Math.max(0, Math.min(2, i))]; apply(); }
    sp.querySelectorAll('button').forEach(function (b) {
      b.addEventListener('pointerdown', function (e) { e.stopPropagation(); });
      b.addEventListener('click', function () { step(b.dataset.dir); });
    });
    sp.addEventListener('pointerdown', function (e) {
      if (layout.state !== 'split') return;
      e.preventDefault(); sp.setPointerCapture(e.pointerId); comp.classList.add('dragging');
      var r = comp.getBoundingClientRect(), cs = getComputedStyle(comp), left = r.left + parseFloat(cs.paddingLeft), width = r.width - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
      function move(ev) { layout.split = Math.max(20, Math.min(80, (ev.clientX - left) / width * 100)); comp.style.setProperty('--split', layout.split + '%'); }
      function up() { comp.classList.remove('dragging'); sp.removeEventListener('pointermove', move); sp.removeEventListener('pointerup', up); sp.removeEventListener('pointercancel', up); apply(); }
      sp.addEventListener('pointermove', move); sp.addEventListener('pointerup', up); sp.addEventListener('pointercancel', up);
    });
    sp.addEventListener('dblclick', function () { layout = {state: 'split', split: def}; apply(); });
    sp.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
      e.preventDefault();
      if (layout.state !== 'split') { step(e.key === 'ArrowLeft' ? 'left' : 'right'); return; }
      layout.split = Math.max(20, Math.min(80, layout.split + (e.key === 'ArrowLeft' ? -3 : 3))); apply();
    });
    apply();
  }
  function all() { document.querySelectorAll('.splitpane').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
