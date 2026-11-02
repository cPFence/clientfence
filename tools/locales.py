#!/usr/bin/env python3
"""ClientFence translations (release plan R11).

    python3 tools/locales.py extract          write the template of every text the code translates
    python3 tools/locales.py starter LANG...  add the texts LANG still lacks to its clientfence.po
    python3 tools/locales.py check LANG...    every text translated, placeholders and spacing kept
    python3 tools/locales.py verify           fail if the template is stale or any language is incomplete
    python3 tools/locales.py build LANG...    write src/locale/LANG/LC_MESSAGES/messages.mo (and the
                                              completion figures, src/locale/completion.php)
    python3 tools/locales.py status           how many texts each language still lacks
    python3 tools/locales.py todo LANG [N]    the next N (default 100) untranslated texts, as JSON
    python3 tools/locales.py fill LANG FILE   write the translations in FILE (JSON) into LANG

LANG may be "all": every locale with a ClientFence catalogue.

Files:
- src/themes/palisade/locale/clientfence.pot  the template: every text in `'…'|trans`,
  `__trans('…')`, `__pluralTrans('…', '…')` and the message of a translating exception
  (`new InformationException('…')` and the like), made by `extract`; commit it.
- translations/LANG/fossbilling.po  FOSSBilling's own catalogue as released with 0.8.8.
- src/themes/palisade/locale/LANG/clientfence.po  the ClientFence catalogue: the texts
  FOSSBilling's catalogue lacks, plus fixes to its translations. It wins where both have a text.
- src/locale/LANG/LC_MESSAGES/messages.mo  what the site reads (made by `build`; commit it).

Translations are written by people (or the project's own agents), never by a translation
service. Needs Python 3 and GNU gettext (msgcat, msgfmt).
"""
import glob
import json
import os
import re
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'src')
THEME_LOCALE = os.path.join(SRC, 'themes', 'palisade', 'locale')
TEMPLATE = os.path.join(THEME_LOCALE, 'clientfence.pot')
SKIP_DIRS = {'vendor', 'node_modules', 'locale', '.git', 'tests'}
ENGLISH = {'en_US', 'en_GB', 'en_AU'}

# Placeholders a translation must keep: %name%, :name, printf codes and HTML tags.
PLACEHOLDER = re.compile(r'%[A-Za-z_]+%|(?<![A-Za-z0-9_/%]):[a-z_]+(?![A-Za-z0-9_])|%\d*\$?[sdf]|</?(?:a|b|br|code|div|em|i|kbd|li|ol|p|pre|small|span|strong|sub|sup|u|ul)\b[^<>]*>')


# ----- PO files ------------------------------------------------------------------------------

def po_unquote(lines):
    out = []
    for line in lines:
        body = line[1:-1]
        out.append(re.sub(r'\\(.)', lambda m: {'n': '\n', 't': '\t', '"': '"', '\\': '\\'}.get(m.group(1), '\\' + m.group(1)), body))
    return ''.join(out)


def po_quote(text):
    escaped = text.replace('\\', '\\\\').replace('"', '\\"').replace('\t', '\\t')
    parts = escaped.split('\n')
    if len(parts) == 1:
        return '"' + escaped + '"'
    chunks = [p + '\\n' for p in parts[:-1]] + ([parts[-1]] if parts[-1] else [])
    return '""\n' + '\n'.join('"' + c + '"' for c in chunks)


def read_po(path):
    """Entries in file order: dicts with id, plural (or None), strs (list), comments (list)."""
    entries = []
    entry = None
    key = None
    buf = {}

    def flush():
        nonlocal entry
        if entry is not None:
            entry['id'] = po_unquote(buf.get('msgid', []))
            entry['plural'] = po_unquote(buf['msgid_plural']) if 'msgid_plural' in buf else None
            if entry['plural'] is None:
                entry['strs'] = [po_unquote(buf.get('msgstr', []))]
            else:
                n = sorted(int(k[7:-1]) for k in buf if k.startswith('msgstr['))
                entry['strs'] = [po_unquote(buf['msgstr[%d]' % i]) for i in n]
            entries.append(entry)
        entry = None
        buf.clear()

    comments = []
    with open(path, encoding='utf-8') as f:
        for raw in f:
            line = raw.rstrip('\n')
            if not line.strip():
                flush()
                key = None
                continue
            if line.startswith('#'):
                if entry is not None and key is not None:
                    flush()
                    key = None
                comments.append(line)
                continue
            m = re.match(r'^(msgid_plural|msgid|msgstr(?:\[\d+\])?)\s+(".*")$', line)
            if m:
                if m.group(1) == 'msgid' and entry is not None and 'msgid' in buf:
                    flush()
                if entry is None:
                    entry = {'comments': comments}
                    comments = []
                key = m.group(1)
                buf[key] = [m.group(2)]
            elif line.startswith('"') and key is not None:
                buf[key].append(line)
    flush()
    return entries


