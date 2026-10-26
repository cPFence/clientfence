#!/usr/bin/env python3
"""Renames FOSSBilling's code names to ClientFence's (AGENTS.md, "Names").

    python3 tools/rename-fossbilling.py tree         rename the working tree (git mv, then the text)
    python3 tools/rename-fossbilling.py patch FILE   rename the names in an upstream FOSSBilling
                                                     patch (stdout), before porting it by hand

The same rules serve both: an upstream fix is written with FOSSBilling's names (FOSSBilling\\,
Box_App, Box\\Mod\\, the FOSSBilling JS global, the fossbilling_* cookies), and ClientFence has its
own. Kept as they are: copyright and licence lines (Apache-2.0), web addresses, prose about the
upstream project, and the identifiers listed in KEEP (the encryption salt, FOSSBilling's own
service APIs and extension manifests).
"""

import os
import re
import subprocess
import sys

# library/Box/*.php, plus the docblock-only names Box_Mod, Box_Table and Box_Theme.
BOX = {
    'App': 'App', 'AppAdmin': 'AppAdmin', 'AppClient': 'AppClient', 'Authorization': 'Authorization',
    'BeanHelper': 'BeanHelper', 'Crypt': 'Crypt', 'Database': 'Database',
    'DbLoggedPDOStatement': 'DbLoggedPDOStatement', 'EventDispatcher': 'EventDispatcher',
    'EventManager': 'EventManager', 'Event': 'Event', 'LogDb': 'LogDb', 'Log': 'Log',
    'Period': 'Period', 'Translate': 'Translate', 'Url': 'Url',
    'Mod': 'Module', 'Table': 'Table', 'Theme': 'Theme',
}

# Lower-case names: cookies, form actions, locks, request attributes, temporary files, assets.
LOWER = [
    ('fossbilling_session', 'clientfence_session'),
    ('fossbilling_csrf', 'clientfence_csrf'),
    ('fossbilling_locale', 'clientfence_locale'),
    ('fossbilling_timezone', 'clientfence_timezone'),
    ('fossbilling_submit', 'clientfence_submit'),
    ('fossbilling_hook_batch_connect', 'clientfence_hook_batch_connect'),
    ('_fossbilling_route_path', '_clientfence_route_path'),
    ('.fossbilling_update_readiness_check', '.clientfence_update_readiness_check'),
    ('__fossbilling_cache_connection_test__', '__clientfence_cache_connection_test__'),
    ("'fossbilling_' . $policyName", "'clientfence_' . $policyName"),
    ('fossbilling-rate-limiter', 'clientfence-rate-limiter'),
    ('fossbilling-orderbutton-iframe', 'clientfence-orderbutton-iframe'),
    ('fossbilling_test_data', 'clientfence_test_data'),
    ('fossbilling_test_cache_', 'clientfence_test_cache_'),
    ('fossbilling_e2e_cookies', 'clientfence_e2e_cookies'),
    ('fossbilling-update-', 'clientfence-update-'),
    ('fossbilling-pdf-font-test-', 'clientfence-pdf-font-test-'),
    ('fossbilling-response-factory-', 'clientfence-response-factory-'),
    ('fossbilling-app', 'clientfence-app'),
    ('fossbilling-cypress-', 'clientfence-cypress-'),
    ('fossbilling-live-', 'clientfence-live-'),
    ('fossbilling.js', 'clientfence.js'),
    ('fossbilling.css', 'clientfence.css'),
    ('fossbilling.ts', 'clientfence.ts'),
    ('fossbilling.scss', 'clientfence.scss'),
    ("SENTRY_RELEASE_PACKAGE = 'fossbilling'", "SENTRY_RELEASE_PACKAGE = 'clientfence'"),
    ("getenv('DB_NAME') ?: 'fossbilling'", "getenv('DB_NAME') ?: 'clientfence'"),
    ('db_name="fossbilling"', 'db_name="clientfence"'),
    ('${FOSSBILLING_DB_NAME:-fossbilling}', '${CLIENTFENCE_DB_NAME:-clientfence}'),
    ('FOSSBILLING_VERSION', 'CLIENTFENCE_BASE_VERSION'),
    ('FOSSBILLING_', 'CLIENTFENCE_'),
    ('# BEGIN FOSSBilling', '# BEGIN ClientFence'),
    ('# END FOSSBilling', '# END ClientFence'),
]

