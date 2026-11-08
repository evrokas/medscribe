/*
 * PDFs on phones and small screens (reported 2026-11-08: a PDF inside an iframe showed only its first page on mobile and
 * could not be scrolled). On a small or touch screen, an <iframe data-pdf-pages data-pdfjs="<vendor/pdfjs/ url>"> is
 * replaced by every page of the PDF drawn with PDF.js (web/vendor/pdfjs, 4.10.38, eval off), fitted to the pane's width,
 * plus an "Open PDF" link to the device's own viewer. Desktop browsers keep their built-in viewer (zoom, print).
 * Pages are drawn when the pane is visible (the Preview tab), and again when its width changes.
 */
(function () {
  'use strict';
  if (!window.matchMedia || !window.matchMedia('(max-width: 900px), (pointer: coarse)').matches) return;
  var frames = [].slice.call(document.querySelectorAll('iframe[data-pdf-pages]'));
  if (!frames.length) return;
  var lib = null;
  function load(base) {
    if (!lib) lib = import(base + 'pdf.min.js').then(function (m) { m.GlobalWorkerOptions.workerSrc = base + 'pdf.worker.min.js'; return m; });
    return lib;
  }
  frames.forEach(function (f) {
    var url = f.getAttribute('src'), base = f.dataset.pdfjs;
    var box = document.createElement('div'); box.className = 'pdf-pages';
    var bar = document.createElement('div'); bar.className = 'pdf-pages-bar';
    var open = document.createElement('a'); open.href = url; open.target = '_blank'; open.rel = 'noopener'; open.textContent = 'Open PDF';
    var info = document.createElement('span'); info.className = 'ms-sub'; info.textContent = 'Loading…';
    bar.append(info, open);
    var pages = document.createElement('div'); pages.className = 'pdf-pages-list';
    box.append(bar, pages);
    f.replaceWith(box);
    var doc = null, drawnAt = 0, busy = false;
    function draw() {
      var w = pages.clientWidth;
      if (!w || busy || Math.abs(w - drawnAt) < 8) return;
      busy = true;
      (doc ? Promise.resolve(doc) : load(base).then(function (m) {
        return m.getDocument({url: url, isEvalSupported: false, withCredentials: true}).promise;
      })).then(function (d) {
        doc = d; drawnAt = w; pages.textContent = '';
        info.textContent = d.numPages + (d.numPages === 1 ? ' page' : ' pages');
        var dpr = Math.min(window.devicePixelRatio || 1, 3), chain = Promise.resolve();
        for (var n = 1; n <= d.numPages; n++) (function (n) {
          var c = document.createElement('canvas'); c.setAttribute('aria-label', 'Page ' + n); pages.appendChild(c);
          chain = chain.then(function () { return d.getPage(n); }).then(function (p) {
            var v1 = p.getViewport({scale: 1}), v = p.getViewport({scale: (w / v1.width) * dpr});
            c.width = Math.floor(v.width); c.height = Math.floor(v.height); c.style.width = w + 'px';
            return p.render({canvasContext: c.getContext('2d'), viewport: v}).promise;
          });
        })(n);
        return chain;
      }).catch(function () {
        info.textContent = 'The preview cannot be shown here.';
      }).then(function () { busy = false; });
    }
    if (window.ResizeObserver) new ResizeObserver(draw).observe(pages);
    window.addEventListener('resize', draw);
    draw();
  });
})();