def write_po(path, header, entries):
    out = []
    out.extend(header.get('comments', []))
    out.append('msgid ""')
    out.append('msgstr ' + po_quote(header['strs'][0]))
    for e in entries:
        out.append('')
        out.extend(e.get('comments', []))
        out.append('msgid ' + po_quote(e['id']))
        if e['plural'] is None:
            out.append('msgstr ' + po_quote(e['strs'][0]))
        else:
            out.append('msgid_plural ' + po_quote(e['plural']))
            for i, s in enumerate(e['strs']):
                out.append('msgstr[%d] %s' % (i, po_quote(s)))
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'w', encoding='utf-8') as f:
        f.write('\n'.join(out) + '\n')


def split_header(entries):
    if entries and entries[0]['id'] == '':
        return entries[0], entries[1:]
    return None, entries


def header_field(header, name):
    m = re.search(r'^' + re.escape(name) + r':\s*(.*)$', header['strs'][0] if header else '', re.M)
    return m.group(1).strip() if m else None


def translated(entries):
    return {e['id'] for e in entries if e['id'] and all(s for s in e['strs'])}


# ----- extract -------------------------------------------------------------------------------

def unescape(body, quote, php):
    if quote == "'" or not php:
        # Twig strings and PHP single-quoted strings: only \<quote> and \\ are escapes.
        return re.sub(r'\\([\\' + quote + r'])', r'\1', body)
    return re.sub(r'\\(.)', lambda m: {'n': '\n', 't': '\t', '"': '"', '\\': '\\', '$': '$'}.get(m.group(1), '\\' + m.group(1)), body)


LITERAL = r"""(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")"""
TWIG = re.compile(LITERAL + r'\s*\|\s*trans\b', re.S)
PHP_SINGLE = re.compile(r'\b__trans\(\s*' + LITERAL, re.S)
PHP_PLURAL = re.compile(r'\b__pluralTrans\(\s*' + LITERAL + r'\s*,\s*' + LITERAL, re.S)
# Exceptions whose constructor translates the message (ClientFence\Exception and its subclasses).
PHP_THROW = re.compile(r'\bnew\s+(\\?[A-Za-z_][A-Za-z0-9_\\]*)\(\s*' + LITERAL, re.S)
TRANSLATING_EXCEPTIONS = {
    'ClientFence\\Exception', 'ClientFence\\InformationException', 'Server_Exception', 'Registrar_Exception',
    'Payment_Exception', 'ClientFence\\Mod\\Support\\Mail\\MailException',
}


def resolve_class(name, text):
    """The full class name PHP gives `name` in a file (its namespace and use statements)."""
    if name.startswith('\\'):
        return name[1:]
    first = name.split('\\')[0]
    for m in re.finditer(r'^use\s+([A-Za-z0-9_\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;', text, re.M):
        alias = m.group(2) or m.group(1).rsplit('\\', 1)[-1]
        if alias == first:
            return m.group(1) + name[len(first):]
    namespace = re.search(r'^namespace\s+([A-Za-z0-9_\\]+)\s*;', text, re.M)
    if namespace and '_' not in first:
        return namespace.group(1) + '\\' + name
    return name


def literal(m, i, php):
    single, double = m.group(i), m.group(i + 1)
    if single is not None:
        return unescape(single, "'", php)
    if php and re.search(r'(?<!\\)\$[A-Za-z_{]', double):
        return None  # interpolated: not a fixed text
    return unescape(double, '"', php)


