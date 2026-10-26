#!/usr/bin/env bash
# Install test (release plan R4): installs a ClientFence release archive with its own installer
# on a clean MariaDB and runs tools/install-test/smoke.py against it (staff sign-in, a product, a
# client who orders and pays with the manual gateway, a ticket).
#
#   tools/install-test.sh [--archive ZIP] [--from latest|VERSION|ZIP]
#
# --archive  the release to test (default: built from this checkout with tools/build-release.sh).
# --from     the upgrade test: install that older release first (a version from the release feed,
#            "latest" for the feed's current one, or a local archive), run the checks, then unpack
#            the new archive over it as System → Update does, apply the database updates
#            (system:run-patcher) and run the checks again.
#
# Needs Docker, curl, unzip and python3. Everything runs in throwaway containers on a network of
# its own; the site listens on this machine's loopback only. Nothing is kept afterwards.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
FEED=${CLIENTFENCE_FEED_URL:-https://clientfence.com/releases/}
ARCHIVE=""
FROM=""
while [[ $# -gt 0 ]]; do
  case $1 in
    --archive) ARCHIVE=$2; shift 2 ;;
    --from) FROM=$2; shift 2 ;;
    *) sed -n '6,11p' "$0" | sed 's/^# //'; exit 2 ;;
  esac
done

work=$(mktemp -d)
name="cf-install-test-$$"
cleanup() {
  docker rm -f -v "$name-app" "$name-db" >/dev/null 2>&1 || true
  docker network rm "$name" >/dev/null 2>&1 || true
  # The site files belong to the web server's user: hand them back first, so a runner that is not
  # root (GitHub Actions) can remove them.
  if [[ -d $work/site ]]; then
    docker run --rm -v "$work/site:/site" clientfence-php-base chown -R "$(id -u):$(id -g)" /site >/dev/null 2>&1 || true
  fi
  rm -rf -- "$work"
}
trap cleanup EXIT

if [[ -z $ARCHIVE ]]; then
  "$ROOT/tools/build-release.sh" --out "$work/build" >/dev/null
  ARCHIVE=$(ls "$work"/build/clientfence-*.zip)
fi
[[ -f $ARCHIVE ]] || { echo "no archive: $ARCHIVE" >&2; exit 2; }

fetch() { # url file seconds: download; on an error, say who answered (the server, or a Cloudflare block)
  local status
  status=$(curl -sS --max-time "$3" -D "$work/headers" -o "$2" -w '%{http_code}' "$1") || status=${status:-000}
  [[ $status == 200 ]] && return 0
  echo "$1 answered HTTP $status" >&2
  grep -i -E '^(server|cf-ray|cf-mitigated|cf-cache-status|content-type):' "$work/headers" >&2 || true
  sed 's/<[^>]*>//g' "$2" 2>/dev/null | tr -s ' \n' ' ' | head -c 300 >&2 || true
  echo >&2
  exit 1
}

# The release to start from: the new one, or the older one of the upgrade test.
first=$ARCHIVE
if [[ -n $FROM ]]; then
  if [[ -f $FROM ]]; then
    first=$FROM
  else
    fetch "${FEED}latest.json" "$work/latest.json" 30
    feed=$(cat "$work/latest.json")
    read -r version url sha < <(python3 -I -c 'import json,sys; f=json.loads(sys.stdin.read()); print(f["version"], f["download_url"], f["sha256"])' <<<"$feed")
    if [[ $FROM != latest && $FROM != "$version" ]]; then
      url="${FEED}clientfence-$FROM.zip"
      sha=""
    fi
    fetch "$url" "$work/from.zip" 300
    [[ -z $sha || $(sha256sum "$work/from.zip" | cut -d' ' -f1) == "$sha" ]] || { echo "the SHA-256 of $url does not match the feed" >&2; exit 1; }
    first="$work/from.zip"
  fi
fi

