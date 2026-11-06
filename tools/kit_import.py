#!/usr/bin/env python3
"""One-off importer (PLAN.md §10): propose a MedScribe type definition from a kit template (tpl-*.tex) and a
matching regression fixture. Usage: tools/kit_import.py <kit template name without tpl->  [...]
Writes types/<name>.json and tests/fixtures/auto/<name>.tex (the kit file with every [placeholder] filled) and
adds the case to tests/fixtures/auto/cases.json. Anything it cannot express as markup becomes a trusted
`format: latex` block, listed under "needs_review" in the type. The doctor reviews each imported type."""
import re, sys, json, os
KIT = os.path.expanduser('~/devel/docs/template-kit/templates/')
GREEK = re.compile('[\u0370-\u03ff\u1f00-\u1fff]')
PURPOSE = {
 "Το παρόν χορηγείται για χρήση στην ασφαλιστική εταιρεία του ασθενούς.": "purpose_insurer",
 "Το παρόν χορηγείται για χρήση από τον ασφαλιστικό φορέα του ασθενούς.": "purpose_fund",
 "Το παρόν χορηγείται για την εργασία του ασθενούς.": "purpose_work",
 "Το παρόν χορηγείται μετά από αίτηση του ασθενούς, για την υπηρεσία του.": "purpose_request",
 "Το παρόν χορηγείται για χρήση στο διαγνωστικό κέντρο.": "purpose_center",
 "Το παρόν χορηγείται για κάθε νόμιμη χρήση.": "purpose_any",
 "Η παρούσα βεβαίωση δίνεται κατόπιν επιθυμίας του ασθενούς.": "purpose_wish"}

def strip_comment(s):
    # drop an unescaped trailing % comment
    out = ''; i = 0
    while i < len(s):
        if s[i] == '\\' and i + 1 < len(s): out += s[i:i+2]; i += 2; continue
        if s[i] == '%': break
        out += s[i]; i += 1
    return out.rstrip()

def balanced(s, start):
    """index just after the {...} group that opens at s[start]=='{'"""
    d = 0
    for i in range(start, len(s)):
        if s[i] == '{': d += 1
        elif s[i] == '}':
            d -= 1
            if d == 0: return i + 1
    return -1