def extract(destination=TEMPLATE):
    found = {}
    for dirpath, dirnames, filenames in os.walk(SRC):
        dirnames[:] = sorted(d for d in dirnames if d not in SKIP_DIRS)
        for name in sorted(filenames):
            if not name.endswith(('.twig', '.php')):
                continue
            path = os.path.join(dirpath, name)
            rel = os.path.relpath(path, ROOT)
            with open(path, encoding='utf-8', errors='replace') as f:
                text = f.read()
            php = name.endswith('.php')
            hits = []
            if name.endswith('.twig'):
                hits += [(literal(m, 1, False), None) for m in TWIG.finditer(text)]
            if php:
                hits += [(literal(m, 1, True), None) for m in PHP_SINGLE.finditer(text)]
                hits += [(literal(m, 1, True), literal(m, 3, True)) for m in PHP_PLURAL.finditer(text)]
                hits += [(literal(m, 2, True), None) for m in PHP_THROW.finditer(text)
                         if resolve_class(m.group(1), text) in TRANSLATING_EXCEPTIONS]
            for msgid, plural in hits:
                if not msgid or not msgid.strip():
                    continue
                key = (msgid, plural)
                found.setdefault(key, set()).add(rel)
    # Module names and descriptions (manifest.json) show translated on System > Settings, Extensions and the settings search.
    for manifest in sorted(glob.glob(os.path.join(SRC, 'modules', '*', 'manifest.json'))):
        with open(manifest, encoding='utf-8') as f:
            data = json.load(f)
        for field in ('name', 'description'):
            if isinstance(data.get(field), str) and data[field].strip():
                found.setdefault((data[field], None), set()).add(os.path.relpath(manifest, ROOT))
    entries = []
    for (msgid, plural), files in sorted(found.items(), key=lambda kv: kv[0][0]):
        refs = sorted(files)
        comments = ['#: ' + ' '.join(refs[:3]) + (' …' if len(refs) > 3 else '')]
        entries.append({'id': msgid, 'plural': plural, 'strs': ['', ''] if plural else [''], 'comments': comments})
    # One entry per msgid: a plural use wins over a singular one.
    unique = {}
    for e in entries:
        if e['id'] not in unique or e['plural']:
            unique[e['id']] = e
    header = {'comments': ['# ClientFence: every text the code translates (tools/locales.py extract).'],
              'strs': ['Project-Id-Version: ClientFence\nMIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\n']}
    write_po(destination, header, list(unique.values()))
    if destination == TEMPLATE:
        print('%s: %d texts' % (os.path.relpath(TEMPLATE, ROOT), len(unique)))


# ----- per language --------------------------------------------------------------------------

def languages(args):
    if args == ['all']:
        return sorted(d for d in os.listdir(THEME_LOCALE) if os.path.isfile(os.path.join(THEME_LOCALE, d, 'clientfence.po')))
    return args


def paths(lang):
    return (os.path.join(ROOT, 'translations', lang, 'fossbilling.po'),
            os.path.join(THEME_LOCALE, lang, 'clientfence.po'),
            os.path.join(SRC, 'locale', lang, 'LC_MESSAGES', 'messages.mo'))


def nplurals(header):
    forms = header_field(header, 'Plural-Forms') or ''
    m = re.search(r'nplurals\s*=\s*(\d+)', forms)
    return int(m.group(1)) if m else 2


def placeholders_kept(e):
    """Every translation of the entry keeps the placeholders of its source text."""
    for i, s in enumerate(e['strs']):
        source = e['id'] if (e['plural'] is None or i == 0) else e['plural']
        if placeholders(s) != placeholders(source) and not (e['plural'] and placeholders(s) == placeholders(e['plural'])):
            return False
    return True


def missing(lang):
    """Template entries neither FOSSBilling's nor the ClientFence catalogue translates. A FOSSBilling
    translation that loses a placeholder (Arabic ":hosting for :domain" -> ":host ...") counts as
    missing, so the ClientFence catalogue gets a correct one that overrides it."""
    fb_path, cf_path, _ = paths(lang)
    fb = read_po(fb_path) if os.path.exists(fb_path) else []
    have = translated([e for e in fb if not e['id'] or placeholders_kept(e)])
    cf = read_po(cf_path) if os.path.exists(cf_path) else []
    have |= translated(cf)
    return [e for e in split_header(read_po(TEMPLATE))[1] if e['id'] not in have]


