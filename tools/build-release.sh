#!/usr/bin/env bash
# Builds a ClientFence release for the release feed (src/modules/Clientfence/Updates.php):
#   <out>/clientfence-<version>.zip   the release tree (Dockerfile target release-artifact), the
#                                     archive System → Update unpacks over an install, with
#                                     library/ClientFence/release-files.json (SHA-256 of each file)
#   <out>/latest.json                 the feed: version, release_date, minimum_php_version,
#                                     download_url, sha256, notes
#
#   tools/build-release.sh [--out DIR] [--url BASE] [--notes FILE] [--date YYYY-MM-DD]
#
# The version is Version::CLIENTFENCE of this checkout. --url is the feed folder (default
# https://clientfence.com/releases/): the archive must be published next to latest.json there.
# --notes is a Markdown file with the release notes. Publish the archive before latest.json, so the
# feed never names an archive that is not there yet.
# A beta is the same build: publish it with clientfence-feed.sh --beta (beta.json, opt in on
# System → Update) and later --promote it to stable unchanged.
# The bundled GeoIP databases must be at most 7 days old: run tools/update-geoip.sh and commit
# them first when the build says so.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
untracked=$(git -C "$ROOT" ls-files --others --exclude-standard -- src)
[[ -z $untracked ]] || { echo "untracked files under src/ would go into the release: ${untracked//$'\n'/ }" >&2; exit 1; }
OUT="$ROOT/build/release"
BASE="https://clientfence.com/releases/"
NOTES=""
DATE=$(date -u +%F)
while [[ $# -gt 0 ]]; do
  case $1 in
    --out) OUT=$2; shift 2 ;;
    --url) BASE=$2; shift 2 ;;
    --notes) NOTES=$2; shift 2 ;;
    --date) DATE=$2; shift 2 ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
done
[[ $BASE == */ ]] || BASE="$BASE/"
[[ -z $NOTES || -f $NOTES ]] || { echo "no notes file: $NOTES" >&2; exit 2; }

work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
mkdir -p "$OUT"

"$ROOT/tools/update-geoip.sh" --check 7 || { echo "the bundled GeoIP databases are not current: run tools/update-geoip.sh" >&2; exit 1; }

docker buildx build --quiet --target release-artifact --output "type=local,dest=$work/tree" "$ROOT" >/dev/null

python3 -I - "$work/tree" "$OUT" "$BASE" "$DATE" "$NOTES" "$ROOT/composer.json" <<'PY'
import hashlib, json, os, re, sys, zipfile

tree, out, base, date, notes_file, composer = sys.argv[1:7]

source = open(os.path.join(tree, 'library/ClientFence/Version.php'), encoding='utf-8').read()
match = re.search(r"public const string CLIENTFENCE = '([^']+)';", source)
if not match:
    sys.exit('Version::CLIENTFENCE not found')
version = match.group(1)
# Version::semverRegex, as Updates::parse() checks it.
semver = r'^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$'
if not re.match(semver, version):
    sys.exit(f'not a semantic version: {version}')
if not re.match(r'^\d{4}-\d{2}-\d{2}$', date):
    sys.exit(f'not a date: {date}')

php = json.load(open(composer))['require']['php']
php_min = re.search(r'\d+\.\d+(?:\.\d+)?', php).group(0)

# Nothing private goes public: no plans, notes, changelog (none before 1.0.0), agent or dev files.
private = re.compile(r'(^|/)(CHANGELOG[^/]*|AGENTS\.md|[^/]*PLAN[^/]*\.md|[^/]*NOTES[^/]*\.md|\.env(\.[^/]*)?|docs|dev|tools|\.github)(/|$)', re.IGNORECASE)
for folder, dirs, files in os.walk(tree):
    rel_folder = os.path.relpath(folder, tree)
    if rel_folder == 'vendor' or rel_folder.startswith('vendor' + os.sep):
        continue
    for entry in dirs + files:
        rel = os.path.normpath(os.path.join(rel_folder, entry)).replace(os.sep, '/')
        if private.search(rel):
            sys.exit(f'private or planning file in the release tree: {rel}')

# The SHA-256 of every file of the release, so System → Update can find the files a site changed
# before the next update overwrites them (src/modules/Clientfence/Customized.php). The GeoIP
# databases are left out: the cron replaces them every week.
listed = 'library/ClientFence/release-files.json'
refreshed = 'library/ClientFence/GeoIP/Databases/'
hashes = {}
for folder, dirs, files in os.walk(tree):
    for f in files:
        path = os.path.join(folder, f)
        rel = os.path.relpath(path, tree).replace(os.sep, '/')
        if rel != listed and not rel.startswith(refreshed) and not os.path.islink(path):
            with open(path, 'rb') as fh:
                hashes[rel] = hashlib.file_digest(fh, 'sha256').hexdigest()
with open(os.path.join(tree, listed), 'w', encoding='utf-8') as fh:
    json.dump({'version': version, 'files': dict(sorted(hashes.items()))}, fh, separators=(',', ':'))

name = f'clientfence-{version}.zip'
if not re.match(r'^[A-Za-z0-9_-][A-Za-z0-9._-]*\.zip$', name) or '..' in name:
    sys.exit(f'archive name not allowed by the feed: {name}')
archive = os.path.join(out, name)
partial = archive + '.part'
with zipfile.ZipFile(partial, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    for folder, dirs, files in os.walk(tree):
        dirs.sort()
        for f in sorted(files):
            path = os.path.join(folder, f)
            if os.path.islink(path):
                sys.exit(f'symbolic link in the release tree: {path}')
            z.write(path, os.path.relpath(path, tree))
os.replace(partial, archive)

digest = hashlib.sha256()
with open(archive, 'rb') as f:
    for chunk in iter(lambda: f.read(1 << 20), b''):
        digest.update(chunk)

feed = {
    'version': version,
    'release_date': date,
    'minimum_php_version': php_min,
    'download_url': base + name,
    'sha256': digest.hexdigest(),
    'notes': open(notes_file, encoding='utf-8').read().strip() if notes_file else '',
}
with open(os.path.join(out, 'latest.json'), 'w', encoding='utf-8') as f:
    json.dump(feed, f, indent=2, ensure_ascii=False)
    f.write('\n')

count = len(zipfile.ZipFile(archive).namelist())
print(f'{archive}  ({count} files, {os.path.getsize(archive) // 1024} KB)')
print(f'{os.path.join(out, "latest.json")}  version {version}, sha256 {feed["sha256"]}')
PY
