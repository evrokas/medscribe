/*
 * The instruction text editor (docs/plans/type-editor-ui.md §4.3): a block's text with its fields shown as chips.
 *
 * The risk is damage to the text, so:
 *   - only whole, unescaped @tokens of known fields (or built-ins) become chips (type_tree.js's token rule);
 *     everything else is the text as typed, character for character;
 *   - the text is read back by walking the nodes (never innerText): text nodes as they are, a <br> = "\n", a chip = "@id";
 *   - SELF-CHECK: before a text is shown as chips, it is built and read straight back; unless that gives the same bytes,
 *     the block opens as a plain textarea. After every edit the same check runs on what would be written: if it fails,
 *     the row turns into a textarea holding what was typed, and nothing is written until it is edited there.
 * The core (split / join / unsafe) has no DOM and is tested with node (tests/js/type_chips_test.js); build/read are
 * checked in Chromium (tests/js/type_chips_dom.html via bin/check_chips_dom.js).
 */
(function () {
  'use strict';
  var T = typeof window !== 'undefined' && window.MSTypeTree ? window.MSTypeTree : (typeof require !== 'undefined' ? require('./type_tree.js') : null);

  // characters a contenteditable cannot be trusted to keep (browsers turn them into others): such a text stays plain
  var UNSAFE = new RegExp('[\\u00a0\\r\\u200b-\\u200f\\u2028\\u2029\\ufeff\\u0000-\\u0008\\u000b\\u000c\\u000e-\\u001f]');
  function unsafe(text) { return UNSAFE.test(text); }

  // text -> segments [{t:'text', v} | {t:'chip', id}], chips only for the names in `known`
  function split(text, known) {
    var out = [], re = T.anyTokenRe(), last = 0, m;
    while ((m = re.exec(text))) {
      if (!Object.prototype.hasOwnProperty.call(known, m[1])) continue;
      if (m.index > last) out.push({t: 'text', v: text.slice(last, m.index)});
      out.push({t: 'chip', id: m[1]});
      last = m.index + m[0].length;
    }
    if (last < text.length) out.push({t: 'text', v: text.slice(last)});
    return out;
  }
  function join(segs) { return segs.map(function (s) { return s.t === 'chip' ? '@' + s.id : s.v; }).join(''); }
  // the @names that are not fields of the type (shown as a warning, left as text)
  function unknownTokens(text, known) {
    var out = [], re = T.anyTokenRe(), m;
    while ((m = re.exec(text))) if (!Object.prototype.hasOwnProperty.call(known, m[1]) && out.indexOf(m[1]) < 0) out.push(m[1]);
    return out;
  }

  // the block markup inside a plain-text piece, by the engine's own rule (render_tokenize in web/render.php):
  // [{v, k}] covering the text exactly; k = text | esc | cspace | br | level | gender | bold | ital
  var MARKUP = /(\\[\\*@{}|]|\\ |\{br\}|\{v:[^{}]*\}|\{[^{}|]*\|[^{}]*\}|\*\*|\*)/;
  function markup(text) {
    return text.split(MARKUP).filter(function (x) { return x !== ''; }).map(function (x) {
      var k = x === '**' ? 'bold' : x === '*' ? 'ital' : x === '\\ ' ? 'cspace' : x === '{br}' ? 'br' : /^\\./.test(x) && x.length === 2 ? 'esc'
            : /^\{v:/.test(x) ? 'level' : /^\{[^{}|]*\|[^{}]*\}$/.test(x) ? 'gender' : 'text';
      return {v: x, k: k};
    });
  }

  // ---------- DOM ----------
  function chip(doc, id, label) {
    var c = doc.createElement('span');
    c.className = 'te-chip'; c.contentEditable = 'false'; c.setAttribute('data-token', id); c.tabIndex = 0;
    c.setAttribute('data-tip', '@' + id); c.title = '@' + id; c.setAttribute('aria-label', 'Field ' + label + ', @' + id);
    c.textContent = label;
    return c;
  }
  // fill an element with the text: text nodes, <br> for "\n", chips; a final "\n" gets an end marker <br> (a contenteditable
  // does not show a last line break otherwise) that read() skips
  // Markup is coloured (spans marked data-mk, read back as their text); the text between ** or * is shown bold or italic.
  function build(host, text, known) {
    var doc = host.ownerDocument, b = false, it = false;
    while (host.firstChild) host.removeChild(host.firstChild);
    function piece(v, cls) {
      v.split('\n').forEach(function (part, j) {
        if (j) host.appendChild(doc.createElement('br'));
        if (!part) return;
        var t = doc.createTextNode(part);
        if (!cls) { host.appendChild(t); return; }
        var sp = doc.createElement('span'); sp.setAttribute('data-mk', ''); sp.className = cls; sp.appendChild(t); host.appendChild(sp);
      });
    }
    function state() { return (b ? ' te-mk-b' : '') + (it ? ' te-mk-i' : ''); }
    split(text, known).forEach(function (s) {
      if (s.t === 'chip') { var c = chip(doc, s.id, known[s.id]); if (b) c.classList.add('te-mk-b'); if (it) c.classList.add('te-mk-i'); host.appendChild(c); return; }
      markup(s.v).forEach(function (m) {
        if (m.k === 'bold') { piece(m.v, 'te-mk te-mk-mark'); b = !b; return; }
        if (m.k === 'ital') { piece(m.v, 'te-mk te-mk-mark'); it = !it; return; }
        if (m.k === 'text') { piece(m.v, state().trim()); return; }
        piece(m.v, 'te-mk te-mk-' + m.k + state());
      });
    });
    if (/\n$/.test(text)) { var end = doc.createElement('br'); end.setAttribute('data-end', ''); host.appendChild(end); }
  }
  // element -> text. A block element a browser slipped in (a <div> on Enter, a <p>) is read by its text and flagged: the
  // caller then refuses the chip path for this edit
  function read(host) {
    var out = '', strange = false;
    (function walk(n) {
      for (var c = n.firstChild; c; c = c.nextSibling) {
        if (c.nodeType === 3) out += c.nodeValue;
        else if (c.nodeType === 1) {
          if (c.tagName === 'BR') { if (!c.hasAttribute('data-end') || c.nextSibling) out += '\n'; }
          else if (c.hasAttribute('data-token')) out += '@' + c.getAttribute('data-token');
          else if (c.hasAttribute('data-mk')) walk(c);
          // an inline wrapper the browser adds while typing next to coloured text (Chrome keeps the style that way):
          // only text inside, read as it is; the re-colouring after the edit removes it
          else if (/^(SPAN|FONT|B|I|STRONG|EM|U)$/.test(c.tagName) && !c.querySelector('div,p,li,table,img')) walk(c);
          else { strange = true; if (/^(DIV|P)$/.test(c.tagName) && out !== '') out += '\n'; walk(c); }
        }
      }
    })(host);
    return {text: out, strange: strange};
  }
  // the caret as an offset in the text (a chip counts as its @token, the end marker as nothing), and back
  function size(c) {
    if (c.nodeType === 3) return c.nodeValue.length;
    if (c.nodeType !== 1) return 0;
    if (c.tagName === 'BR') return c.hasAttribute('data-end') ? 0 : 1;
    if (c.hasAttribute('data-token')) return 1 + c.getAttribute('data-token').length;
    var n = 0; for (var k = c.firstChild; k; k = k.nextSibling) n += size(k); return n;
  }
  function offsetOf(host, node, off) {
    var n = 0, found = false;
    (function walk(p) {
      for (var i = 0, c = p.firstChild; c && !found; c = c.nextSibling, i++) {
        if (p === node && i === off) { found = true; return; }
        if (c === node && c.nodeType === 3) { n += off; found = true; return; }
        if (c.nodeType === 1 && c.hasAttribute('data-mk') && c.contains(node)) { walk(c); if (found) return; continue; }
        n += size(c);
      }
      if (p === node) found = true;
    })(host);
    return n;
  }
  function placeAt(host, n) {
    var doc = host.ownerDocument, r = doc.createRange(), done = false;
    (function walk(p) {
      for (var c = p.firstChild; c && !done; c = c.nextSibling) {
        if (c.nodeType === 3) { if (n <= c.nodeValue.length) { r.setStart(c, n); done = true; return; } n -= c.nodeValue.length; continue; }
        if (c.nodeType !== 1) continue;
        if (c.hasAttribute('data-mk')) { walk(c); continue; }
        var z = size(c);
        if (c.tagName === 'BR' && c.hasAttribute('data-end')) { r.setStartBefore(c); done = true; return; }
        if (n === 0) { r.setStartBefore(c); done = true; return; }
        if (n < z) { r.setStartAfter(c); done = true; return; }   // inside a chip: after it
        n -= z;
      }
    })(host);
    if (!done) { r.selectNodeContents(host); r.collapse(false); } else r.collapse(true);
    var sel = doc.getSelection(); sel.removeAllRanges(); sel.addRange(r);
  }

  // the self-check: does this text survive build -> read unchanged?
  function roundTrips(doc, text, known) {
    if (unsafe(text)) return false;
    var probe = doc.createElement('div');
    build(probe, text, known);
    var r = read(probe);
    return !r.strange && r.text === text;
  }

  /*
   * An editor for one text. opts: {text, known: {id: label}, onChange(text), id (for the warning)}.
   * Returns {el, focus(), insertText(s), insertChip(id), wrap(a, b), plain(bool), isPlain()}.
   */
  function editor(doc, opts) {
    var box = doc.createElement('div'); box.className = 'te-text';
    var ce = doc.createElement('div'); ce.className = 'te-ce'; ce.contentEditable = 'true'; ce.spellcheck = true;
    ce.setAttribute('role', 'textbox'); ce.setAttribute('aria-multiline', 'true');
    ce.style.whiteSpace = 'pre-wrap';   // without it a browser types spaces as U+00A0 (and the self-check turns the row plain)
    var ta = doc.createElement('textarea'); ta.className = 'te-input te-plain'; ta.spellcheck = true;
    var note = doc.createElement('div'); note.className = 'te-plain-note';
    var warn = doc.createElement('div'); warn.className = 'te-warn';
    box.appendChild(ce); box.appendChild(ta); box.appendChild(note); box.appendChild(warn);
    var text = opts.text, plain = false, forced = false;

    function sizeTa() { ta.rows = Math.min(14, Math.max(2, Math.ceil(ta.value.length / 80) + (ta.value.match(/\n/g) || []).length)); }
    function showWarn() {
      var u = unknownTokens(text, opts.known);
      warn.textContent = u.length ? 'Not a field of this type: @' + u.join(', @') + ' (left as text; the save check refuses unknown tokens)' : '';
    }
    function setPlain(on, why) {
      plain = on;
      ce.hidden = on; ta.hidden = !on;
      if (on) { ta.value = text; sizeTa(); }
      else build(ce, text, opts.known);
      note.textContent = why || '';
      note.hidden = !why;
    }
    function changed(t) { if (t === text) return; text = t; showWarn(); opts.onChange(t); }

    ce.addEventListener('input', function () {
      var r = read(ce);
      // what would be written must re-read to itself; otherwise stop here: plain text, nothing written
      if (r.strange || !roundTrips(doc, r.text, opts.known)) {
        if (typeof console !== 'undefined') console.warn('type editor: block ' + (opts.id || '?') + ': the edit could not be kept as chips; shown as plain text');
        forced = true;
        text = r.text;  // shown in the textarea for the doctor to check; not written until edited there
        setPlain(true, 'Shown as plain text: this edit cannot be shown with chips safely. Check the text below; it is saved once you edit it here.');
        return;
      }
      changed(r.text);
      if (!composing) refresh();
    });
    // re-colour after an edit, the caret kept at its place in the text (not while an accent is being composed)
    var composing = false;
    function refresh() {
      var sel = doc.getSelection && doc.getSelection(), off = null;
      if (sel && sel.rangeCount && ce.contains(sel.focusNode)) off = offsetOf(ce, sel.focusNode, sel.focusOffset);
      build(ce, text, opts.known);
      if (off !== null) placeAt(ce, off);
    }
    ce.addEventListener('compositionstart', function () { composing = true; });
    ce.addEventListener('compositionend', function () { composing = false; ce.dispatchEvent(new Event('input')); });
    ce.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); insertText('\n'); }   // our own <br>, never a browser <div>
      if ((e.ctrlKey || e.metaKey) && (e.key === 'b' || e.key === 'i')) { e.preventDefault(); wrap(e.key === 'b' ? '**' : '*', e.key === 'b' ? '**' : '*'); }
    });
    ce.addEventListener('paste', function (e) {   // plain text only, line ends normalised, then chips again
      e.preventDefault();
      var t = ((e.clipboardData || window.clipboardData).getData('text/plain') || '').replace(/\r\n?/g, '\n');
      insertText(t);
    });
    ce.addEventListener('drop', function (e) { e.preventDefault(); });
    ta.addEventListener('input', function () { forced = false; changed(ta.value); sizeTa(); });

    // the caret inside ce (or the end)
    function range() {
      var sel = doc.getSelection && doc.getSelection();
      if (sel && sel.rangeCount && ce.contains(sel.getRangeAt(0).commonAncestorContainer)) return sel.getRangeAt(0);
      var r = doc.createRange(); r.selectNodeContents(ce); r.collapse(false); return r;
    }
    function put(node) {
      var r = range(); r.deleteContents(); r.insertNode(node);
      r.setStartAfter(node); r.collapse(true);
      var sel = doc.getSelection(); sel.removeAllRanges(); sel.addRange(r);
    }
    function after() { ce.dispatchEvent(new Event('input')); }
    function insertText(s) {
      if (plain) { var a = ta.selectionStart, b = ta.selectionEnd; ta.value = ta.value.slice(0, a) + s + ta.value.slice(b); ta.selectionStart = ta.selectionEnd = a + s.length; ta.dispatchEvent(new Event('input')); return; }
      // through a text: a fresh build keeps <br>s and chips ours
      var frag = doc.createElement('div'); build(frag, s, opts.known);
      var last = null; while (frag.firstChild) { last = frag.firstChild; put(last); }
      if (/\n$/.test(s)) {   // a line break at the very end needs the end marker to show
        var r = read(ce); if (/\n$/.test(r.text)) { build(ce, r.text, opts.known); placeEnd(); }
      }
      after();
    }
    function placeEnd() { var r = doc.createRange(); r.selectNodeContents(ce); r.collapse(false); var sel = doc.getSelection(); sel.removeAllRanges(); sel.addRange(r); }
    function insertChip(id) {
      if (plain) return insertText('@' + id);
      put(chip(doc, id, opts.known[id] || id)); after();
    }
    function wrap(a, b) {
      if (plain) { var s0 = ta.selectionStart, s1 = ta.selectionEnd; ta.value = ta.value.slice(0, s0) + a + ta.value.slice(s0, s1) + b + ta.value.slice(s1); ta.selectionStart = s0 + a.length; ta.selectionEnd = s1 + a.length; ta.dispatchEvent(new Event('input')); return; }
      var r = range(), inner = doc.createElement('div'); inner.appendChild(r.cloneContents());
      var sel = read(inner).text;
      r.deleteContents();
      insertText(a + sel + b);
    }

    if (!roundTrips(doc, text, opts.known)) {
      if (typeof console !== 'undefined') console.warn('type editor: block ' + (opts.id || '?') + ' is shown as plain text (it does not round-trip as chips)');
      setPlain(true, 'Shown as plain text: this text cannot be shown with chips safely.');
    } else setPlain(false, '');
    showWarn();
    return {
      el: box, insertText: insertText, insertChip: insertChip, wrap: wrap,
      focus: function () { (plain ? ta : ce).focus(); },
      plain: function (on) { if (on) setPlain(true, ''); else if (!forced && roundTrips(doc, text, opts.known)) setPlain(false, ''); else return false; return true; },
      isPlain: function () { return plain; },
      text: function () { return text; }
    };
  }

  var api = {markup: markup, offsetOf: offsetOf, placeAt: placeAt, split: split, join: join, unsafe: unsafe, unknownTokens: unknownTokens, build: build, read: read, roundTrips: roundTrips, editor: editor};
  if (typeof window !== 'undefined') window.MSChips = api;
  if (typeof module !== 'undefined') module.exports = api;
})();
