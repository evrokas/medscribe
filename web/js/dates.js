/*
 * Dates everywhere in the app (decided 2026-11-08): every <input type="date"> gets a flatpickr calendar
 * (web/vendor/flatpickr, served from here) that shows the date as dd/mm/yyyy and accepts dd/mm/yy or dd/mm/yyyy typed
 * by hand; <input type="time"> and "datetime-local" use the 24-hour clock. The original input keeps its name and its ISO
 * value (Y-m-d), so what a form posts does not change. Times are the browser's, and the app's server time is
 * Europe/Athens (settings.info.yaml `tz`). Inputs added later are picked up too (MutationObserver), or call
 * window.MSDates.attach(root).
 */
(function () {
  'use strict';
  if (!window.flatpickr) return;   // the library did not load: the browser's own date inputs stay
  var two = function (n) { return (n < 10 ? '0' : '') + n; };
  // "dd/mm/yy", "dd/mm/yyyy", "d.m.yyyy", "d-m-yy", or ISO "yyyy-mm-dd"; a two-digit year is 20yy up to ten years ahead, else 19yy
  function parse(s) {
    s = String(s || '').trim();
    var m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(s), d, mo, y;
    if (m) { y = +m[1]; mo = +m[2]; d = +m[3]; }
    else {
      m = /^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2}|\d{4})$/.exec(s);
      if (!m) return undefined;
      d = +m[1]; mo = +m[2]; y = +m[3];
      if (m[3].length === 2) y += (2000 + y <= new Date().getFullYear() + 10) ? 2000 : 1900;
    }
    var dt = new Date(y, mo - 1, d);
    return (dt.getFullYear() === y && dt.getMonth() === mo - 1 && dt.getDate() === d) ? dt : undefined;
  }
  function attachOne(el) {
    if (el._flatpickr || el.dataset.msNoPicker !== undefined) return;
    var type = (el.getAttribute('type') || '').toLowerCase(), base = {
      allowInput: !el.readOnly, disableMobile: true, time_24hr: true, locale: {firstDayOfWeek: 1}, monthSelectorType: 'static',
      onReady: function (sel, str, fp) {
        if (!fp.altInput) return;
        fp.altInput.placeholder = type === 'time' ? 'hh:mm' : (type === 'date' ? 'dd/mm/yyyy' : 'dd/mm/yyyy hh:mm');
        if (el.id) { fp.altInput.id = el.id + '-shown'; var lb = document.querySelector('label[for="' + el.id + '"]'); if (lb) lb.htmlFor = fp.altInput.id; }
        fp.altInput.setAttribute('aria-label', el.getAttribute('aria-label') || (el.closest('label') ? el.closest('label').textContent.trim() : 'date'));
        // typed text is read when the field is left (and on Enter), not on every key
        fp.altInput.addEventListener('blur', function () { var v = fp.altInput.value.trim(); if (v === '') { if (el.value !== '') fp.clear(); } else fp.setDate(v, true, fp.config.altFormat); });
      }
    };
    if (type === 'date') {
      flatpickr(el, Object.assign(base, {dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y', altInputClass: el.className + ' ms-date',
        parseDate: function (s) { return parse(s); },
        formatDate: function (d, f) { return f === 'Y-m-d' ? d.getFullYear() + '-' + two(d.getMonth() + 1) + '-' + two(d.getDate()) : two(d.getDate()) + '/' + two(d.getMonth() + 1) + '/' + d.getFullYear(); }}));
    } else if (type === 'time') {
      flatpickr(el, Object.assign(base, {enableTime: true, noCalendar: true, dateFormat: 'H:i', altInput: true, altFormat: 'H:i', altInputClass: el.className + ' ms-date'}));
    } else if (type === 'datetime-local') {
      flatpickr(el, Object.assign(base, {enableTime: true, dateFormat: 'Y-m-d\\TH:i', altInput: true, altFormat: 'd/m/Y H:i', altInputClass: el.className + ' ms-date'}));
    }
    if (el.disabled && el._flatpickr && el._flatpickr.altInput) el._flatpickr.altInput.disabled = true;
  }
  function attach(root) {
    (root || document).querySelectorAll('input[type=date], input[type=time], input[type=datetime-local]').forEach(attachOne);
  }
  window.MSDates = {attach: attach, parse: parse};
  attach(document);
  new MutationObserver(function (list) {
    list.forEach(function (m) { m.addedNodes.forEach(function (n) { if (n.nodeType === 1) attach(n.matches && n.matches('input') ? n.parentNode : n); }); });
  }).observe(document.body, {childList: true, subtree: true});
})();