def starter(lang):
    fb_path, cf_path, _ = paths(lang)
    fb_header, _ = split_header(read_po(fb_path))
    existing = os.path.exists(cf_path)
    if existing:
        header, entries = split_header(read_po(cf_path))
    else:
        header = {'comments': ['# ClientFence texts for %s, over FOSSBilling\'s catalogue (tools/locales.py).' % lang],
                  'strs': ['Project-Id-Version: ClientFence\nMIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\n'
                           'Content-Transfer-Encoding: 8bit\nLanguage: %s\nPlural-Forms: %s\n'
                           % (lang, header_field(fb_header, 'Plural-Forms') or 'nplurals=2; plural=(n != 1);')]}
        entries = []
    n = nplurals(header)
    known = {e['id'] for e in entries}
    new = [{'id': e['id'], 'plural': e['plural'], 'strs': [''] * (n if e['plural'] else 1), 'comments': e['comments']}
           for e in missing(lang) if e['id'] not in known]
    if existing:
        # Appended, so the file's own comments and layout stay as they are.
        if new:
            with tempfile.TemporaryDirectory() as work:
                part = os.path.join(work, 'new.po')
                write_po(part, {'strs': ['']}, new)
                with open(part, encoding='utf-8') as f:
                    added = f.read().split('\n', 2)[2]
            with open(cf_path, 'a', encoding='utf-8') as f:
                f.write('\n# To translate (tools/locales.py starter).\n' + added.lstrip('\n'))
    else:
        write_po(cf_path, header, new)
    print('%s: %d texts to translate added (%d in the file)' % (lang, len(new), len(entries) + len(new)))


def placeholders(text):
    return sorted(PLACEHOLDER.findall(text))


def check(lang, strict=False):
    _, cf_path, _ = paths(lang)
    header, entries = split_header(read_po(cf_path))
    problems = []
    n = nplurals(header)
    seen = set()
    for e in entries:
        where = e['id'][:60].replace('\n', ' ')
        if e['id'] in seen:
            problems.append('duplicate: %s' % where)
        seen.add(e['id'])
        if e['plural'] is not None and len(e['strs']) != n:
            problems.append('needs %d plural forms: %s' % (n, where))
        for i, s in enumerate(e['strs']):
            source = e['id'] if (e['plural'] is None or i == 0) else e['plural']
            if not s:
                problems.append('not translated: %s' % where)
                break
            if placeholders(s) != placeholders(source) and not (e['plural'] and placeholders(s) == placeholders(e['plural'])):
                problems.append('placeholders %s → %s: %s' % (placeholders(source), placeholders(s), where))
            if (source[:1].isspace(), source[-1:].isspace()) != (s[:1].isspace(), s[-1:].isspace()):
                problems.append('leading/trailing space differs: %s' % where)
            if source.count('\n') != s.count('\n'):
                problems.append('line breaks differ: %s' % where)
    result = subprocess.run(['msgfmt', '--check', '-o', os.devnull, cf_path], capture_output=True, text=True)
    if result.returncode != 0:
        problems.append('msgfmt: ' + result.stderr.strip())
    left = len(missing(lang))
    if strict and left:
        problems.append('%d template texts still missing' % left)
    for p in problems:
        print('%s: %s' % (lang, p.replace('\n', ' ')))
    if not strict:
        print('%s: %d entries, %d problems, %d template texts still missing' % (lang, len(entries), len(problems), left))
    return not problems


def verify():
    ok = True
    with tempfile.TemporaryDirectory() as work:
        extracted = os.path.join(work, 'clientfence.pot')
        extract(extracted)
        with open(extracted, encoding='utf-8') as fresh, open(TEMPLATE, encoding='utf-8') as committed:
            if fresh.read() != committed.read():
                print('the template is out of date: run extract')
                ok = False
    for lang in languages(['all']):
        if not check(lang, strict=True):
            ok = False
    return ok


def build(lang):
    fb_path, cf_path, mo_path = paths(lang)
    with tempfile.TemporaryDirectory() as work:
        merged = os.path.join(work, 'merged.po')
        sources = [cf_path] + ([fb_path] if os.path.exists(fb_path) else [])
        subprocess.run(['msgcat', '--use-first', *sources, '-o', merged], check=True)
        subprocess.run(['msgfmt', '--check-format', '-o', mo_path, merged], check=True)
    print('built %s' % os.path.relpath(mo_path, ROOT))


def todo(lang, limit):
    """The next untranslated entries of LANG's catalogue, with the translator's context."""
    _, cf_path, _ = paths(lang)
    header, entries = split_header(read_po(cf_path))
    out = []
    for e in entries:
        if all(e['strs']):
            continue
        item = {'id': e['id'], 'where': ' '.join(c[3:] for c in e['comments'] if c.startswith('#: '))}
        if e['plural'] is not None:
            item['plural'] = e['plural']
            item['forms'] = nplurals(header)
        out.append(item)
        if len(out) >= limit:
            break
    left = sum(1 for e in entries if not all(e['strs']))
    print(json.dumps({'language': lang, 'left': left, 'texts': out}, ensure_ascii=False, indent=1))


