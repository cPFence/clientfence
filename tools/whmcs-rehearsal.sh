#!/usr/bin/env bash
# WHMCS import rehearsal: a throwaway ClientFence install (as tools/install-test.sh makes it) and a
# WHMCS database loaded from a SQL dump next to it, then the import from the console: plan, apply,
# verify. For the demo database (tools/whmcs-rehearsal/demo.sql, invented data) and for a copy of a
# real WHMCS database before a move.
#
#   tools/whmcs-rehearsal.sh [--sql DUMP] [--key-env NAME] [--attachments DIR] [--archive ZIP] [--keep]
#
# --sql      the WHMCS database dump (default: the demo). It is loaded into a database of the
#            throwaway MariaDB and read through a user that may only SELECT.
# --key-env  the name of an environment variable holding WHMCS's encryption key
#            (cc_encryption_hash), to carry two-factor secrets over. The demo's key is used for
#            the demo. The key never goes on a command line.
# --attachments  WHMCS's ticket attachments folder (a copy; default for the demo:
#            tools/whmcs-rehearsal/attachments). The site reads it from /whmcs-attachments.
# --archive  the release to install (default: built from this checkout).
# --keep     stop before the import and keep everything running, to run it from the admin tab:
#            prints the site address and the file with the staff login (mode 600). Remove it all
#            afterwards with: tools/whmcs-rehearsal.sh --clean NAME
#
# Needs Docker, curl, unzip and python3. Everything runs in containers on a network of their own;
# the site listens on this machine's loopback only. Without --keep nothing is kept afterwards.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
SQL="$ROOT/tools/whmcs-rehearsal/demo.sql"
KEY_ENV=""
ATTACHMENTS=""
ARCHIVE=""
KEEP=0
while [[ $# -gt 0 ]]; do
  case $1 in
    --sql) SQL=$2; shift 2 ;;
    --key-env) KEY_ENV=$2; shift 2 ;;
    --attachments) ATTACHMENTS=$2; shift 2 ;;
    --archive) ARCHIVE=$2; shift 2 ;;
    --keep) KEEP=1; shift ;;
    --clean)
      docker rm -f -v "$2-app" "$2-db" >/dev/null 2>&1 || true
      docker network rm "$2" >/dev/null 2>&1 || true
      if [[ -d /tmp/$2 ]]; then
        docker run --rm -v "/tmp/$2:/w" clientfence-php-base rm -rf /w/site >/dev/null 2>&1 || true
        rm -rf -- "/tmp/$2"
      fi
      echo "removed $2"
      exit 0 ;;
    *) sed -n '7,21p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
  esac
done
[[ -f $SQL ]] || { echo "no dump: $SQL" >&2; exit 2; }
if [[ -n $KEY_ENV && -z ${!KEY_ENV:-} ]]; then
  echo "the environment variable $KEY_ENV is empty" >&2
  exit 2
fi

name="cf-whmcs-rehearsal-$$"
work="/tmp/$name"
mkdir -m 700 "$work"
cleanup() {
  if [[ $KEEP == 1 ]]; then
    return
  fi
  docker rm -f -v "$name-app" "$name-db" >/dev/null 2>&1 || true
  docker network rm "$name" >/dev/null 2>&1 || true
  if [[ -d $work/site ]]; then
    docker run --rm -v "$work:/w" clientfence-php-base rm -rf /w/site >/dev/null 2>&1 || true
  fi
  rm -rf -- "$work"
}
trap cleanup EXIT

if [[ -z $ARCHIVE ]]; then
  "$ROOT/tools/build-release.sh" --out "$work/build" >/dev/null
  ARCHIVE=$(ls "$work"/build/clientfence-*.zip)
fi
[[ -f $ARCHIVE ]] || { echo "no archive: $ARCHIVE" >&2; exit 2; }

secret() { python3 -I -c 'import secrets; print(secrets.token_hex(16))'; }

echo "== image and databases"
docker build -q --target php-base -t clientfence-php-base "$ROOT" >/dev/null
docker network create "$name" >/dev/null
db_password=$(secret)
reader_password=$(secret)
# Passwords reach the containers through a file (mode 600), never a command line.
(umask 077 && printf 'MARIADB_DATABASE=clientfence\nMARIADB_USER=clientfence\nMARIADB_PASSWORD=%s\nMARIADB_ROOT_PASSWORD=%s\n' "$db_password" "$(secret)" > "$work/db.env")
docker run -d --name "$name-db" --network "$name" --network-alias db --env-file "$work/db.env" mariadb:11.4 >/dev/null
for _ in $(seq 60); do
  docker exec "$name-db" sh -c 'mariadb-admin ping -h 127.0.0.1 --silent' >/dev/null 2>&1 && break
  sleep 2