echo "== image and database"
docker build -q --target php-base -t clientfence-php-base "$ROOT" >/dev/null
docker network create "$name" >/dev/null
db_password=$(python3 -I -c 'import secrets; print(secrets.token_hex(16))')
docker run -d --name "$name-db" --network "$name" --network-alias db \
  -e MARIADB_DATABASE=clientfence -e MARIADB_USER=clientfence -e MARIADB_PASSWORD="$db_password" \
  -e MARIADB_RANDOM_ROOT_PASSWORD=1 mariadb:11.4 >/dev/null

unpack() { # archive: unzip over the site folder, as System → Update unpacks a release
  # Unzipped next to the site, then copied over it inside a container: the site's files belong to
  # the web server's user, which a runner that is not root cannot overwrite.
  rm -rf -- "$work/unpack"
  unzip -q -o "$1" -d "$work/unpack"
  docker run --rm -v "$work/unpack:/new:ro" -v "$work/site:/site" clientfence-php-base \
    sh -c 'cp -a /new/. /site/ && chown -R www-data:www-data /site'
  rm -rf -- "$work/unpack"
}
mkdir -p "$work/site"
unpack "$first"
docker run -d --name "$name-app" --network "$name" -p 127.0.0.1::80 -v "$work/site:/var/www/html" clientfence-php-base >/dev/null
port=$(docker port "$name-app" 80/tcp | head -1 | sed 's/.*://')
site="http://127.0.0.1:$port/"

for _ in $(seq 60); do
  docker exec "$name-db" sh -c 'mariadb-admin ping -h 127.0.0.1 --silent' >/dev/null 2>&1 && break
  sleep 2
done
for _ in $(seq 30); do
  curl -fsS -o /dev/null "${site}install/" && break
  sleep 2
done

echo "== install $(basename "$first")"
admin_email=install-test-admin@clientfence.com
admin_password="Ad-$(python3 -I -c 'import secrets; print(secrets.token_urlsafe(12))')-1a"
api_token=$(python3 -I -c 'import secrets; print(secrets.token_hex(16))')
# The form goes to curl on stdin, so no password is on a command line.
{
  printf 'form = "database_hostname=db"\nform = "database_port=3306"\n'
  printf 'form = "database_name=clientfence"\nform = "database_username=clientfence"\n'
  printf 'form = "database_password=%s"\n' "$db_password"
  printf 'form = "admin_name=Install Test"\nform = "admin_email=%s"\n' "$admin_email"
  printf 'form = "admin_password=%s"\n' "$admin_password"
  printf 'form = "currency_code=USD"\nform = "system_url=%s"\n' "$site"
} | curl -fsS -o "$work/install.html" --config - "${site}install/install.php?a=install"
grep -q 'Installation Completed' "$work/install.html" || { echo "the installer did not finish:" >&2; sed 's/<[^>]*>//g' "$work/install.html" | grep -v '^\s*$' | head -20 >&2; exit 1; }
# The installer keeps no API token outside testing mode; the checks use one.
printf "UPDATE admin SET api_token = '%s' WHERE email = '%s';\n" "$api_token" "$admin_email" \
  | docker exec -i -e MYSQL_PWD="$db_password" "$name-db" mariadb -uclientfence clientfence

echo "== checks"
python3 -I "$ROOT/tools/install-test/smoke.py" "$site" "$admin_email" "$admin_password" "$api_token" a

if [[ -n $FROM ]]; then
  echo "== update to $(basename "$ARCHIVE")"
  unpack "$ARCHIVE"
  docker exec -u www-data "$name-app" php console.php system:run-patcher
  docker exec -u www-data "$name-app" php -r '
    require "load.php"; $di = include "di.php";
    $pdo = $di["pdo"];
    $pending = ClientFence\Mod\Clientfence\Migrations::pending($pdo);
    echo "ClientFence ", ClientFence\Version::CLIENTFENCE, ", database updates pending: ", $pending, "\n";
    exit($pending === 0 ? 0 : 1);'
  echo "== checks after the update"
  python3 -I "$ROOT/tools/install-test/smoke.py" "$site" "$admin_email" "$admin_password" "$api_token" b
fi

echo "INSTALL TEST PASSED"