# Never renamed. The salt derives the encryption key: renaming it would make every encrypted
# setting unreadable. The min/max/minimum/maximum_fossbilling_version keys and the
# fossbilling_version query parameter belong to FOSSBilling's services and manifest format.
KEEP = ['fossbilling_salt', 'min_fossbilling_version', 'max_fossbilling_version',
        'minimum_fossbilling_version', 'maximum_fossbilling_version', "'fossbilling_version'",
        'FOSSBILLING-PK4']

# Whole lines left alone: copyright and licence lines.
PROTECTED_LINE = re.compile(r'Copyright\b.*FOSSBilling|@copyright\s+FOSSBilling|Copyright FOSSBilling|'
                            r'License: https://github\.com/FOSSBilling', re.I)
# Spans left alone inside a line: web addresses, domains and email addresses.
PROTECTED_SPAN = re.compile(r'https?://[^\s\'"<>)\]`]+|[\w.+-]*@?[\w.-]*fossbilling\.(?:org|net|vm)\b', re.I)

BOX_RE = re.compile(r'(?P<pre>use\s+|[\'"])?(?P<bs>\\*)\bBox_(?P<name>' + '|'.join(sorted(BOX, key=len, reverse=True)) + r')\b')
JS_RE = re.compile(r'(?:(?<=window\.)|(?<=globalThis\.)|(?<=typeof )|(?<=const )|(?<=var )|(?<=let ))FOSSBilling\b'
                   r'|(?<![\w$\\])FOSSBilling(?=\?\.|\.[A-Za-z_$]|\[|\s*\|\||\s*&&|\s*=[^=>]|\s*===|\s*!==)')


def rename_segment(text, kind):
    """Renames the names in a piece of text without protected spans; kind: php, neon, json or other."""
    keep = {}
    for i, word in enumerate(KEEP):
        token = f'\x00K{i}\x00'
        if word in text:
            keep[token] = word
            text = text.replace(word, token)

    # Namespaces: FOSSBilling\ and Box\Mod\ (any number of backslashes: PHP, JSON, regex, neon).
    text = re.sub(r'\bFOSSBilling(?=\\)', 'ClientFence', text)
    text = re.sub(r'\bnamespace FOSSBilling\b', 'namespace ClientFence', text)
    text = re.sub(r'\bBox(\\+)(Mod|Url)\b', r'ClientFence\1\2', text)
    # Paths of the renamed folders.
    text = re.sub(r'\b(library|Unit)/FOSSBilling\b', r'\1/ClientFence', text)
    text = re.sub(r'\blibrary/Box/', 'library/ClientFence/', text)
    text = re.sub(r"PATH_LIBRARY, 'FOSSBilling'", "PATH_LIBRARY, 'ClientFence'", text)

    # Box_ classes move into the ClientFence namespace.
    def box(m):
        name = BOX[m.group('name')]
        sep = '\\\\' if kind in ('json', 'neon') else '\\'
        if kind == 'neon':
            return f'ClientFence{sep}{name}'
        pre = m.group('pre') or ''
        if pre:  # a use statement or a string: no leading backslash
            return f'{pre}ClientFence{sep}{name}'
        return f'{sep}ClientFence{sep}{name}'
    text = BOX_RE.sub(box, text)
    text = text.replace('Box_SessionFile', 'ClientFence_SessionFile')

    # Identifiers that start with the name: FOSSBillingExtension, FOSSBillingVersion,
    # FOSSBilling_Installer, makeFossBillingTwigExtension, the TypeScript types.
    text = re.sub(r'FOSSBilling(?=[A-Z_])', 'ClientFence', text)
    text = text.replace('FossBilling', 'ClientFence')
    # The JavaScript global.
    text = JS_RE.sub('ClientFence', text)

    for old, new in LOWER:
        text = text.replace(old, new)

    for token, word in keep.items():
        text = text.replace(token, word)
    return text