def fill(lang, source):
    """Writes translations into LANG's catalogue: FILE maps each msgid to its text (a list of
    forms for a plural). Only untranslated entries change; the file is edited in place."""
    _, cf_path, _ = paths(lang)
    with open(source, encoding='utf-8') as f:
        given = json.load(f)
    with open(cf_path, encoding='utf-8') as f:
        text = f.read()
    header, entries = split_header(read_po(cf_path))
    done = 0
    unknown = []
    for msgid, value in given.items():
        entry = next((e for e in entries if e['id'] == msgid), None)
        if entry is None or all(entry['strs']):
            unknown.append(msgid)
            continue
        if entry['plural'] is None:
            if not isinstance(value, str) or not value:
                unknown.append(msgid)
                continue
            old = 'msgid %s\nmsgstr ""\n' % po_quote(msgid)
            new = 'msgid %s\nmsgstr %s\n' % (po_quote(msgid), po_quote(value))
        else:
            n = len(entry['strs'])
            if not isinstance(value, list) or len(value) != n or not all(value):
                unknown.append(msgid)
                continue
            head = 'msgid %s\nmsgid_plural %s\n' % (po_quote(msgid), po_quote(entry['plural']))
            old = head + ''.join('msgstr[%d] ""\n' % i for i in range(n))
            new = head + ''.join('msgstr[%d] %s\n' % (i, po_quote(v)) for i, v in enumerate(value))
        if text.count(old) != 1:
            unknown.append(msgid)
            continue
        text = text.replace(old, new)
        done += 1
    with open(cf_path, 'w', encoding='utf-8') as f:
        f.write(text)
    for msgid in unknown:
        print('%s: not written (unknown, already translated or empty): %s' % (lang, msgid[:70]))
    left = sum(1 for e in split_header(read_po(cf_path))[1] if not all(e['strs']))
    print('%s: %d written, %d left' % (lang, done, left))
    return not unknown


def completion():
    """src/locale/completion.php: the share of the template each language translates (the
    percentage Extensions → Languages shows). English needs no translation."""
    total = [e['id'] for e in split_header(read_po(TEMPLATE))[1]]
    figures = {}
    for lang in sorted(os.listdir(os.path.join(SRC, 'locale'))):
        if not os.path.isdir(os.path.join(SRC, 'locale', lang)):
            continue
        if lang in ENGLISH:
            figures[lang] = 100
            continue
        missing_ids = {e['id'] for e in missing(lang)}
        figures[lang] = int(100 * (len(total) - len(missing_ids)) / len(total)) if total else 0
    lines = ['<?php', '/**', ' * Translation completion per locale: the share of the texts the code translates',
             ' * (src/themes/palisade/locale/clientfence.pot). Made by tools/locales.py build.', ' */', 'return [']
    lines += ["  '%s' => %d," % (lang, value) for lang, value in figures.items()]
    lines.append('];')
    with open(os.path.join(SRC, 'locale', 'completion.php'), 'w', encoding='utf-8') as f:
        f.write('\n'.join(lines) + '\n')
    return figures


def status():
    total = len(split_header(read_po(TEMPLATE))[1])
    for lang in sorted(os.listdir(os.path.join(SRC, 'locale'))):
        if not os.path.isdir(os.path.join(SRC, 'locale', lang)) or lang in ENGLISH:
            continue
        print('%-6s %5d of %d texts missing' % (lang, len(missing(lang)), total))


def main(argv):
    if not argv:
        print(__doc__)
        return 2
    command, args = argv[0], argv[1:]
    if command == 'extract':
        extract()
    elif command == 'verify':
        return 0 if verify() else 1
    elif command == 'status':
        status()
    elif command == 'todo' and args:
        todo(args[0], int(args[1]) if len(args) > 1 else 100)
    elif command == 'fill' and len(args) == 2:
        return 0 if fill(args[0], args[1]) else 1
    elif command in ('starter', 'check', 'build') and args:
        ok = True
        for lang in languages(args):
            result = {'starter': starter, 'check': check, 'build': build}[command](lang)
            ok = ok and result is not False
        if command == 'build':
            completion()
        return 0 if ok else 1
    else:
        print(__doc__)
        return 2
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
