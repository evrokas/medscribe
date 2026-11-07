/*
 * Fold and unfold the sections of the input form (the composer, and the type editor's Form preview, whose HTML
 * arrives later: hence one listener on the document). A click on a section's legend toggles it; "Expand all" /
 * "Collapse all" act on every section of the same form. The server decides how each section starts
 * (doc_view_model: folded when it has items and none is picked).
 */
(function () {
  'use strict';
  document.addEventListener('click', function (e) {
    var all = e.target.closest('[data-sec-all]');
    if (all) {
      e.preventDefault();
      var scope = all.closest('form, .pv-body') || document;
      scope.querySelectorAll('fieldset.section').forEach(function (f) { f.classList.toggle('collapsed', all.dataset.secAll === 'close'); });
      return;
    }
    var leg = e.target.closest('fieldset.section > legend.sec-toggle');
    if (!leg || e.target.closest('input, select, textarea, button, a')) return;
    leg.parentNode.classList.toggle('collapsed');
  });
  // typing Enter/Space on a focused legend works like a click
  document.addEventListener('keydown', function (e) {
    var leg = e.target.closest && e.target.closest('fieldset.section > legend.sec-toggle');
    if (leg && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); leg.parentNode.classList.toggle('collapsed'); }
  });
})();