def rename_text(text, kind):
    out = []
    for line in text.splitlines(keepends=True):
        if PROTECTED_LINE.search(line):
            out.append(line)
            continue
        pos = 0
        parts = []
        for m in PROTECTED_SPAN.finditer(line):
            parts.append(rename_segment(line[pos:m.start()], kind))
            parts.append(m.group(0))
            pos = m.end()
        parts.append(rename_segment(line[pos:], kind))
        out.append(''.join(parts))
    return ''.join(out)


def kind_of(path):
    if path.endswith('.neon'):
        return 'neon'
    if path.endswith('.json'):
        return 'json'
    return 'php' if path.endswith('.php') else 'other'


# git mv: folders and files named after FOSSBilling or Box.
MOVES = [
    ('src/library/FOSSBilling', 'src/library/ClientFence'),
    ('tests/Unit/FOSSBilling', 'tests/Unit/ClientFence'),
]
FILE_MOVES = [
    ('src/library/ClientFence/Twig/Extension/FOSSBillingExtension.php', 'src/library/ClientFence/Twig/Extension/ClientFenceExtension.php'),
    ('src/library/ClientFence/Twig/Markdown/FOSSBillingMarkdown.php', 'src/library/ClientFence/Twig/Markdown/ClientFenceMarkdown.php'),
    ('tests/Unit/ClientFence/Twig/Extension/FOSSBillingExtensionTest.php', 'tests/Unit/ClientFence/Twig/Extension/ClientFenceExtensionTest.php'),
    ('frontend/core/fossbilling.ts', 'frontend/core/clientfence.ts'),
    ('src/themes/admin_default/assets/fossbilling.ts', 'src/themes/admin_default/assets/clientfence.ts'),
    ('src/themes/admin_default/assets/js/fossbilling.ts', 'src/themes/admin_default/assets/js/clientfence.ts'),
    ('src/themes/admin_default/assets/scss/fossbilling.scss', 'src/themes/admin_default/assets/scss/clientfence.scss'),
]
# Not rewritten: the licence texts, upstream's Arabic catalogue, compiled catalogues, this tool.
SKIP = re.compile(r'^(LICENSE|NOTICE|translations/|tools/rename-fossbilling\.py$)|\.(mo|mmdb|png|jpg|gif|ico|woff2?|ttf|eot|pdf|zip)$')


def git(*args):
    return subprocess.run(['git', *args], check=True, capture_output=True, text=True).stdout


def tree():
    for old, new in MOVES + FILE_MOVES:
        if os.path.exists(old):
            git('mv', old, new)
    if os.path.isdir('src/library/Box'):
        for name in os.listdir('src/library/Box'):
            git('mv', f'src/library/Box/{name}', f'src/library/ClientFence/{name}')
        os.rmdir('src/library/Box')
    changed = 0
    for path in git('ls-files').splitlines():
        if path.startswith('src/vendor/') or SKIP.search(path) or not os.path.isfile(path):
            continue
        with open(path, 'rb') as f:
            raw = f.read()
        if b'\x00' in raw:
            continue
        try:
            text = raw.decode('utf-8')
        except UnicodeDecodeError:
            continue
        new = rename_text(text, kind_of(path))
        if new != text:
            with open(path, 'w', encoding='utf-8', newline='') as f:
                f.write(new)
            changed += 1
    print(f'{changed} files rewritten')


def patch(file):
    with open(file, encoding='utf-8') as f:
        text = f.read()
    sys.stdout.write(rename_text(text, 'other'))


if __name__ == '__main__':
    if len(sys.argv) == 2 and sys.argv[1] == 'tree':
        tree()
    elif len(sys.argv) == 3 and sys.argv[1] == 'patch':
        patch(sys.argv[2])
    else:
        sys.exit(__doc__)