done
sleep 3
# The WHMCS copy and a user that may only read it (the import never needs more), made as root.
asroot() { docker exec -i "$name-db" sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot "$@"' sh "$@"; }
{
  printf "CREATE DATABASE whmcs CHARACTER SET utf8mb4;\n"
  printf "CREATE USER 'whmcsread'@'%%' IDENTIFIED BY '%s';\n" "$reader_password"
  printf "GRANT SELECT ON whmcs.* TO 'whmcsread'@'%%';\n"
} | asroot
asroot whmcs < "$SQL"
echo "loaded $(basename "$SQL")"

mkdir -p "$work/site"
unzip -q -o "$ARCHIVE" -d "$work/unpack"
docker run --rm -v "$work/unpack:/new:ro" -v "$work/site:/site" clientfence-php-base sh -c 'cp -a /new/. /site/ && chown -R www-data:www-data /site'
rm -rf -- "$work/unpack"
# WHMCS's attachments, a read-only copy the site can read.
if [[ -z $ATTACHMENTS && $SQL == "$ROOT/tools/whmcs-rehearsal/demo.sql" ]]; then
  ATTACHMENTS="$ROOT/tools/whmcs-rehearsal/attachments"
fi
mkdir -p "$work/attachments"
if [[ -n $ATTACHMENTS ]]; then
  cp -a "$ATTACHMENTS/." "$work/attachments/"
fi
chmod -R a+rX "$work/attachments"
docker run -d --name "$name-app" --network "$name" -p 127.0.0.1::80 -v "$work/site:/var/www/html" -v "$work/attachments:/whmcs-attachments:ro" clientfence-php-base >/dev/null
# The ticket files folder next to the site, as on a real host (TicketFiles::dir()).
docker exec "$name-app" sh -c 'for d in .clientfence-ticket-files .clientfence-backups; do mkdir -p "/var/www/$d" && chown www-data:www-data "/var/www/$d" && chmod 700 "/var/www/$d"; done'
port=$(docker port "$name-app" 80/tcp | head -1 | sed 's/.*://')
site="http://127.0.0.1:$port/"
for _ in $(seq 30); do
  curl -fsS -o /dev/null "${site}install/" && break
  sleep 2
done

echo "== install $(basename "$ARCHIVE")"
admin_email=rehearsal-admin@clientfence.com
admin_password="Ad-$(python3 -I -c 'import secrets; print(secrets.token_urlsafe(12))')-1a"
api_token=$(secret)
{
  printf 'form = "database_hostname=db"\nform = "database_port=3306"\n'
  printf 'form = "database_name=clientfence"\nform = "database_username=clientfence"\n'
  printf 'form = "database_password=%s"\n' "$db_password"
  printf 'form = "admin_name=Rehearsal"\nform = "admin_email=%s"\n' "$admin_email"
  printf 'form = "admin_password=%s"\n' "$admin_password"
  printf 'form = "currency_code=USD"\nform = "system_url=%s"\n' "$site"
} | curl -fsS -o "$work/install.html" --config - "${site}install/install.php?a=install"
grep -q 'Installation Completed' "$work/install.html" || { echo "the installer did not finish:" >&2; sed 's/<[^>]*>//g' "$work/install.html" | grep -v '^\s*$' | head -20 >&2; exit 1; }
printf "UPDATE admin SET api_token = '%s' WHERE email = '%s';\n" "$api_token" "$admin_email" \
  | docker exec -i "$name-db" sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb -uclientfence clientfence'

echo "== import settings"
key=""
if [[ -n $KEY_ENV ]]; then
  key=${!KEY_ENV}
elif [[ $SQL == "$ROOT/tools/whmcs-rehearsal/demo.sql" ]]; then
  key=demo-whmcs-encryption-key
fi
# The API call's form on stdin and its login in a file of the work folder: no secret on a command line.
umask 077
printf 'user = "admin:%s"\n' "$api_token" > "$work/curl-auth"
# No email ever leaves a rehearsal (a real WHMCS dump holds real clients' addresses): outgoing
# mail goes to Symfony's null transport, which discards it, and the check below stops the
# rehearsal if that did not take.
printf 'data-urlencode = "ext=mod_email"\ndata-urlencode = "mailer=custom"\ndata-urlencode = "custom_dsn=null://null"\n' \
  | curl -fsS --config "$work/curl-auth" --config - "${site}api/admin/extension/config_save" >/dev/null
curl -fsS --config "$work/curl-auth" --data-urlencode "ext=mod_email" "${site}api/admin/extension/config_get" \
  | python3 -I -c 'import json,sys; c=(json.load(sys.stdin).get("result") or {}); sys.exit(0) if c.get("mailer") == "custom" and c.get("custom_dsn") == "null://null" else sys.exit("outgoing mail could not be switched off: stopping")'
echo "outgoing mail: off (null transport)"
{
  printf 'data-urlencode = "host=db"\ndata-urlencode = "port=3306"\ndata-urlencode = "database=whmcs"\n'
  printf 'data-urlencode = "username=whmcsread"\ndata-urlencode = "password=%s"\n' "$reader_password"
  printf 'data-urlencode = "encryption_key=%s"\n' "$key"
  if [[ -n $ATTACHMENTS ]]; then printf 'data-urlencode = "attachments_dir=/whmcs-attachments"\n'; fi
} | curl -fsS --config "$work/curl-auth" --config - "${site}api/admin/clientfence/whmcs_import_save" | python3 -I -c 'import json,sys; r=json.load(sys.stdin); print("WHMCS", r["result"]["version"]) if r.get("result") else sys.exit("save failed: " + str(r.get("error")))'
unset key reader_password

# The import checks the console's PHP first and refuses a memory limit under 256M (the image's CLI
# default is 128M): the rehearsal gives it more, as its fix says.
console() { docker exec -u www-data "$name-app" php -d memory_limit=512M console.php clientfence:whmcs-import "$@"; }
echo "== plan"
console plan

if [[ $KEEP == 1 ]]; then
  printf 'ADMIN_EMAIL=%s\nADMIN_PASSWORD=%s\n' "$admin_email" "$admin_password" > "$work/admin.env"
  echo "kept: $site  (staff login in $work/admin.env)"
  echo "remove with: $0 --clean $name"
  exit 0
fi

echo "== apply"
console apply
echo "== verify"
console verify
echo "WHMCS REHEARSAL FINISHED"
