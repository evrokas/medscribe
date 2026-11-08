/*
 * The composer's page shell (docs/plans/composer-ui.md §4.1, §4.5): patient banner and details line (summaries kept in
 * step with their fields, Edit opens the fields in place), the "Unsaved changes" marker and the leave-page warning, the
 * action bar's save state, the checks list. It never changes what the form posts: every control stays in the form.
 */
(function () {
  'use strict';
  var form = document.getElementById('composer-form');
  if (!form) return;
  var $ = function (sel, root) { return (root || form).querySelector(sel); };
  var val = function (name) { var e = form.elements[name]; return e ? String(e.value || '').trim() : ''; };

  // Edit buttons open and close their fields in place
  form.querySelectorAll('.cx-toggle').forEach(function (b) {
    b.addEventListener('click', function () {
      var box = document.getElementById(b.getAttribute('aria-controls')), open = box.hidden;
      box.hidden = !open; b.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { var first = box.querySelector('input:not([type=hidden]), select'); if (first) first.focus(); }
    });
  });
  var cb = $('.cx-checks-btn');
  if (cb) cb.addEventListener('click', function () {
    var l = document.getElementById('cx-checks-list'); l.hidden = !l.hidden; cb.setAttribute('aria-expanded', l.hidden ? 'false' : 'true');
  });

  // the banner and the details line say what the fields hold
  function summaries() {
    var sub = document.getElementById('cx-p-sub');
    if (sub) {
      var d = sub.dataset, g = val('patient[gender]'), age = val('patient[age]'), amka = val('patient[amka]'), name = val('patient[name]');
      var parts = [];
      if (g) parts.push(g === 'M' ? d.m : d.f);
      if (age) parts.push(/^\d+$/.test(age) ? age + ' ' + d.years : age);
      parts.push(amka ? 'AMKA ' + amka : d.noamka);
      parts.push(d.src);
      sub.textContent = parts.join(' · ');
      var nm = document.getElementById('cx-p-name');
      if (name) nm.textContent = name;
      document.getElementById('cx-initials').textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map(function (w) { return w.charAt(0); }).join('').toUpperCase();
      var box = document.getElementById('cx-patient');
      document.getElementById('cx-gender-warn').hidden = !(box.dataset.gr && !g && (name || !box.dataset.optional));
    }
    var sum = document.getElementById('cx-d-sum'), s = sum.dataset, out = [];
    var date = val('settings[date]'), m = /^(\d{4})-(\d\d)-(\d\d)$/.exec(date);
    out.push(val('settings[clinic]'));
    if (m) out.push(m[3] + '/' + m[2] + '/' + m[1]);
    if (val('settings[title]')) out.push('«' + val('settings[title]') + '»');
    if (val('settings[recipient]')) out.push(s.to + ' ' + val('settings[recipient]'));
    if (val('settings[closing]')) out.push(s.closing + ' «' + val('settings[closing]') + '»');
    out.push(val('settings[stamp]') === 'yes' ? s.yes : s.no);
    sum.textContent = out.filter(Boolean).join(' · ');
  }
  summaries();

  // unsaved changes: the marker, the bar's state, and a warning before leaving the page
  var dirty = false, submitting = false, state = document.getElementById('cx-state');
  function markDirty() {
    summaries();
    if (dirty) return;
    dirty = true;
    document.getElementById('cx-dirty').hidden = false;
    if (state) state.textContent = state.dataset.unsaved;
  }
  form.addEventListener('input', markDirty);
  form.addEventListener('change', markDirty);
  window.addEventListener('beforeunload', function (e) {
    if (dirty && !submitting) { e.preventDefault(); e.returnValue = ''; }
  });
  form.addEventListener('submit', function (e) {
    submitting = true;
    var b = e.submitter;
    if (state) state.textContent = b && b.value === 'preview' ? state.dataset.building : state.dataset.saving;
    if (b && b.value === 'preview') setTimeout(function () { form.classList.add('building'); }, 0);
  });
  // a case feature chip submits the form too (its own buttons); the delete form is separate and asks first
  window.MSComposer = {markDirty: markDirty, isDirty: function () { return dirty; }};
})();