class Conv:
    def __init__(self, name):
        self.name = name; self.fields = {}; self.nf = 0; self.review = []; self.values = {}
    def newfield(self, inner, kind='text', fmt=None):
        self.nf += 1; fid = 'f%d' % self.nf
        f = {"type": kind, "label": re.sub(r'\s+', ' ', inner)[:60], "placeholder": inner}
        self.fields[fid] = f
        self.values[fid] = ('2026-01-10' if kind == 'date' else 'Τιμή %d' % self.nf)
        return fid
    def fill(self, fid, fmt='d/m/Y'):
        v = self.values[fid]
        if self.fields[fid]['type'] == 'date':
            y, m, d = v.split('-'); return fmt.replace('d', d).replace('m', m).replace('Y', y)
        return v
    def convert(self, raw, datefmt='d/m/Y', in_info=False):
        """returns (markup | None, filled latex line). None = needs latex fallback."""
        s = raw.strip()
        s = re.sub(r'^\{\}(?=\[)', '', s)                      # "\item {}[x]" guard
        filled = s; markup = s
        # placeholders [..] -> fields (dates by shape)
        def ph(m):
            inner = m.group(1)
            kind = 'date' if re.fullmatch(r'DD[/-]MM[/-]YYYY', inner) else 'text'
            fid = self.newfield(inner, kind); self.fields_orig[fid] = inner
            return '\0' + fid + '\0'
        marked = re.sub(r'\[([^\[\]]+)\]', ph, markup)
        def bare(m):
            fid = self.newfield(m.group(0), 'date'); self.fields_orig[fid] = m.group(0); self.fmts = getattr(self, 'fmts', {}); self.fmts[fid] = 'd-m-Y' if '-' in m.group(0) else 'd/m/Y'
            return '\0' + fid + '\0'
        marked = re.sub(r'(?<![\w\0])DD[/-]MM[/-]YYYY(?![\w])', bare, marked)
        # bare DD-MM-YYYY in \Info rows
        if in_info and re.fullmatch(r'DD-MM-YYYY', marked):
            fid = self.newfield('DD-MM-YYYY', 'date'); marked = '\0' + fid + '\0'
        # latex -> markup
        m = marked
        for a, b in (('\\%', '%'), ('\\&', '&'), ('\\$', '$'), ('\\_', '_'), ('\\#', '#'), ('\\PatientName', '@patient'), ('\\PatientAMKA', '@amka')):
            m = m.replace(a, b)
        def bold(mm): return '**' + mm.group(1) + '**'
        for _ in range(3):
            m = re.sub(r'\\textbf\{([^{}]*)\}', bold, m)
            m = re.sub(r'\\(?:textit|emph)\{([^{}]*)\}', lambda mm: '*' + mm.group(1) + '*', m)
        # gender
        m = re.sub(r'(?<![\w\u0370-\u03ff])Ο ασθενής', '{Ο|Η} ασθενής', m)
        m = re.sub(r'(?<![\w\u0370-\u03ff])ο ασθενής', '{ο|η} ασθενής', m)
        m = re.sub(r'του ασθενούς', '{του|της} ασθενούς', m)
        ok = '\\\\' not in marked   # a forced line break (\\\\) stays LaTeX
        rest = re.sub(r'\\ ', '', m)
        rest = re.sub(r'\{[Οοτ][^{}|]*\|[^{}]*\}', '', rest)
        if re.search(r'\\[a-zA-Z]+|[{}$~^_#&]', rest.replace('\0', '')): ok = False
        # literal * or @ must be escaped for markup
        if ok:
            tmp = m.replace('**', '\x01').replace('\\ ', '\x02')
            tmp = re.sub(r'(?<!\\)\*', r'\\*', tmp)
            tmp = re.sub(r'(?<![\w@])@(?![a-z])', r'\\@', tmp)
            m = tmp.replace('\x01', '**').replace('\x02', '\\ ')
            # a field in the markup stands for @fN
            for fid in re.findall('\0(f\\d+)\0', m): pass
        # filled latex line (for the fixture): fields replaced by their values, everything else untouched
        fl = re.sub('\0(f\\d+)\0', lambda mm: self.fill(mm.group(1), getattr(self, 'fmts', {}).get(mm.group(1), datefmt)), marked)
        if ok:
            m = re.sub('\0(f\\d+)\0', lambda mm: '@' + mm.group(1), m)
            return m, fl
        # latex fallback: keep the raw text; fields inside cannot be edited -> use the filled text too
        restored = re.sub('\0(f\\d+)\0', lambda mm: ('' if self.fields_orig.get(mm.group(1), '').startswith('DD') else '[') + self.fields_orig.get(mm.group(1), '') + ('' if self.fields_orig.get(mm.group(1), '').startswith('DD') else ']'), marked)
        for fid in re.findall('\0(f\\d+)\0', marked): del self.fields[fid]
        return None, restored

def main(name):
    src = open(KIT + 'tpl-' + name + '.tex', encoding='utf8').read()
    head, body = src.split('\\begin{document}', 1)
    body = body.split('\\end{document}')[0]
    conv = Conv(name); conv.fields_orig = {}
    t = {"format": 1, "name": name, "label": name, "lang": 'en' if 'british' in head else 'gr', "paper": 'a5' if re.search(r'^\\providecommand\\DocPaper\{a5\}', head, re.M) else 'a4',
         "imported": "kit tpl-%s.tex" % name, "shared": {"purpose": "latest"}, "fields": {}, "sections": [], "needs_review": []}
    def prov(var):
        act = re.search(r'^\\providecommand\\' + var + r'\{(.*)\}\s*(?:%.*)?$', head, re.M)
        alts = re.findall(r'^%\s*\\providecommand\\' + var + r'\{(.*)\}\s*$', head, re.M)
        return (act.group(1) if act else None), alts
    for var, key in (('DocTitle', 'title'), ('DocClosing', 'closing')):
        a, alts = prov(var)
        t[key] = a if a is not None else ''
        t[key + 's'] = [x for x in [t[key]] + alts if x != ''] or []
    a, _ = prov('DocStamp'); t['stamp'] = a or 'no'
    a, _ = prov('DocPageNumbers')
    if a: t['page_numbers'] = a
    if '\\Recipient' in head: t['recipient'] = True
    extra = [v for v in re.findall(r'^\\providecommand\\(\w+)\{', head, re.M) if v not in ('DocLang','Clinic','DocCode','PatientPrefix','PatientName','PatientAge','PatientAMKA','DocDate','DocTitle','DocClosing','DocStamp','DocPageNumbers','DocPaper','Recipient')]
    if extra: t['needs_review'].append('extra settings not imported: ' + ', '.join(extra))

    # fixture: kit file with the header filled in
    fx = src
    fx = fx.replace('\\providecommand\\PatientName{Επώνυμο Όνομα}', '\\providecommand\\PatientName{Παπαδόπουλος Γιώργος}')
    fx = fx.replace('\\providecommand\\PatientAge{00}', '\\providecommand\\PatientAge{45}')
    fx = fx.replace('\\providecommand\\DocDate{\\today}', '\\providecommand\\DocDate{\\DTMdate{2026-01-15}}')
    fx = re.sub(r'^\\providecommand\\PatientAMKA\{.*\}$', lambda m: '\\providecommand\\PatientAMKA{12345678901}', fx, flags=re.M)
    if t.get('recipient'):
        fx = re.sub(r'^\\providecommand\\Recipient\{\[.*\]\}$', lambda m: '\\providecommand\\Recipient{Προς: Δοκιμαστικό Εργαστήριο}', fx, flags=re.M)

    sections = []; cur = None; sid = 0
    lines = body.split('\n'); out_fx = []
    def new_section(layout='paragraphs', heading=None, level='section', optional=False, numbered=False):
        nonlocal sid, cur
        sid += 1
        cur = {"id": "s%d" % sid, "slot": "import.%s.s%d" % (name, sid), "layout": layout, "blocks": []}
        if heading is not None: cur['heading'] = heading
        if level == 'subsection': cur['heading_level'] = 'subsection'
        if numbered: cur['numbered'] = True
        if optional: cur.update({"optional": True, "default": False})
        sections.append(cur); return cur
    def add_block(text, default, inline=False, label=None, raw=None, datefmt=None):
        bid = '%s_b%d' % (cur['id'], len(cur['blocks']) + 1)
        b = {"id": bid, "text": text if raw is None else raw, "default": default}
        if 'blocks' not in cur: cur['blocks'] = []
        if raw is not None: b['format'] = 'latex'; t['needs_review'].append('%s: kept as LaTeX: %s' % (bid, raw[:70]))
        if inline: b['inline'] = True
        if label is not None: b['label'] = label
        if datefmt: b['datefmt'] = datefmt
        cur['blocks'].append(b); return b

    in_env = None; para_first = True; i = 0; topic = None; pending_title = None; seen_newpage = False; in_center = False
    pinfo_done = False; env_stack = []
    new_section('paragraphs')
    group_has = False; need_new = False
    purpose_hits = []
    for ln in lines:
        raw = ln; s = ln.strip()
        active = not s.startswith('%')
        txt = s if active else s.lstrip('%').strip()
        if s == '':
            group_has = False; need_new = True; out_fx.append(ln); continue
        # environments
        m = re.match(r'\\begin\{(itemize|enumerate|description|UrgentBox|PatientInfo|MedTable|center)\}', txt)
        if m and active:
            env = m.group(1)
            if env == 'PatientInfo':
                if cur['blocks']: new_section('paragraphs')
                cur['layout'] = 'patient-info'; cur['slot'] = 'import.%s.patient' % name; in_env = 'PatientInfo'; out_fx.append(ln); continue
            if env in ('itemize', 'enumerate', 'description', 'UrgentBox') and env_stack and cur.get('layout') == 'topics':
                env_stack.append(env); out_fx.append(ln); continue      # a topic's own bullet list
            if env == 'center': in_center = True; out_fx.append(ln); continue
            if env == 'MedTable':
                if cur['blocks']: new_section('paragraphs')
                cur['layout'] = 'medtable'; cur['slot'] = 'import.%s.medications' % name; cur['library'] = 'drug-library'; cur['default_rows'] = []
                if pending_title: cur['title_page'] = pending_title; pending_title = None
                in_env = 'MedTable'; t['shared']['drug-library'] = 'latest'; out_fx.append(ln); continue
            if env in ('itemize', 'enumerate', 'description', 'UrgentBox'):
                env_stack.append(env)
                if len(env_stack) == 1:
                    if cur['layout'] == 'paragraphs' and not cur['blocks']: cur['layout'] = env if env != 'UrgentBox' else 'urgentbox'
                    else:
                        h = cur.get('heading')
                        new_section(env if env != 'UrgentBox' else 'urgentbox')
                    in_env = env
                    if re.match(r'\\begin\{(enumerate|itemize)\}\[', txt): cur['list_options'] = re.search(r'\[.*\]', txt).group(0)
                    out_fx.append(ln); continue
            t['needs_review'].append('environment %s kept as latex' % env)
        if re.match(r'\\end\{center\}', txt) and active: in_center = False; out_fx.append(ln); continue
        if re.match(r'\\end\{MedTable\}', txt) and active:
            in_env = None; new_section('paragraphs'); out_fx.append(ln); continue
        if in_center and active:
            tm = re.search(r'\\textbf\{(.*)\}', txt)
            if tm: pending_title = tm.group(1)
            out_fx.append(ln); continue
        if active and txt == '\\newpage':
            seen_newpage = True; out_fx.append(ln); continue
        if active and txt.startswith('\\vspace{0.5cm}'): out_fx.append(ln); continue
        if in_env == 'MedTable':
            dm = re.match(r'\\DrugEntry(\{.*)$', txt)
            if dm and active:
                cells = []; rest = dm.group(1)
                while rest.startswith('{'):
                    e = balanced(rest, 0); cells.append(rest[1:e-1]); rest = rest[e:]
                def mk(c):
                    c = c.replace('\\newline ', '{br}').replace('\\newline', '{br}')
                    c = re.sub(r'\\textbf\{(.*?)\}', r'**\1**', c); return c.strip()
                cur['default_rows'].append(dict(zip(['name', 'dose', 'freq', 'days', 'notes'], [mk(c) for c in cells])))
            out_fx.append(ln); continue
        if re.match(r'\\end\{(itemize|enumerate|description|UrgentBox)\}', txt) and active and env_stack and len(env_stack) > 1 and cur.get('layout') == 'topics':
            env_stack.pop(); out_fx.append(ln); continue
        if re.match(r'\\end\{(itemize|enumerate|description|UrgentBox)\}', txt) and active and env_stack:
            env_stack.pop()
            if not env_stack: in_env = None; new_section('paragraphs')
            out_fx.append(ln); continue
        if re.match(r'\\end\{PatientInfo\}', txt) and active:
            in_env = None; new_section('paragraphs'); out_fx.append(ln); continue
        # headings
        hm = re.match(r'\\(section|subsection)(\*?)\{(.*)\}$', txt)
        if hm and active:
            if cur['blocks'] or cur['layout'] != 'paragraphs' or 'heading' in cur: new_section('paragraphs')
            cur['heading'] = hm.group(3)
            if hm.group(1) == 'subsection': cur['heading_level'] = 'subsection'
            if not hm.group(2): cur['numbered'] = True
            out_fx.append(ln); continue
        # patient info rows
        im = re.match(r'\\Info\{(.*?)\}\{(.*)\}$', txt)
        if im and in_env == 'PatientInfo':
            label, val = im.group(1), im.group(2)
            if '\\DrName' in val: mk, fl = None, val
            else: mk, fl = conv.convert(val, 'd/m/Y' if 'DD/MM/YYYY' in val else 'd-m-Y', in_info=True)
            if mk is None: add_block(None, active, label=label, raw=val)
            else: add_block(mk, active, label=label, datefmt='d/m/Y' if 'DD/MM/YYYY' in val else 'd-m-Y')
            out_fx.append(('' if active else '% ') + '  \\Info{%s}{%s}' % (label, fl)); continue
        if in_env == 'PatientInfo': out_fx.append(ln); continue
        # skip pure English comments and "F alternative" stubs
        if not active and (not GREEK.search(txt) or txt.endswith('...')) and not txt.startswith('\\'): out_fx.append(ln); continue
        if not active and re.match(r'(-{2,}|\\begin|\\end|\\setlength|[0-9]+\.)', txt): out_fx.append(ln); continue
        # purpose sentences
        key = strip_comment(txt) if True else txt
        if key in PURPOSE:
            purpose_hits.append((PURPOSE[key], active)); out_fx.append(ln); continue
        if active and txt == '\\setlength{\\parskip}{6pt}' and in_env in ('enumerate', 'itemize'):
            cur['list_preamble'] = txt; out_fx.append(ln); continue
        # topic headers and their content (postop letters)
        if in_env == 'enumerate' and re.match(r'\\item\s*\\textbf\{[^{}]*\}\s*$', txt):
            title = re.match(r'\\item\s*\\textbf\{([^{}]*)\}', txt).group(1)
            if cur['layout'] != 'topics':
                cur['layout'] = 'topics'; cur['topics'] = []; cur.pop('blocks', None)
                cur.pop('preamble_seen', None)
            topic = {"id": '%s_t%d' % (cur['id'], len(cur['topics']) + 1), "title": title, "style": "itemize", "blocks": []}
            topic['slot'] = 'import.%s.%s' % (name, re.sub(r'[^a-z0-9]+', '_', topic['id']))
            cur['topics'].append(topic)
            if not active: topic['optional_header'] = True
            out_fx.append(ln); continue
        if cur.get('layout') == 'topics' and topic is not None and in_env == 'enumerate':
            im3 = re.match(r'\\item\s*(.*)$', txt) or re.match(r'[^\\]*?:\s*\\item\s*(.*)$', txt)   # "Διαρροή ΕΝΥ: \item ..." (a commented note + item)
            body_t = strip_comment(im3.group(1) if im3 else txt)
            if body_t:
                mk, fl = conv.convert(body_t)
                bid = '%s_b%d' % (topic['id'], len(topic['blocks']) + 1)
                b = {"id": bid, "text": mk if mk is not None else body_t, "default": active}
                if mk is None: b['format'] = 'latex'; t['needs_review'].append('%s: kept as LaTeX: %s' % (bid, body_t[:60]))
                if not im3 and active and not topic['blocks']: topic['style'] = 'paragraph'
                topic['blocks'].append(b)
                out_fx.append(('' if active else '% ') + (re.sub(r'(\\item\s*).*$', lambda mm: mm.group(1) + fl, ln.lstrip('% ').rstrip(), count=1) if im3 else fl)); continue
        # list items
        if in_env in ('itemize', 'enumerate', 'description', 'UrgentBox'):
            im2 = re.match(r'\\item\s*(.*)$', txt)
            if im2:
                rest = im2.group(1); lab = None
                if rest.startswith('['):
                    dep = 0
                    for k, ch in enumerate(rest):
                        if ch == '{': dep += 1
                        elif ch == '}': dep -= 1
                        elif ch == ']' and dep == 0: lab = rest[1:k]; rest = rest[k+1:].lstrip(); break
                body_t = strip_comment(rest); mk, fl = conv.convert(body_t)
                if mk is None: add_block(None, active, label=lab, raw=body_t)
                else: add_block(mk, active, label=lab)
                if lab is not None and in_env in ('itemize', 'enumerate'):
                    cur['blocks'][-1]['bullet'] = cur['blocks'][-1].pop('label')
                out_fx.append(('' if active else '% ') + ln.lstrip('% ').rstrip()[:len(ln.lstrip('% ').rstrip()) - len(rest.strip())] + fl if True else ln); continue
            out_fx.append(ln); continue
        # ordinary paragraph line
        body_t = strip_comment(txt)
        if body_t == '': out_fx.append(ln); continue
        if need_new and cur['layout'] == 'paragraphs' and cur['blocks']: new_section('paragraphs')
        need_new = False
        mk, fl = conv.convert(body_t)
        inline = group_has
        if mk is None: add_block(None, active, inline=inline, raw=body_t)
        else: add_block(mk, active, inline=inline)
        group_has = True
        trailing = ln[len(ln.rstrip()):]
        out_fx.append((ln[:len(ln) - len(ln.lstrip())]) + ('' if active else '% ') + fl)

    # drop empty sections; add the purpose section
    sections = [s for s in sections if s.get('blocks') or s.get('topics') or s['layout'] in ('patient-info', 'medtable')]
    if purpose_hits:
        default = next((b for b, a in purpose_hits if a), None)
        sections.append({"id": "purpose", "slot": "certificate.purpose", "layout": "paragraphs", "use": "purpose", **({"default_block": default} if default else {})})
    t['sections'] = sections
    t['fields'] = {k: v for k, v in conv.fields.items()}
    # make ids stable: field ids as imported (f1..); keep only used
    used = set(re.findall(r'@(f\d+)', json.dumps(t['sections'])))
    t['fields'] = {k: v for k, v in t['fields'].items() if k in used}
    for k, v in list(t['fields'].items()):
        t['fields'][k] = v
    DRAFT_ONLY = {'odigies-auxeniki': 'nested bullet lists inside a topic are flattened', 'odigies-tridymo': 'nested bullet lists inside a topic are flattened'}
    if name in DRAFT_ONLY:
        t['draft'] = True; t['needs_review'].insert(0, 'Import does not match the kit template yet (%s): fix by hand before release.' % DRAFT_ONLY[name])
    if not t['needs_review']: del t['needs_review']
    json.dump(t, open('types/%s.json' % name, 'w', encoding='utf8'), ensure_ascii=False, indent=2); open('types/%s.json' % name, 'a').write('\n')
    # fixture: header-filled source with the body replaced by the filled lines
    fx_head, _ = fx.split('\\begin{document}', 1)
    open('tests/fixtures/auto/%s.tex' % name, 'w', encoding='utf8').write(fx_head + '\\begin{document}' + '\n'.join(out_fx) + '\\end{document}\n')
    if name in DRAFT_ONLY: print(name, 'kept as a draft (no regression case)'); return
    cases_path = 'tests/fixtures/auto/cases.json'
    cases = json.load(open(cases_path)) if os.path.exists(cases_path) else {}
    cases[name] = {"fields": {k: conv.values[k] for k in t['fields']}, "recipient": 'Προς: Δοκιμαστικό Εργαστήριο' if t.get('recipient') else None}
    json.dump(cases, open(cases_path, 'w', encoding='utf8'), ensure_ascii=False, indent=1)
    print(name, 'sections', len(sections), 'blocks', sum(len(s.get('blocks', [])) for s in sections), 'fields', len(t['fields']), 'review', len(t.get('needs_review', [])))

for n in sys.argv[1:]: main(n)
