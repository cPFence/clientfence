#!/usr/bin/env bash
# ClientFence local test site (see dev/README.md).
#   up      start it; the first run makes dev/.env, builds the image, installs and seeds
#   update  rebuild the image from this checkout and restart the site (data and settings stay)
#   seed    add missing test data and set the base settings again
#   status  show the containers and the addresses
#   logs    follow the site's logs
#   down    stop it (data stays)
#   reset   delete everything (database, files, settings) and install again
set -euo pipefail

DEV=$(cd "$(dirname "$0")" && pwd)
ENV_FILE="$DEV/.env"
STATE="$DEV/state"
SEEDED="$DEV/.seeded"
# Secrets never go on a command line (other users of the machine can read those): they reach
# containers through the environment or stdin.

compose() {
  # The project name is fixed, so down/reset never touch another Compose project.
  docker compose --project-name clientfence-dev --project-directory "$DEV" --env-file "$ENV_FILE" \
    --file "$DEV/compose.yml" "$@"
}

make_env() {
  if [[ -f "$ENV_FILE" ]]; then
    # Keys added after a site's first run.
    grep -q '^BACKUP_PASSWORD=' "$ENV_FILE" \
      || (umask 077 && printf 'BACKUP_PASSWORD=%s\n' "$(python3 -I -c 'import secrets; print(secrets.token_hex(12))')" >> "$ENV_FILE")
    return
  fi
  (umask 077 && python3 -I - "$ENV_FILE" <<'PY'
import os, secrets, string, sys

def password():
    # Upper, lower, digit and symbol, as the installer's password rules ask.
    alphabet = string.ascii_letters + string.digits
    core = ''.join(secrets.choice(alphabet) for _ in range(16))
    return 'Bf' + core + '7!'

token = ''.join(secrets.choice(string.ascii_uppercase + string.digits) for _ in range(32))
# ClientFence checks that an email's domain has mail records, so test addresses need a real
# domain (export TEST_EMAIL_DOMAIN before the first run). Mailpit catches every
# email, so nothing is delivered to it.
domain = os.environ.get('TEST_EMAIL_DOMAIN')
if not domain:
    sys.exit('export TEST_EMAIL_DOMAIN with a domain that has mail (MX) records')
lines = {
    'SITE_PORT': '8090',
    'MAIL_PORT': '8091',
    'SITE_URL': 'http://clientfence.localhost:8090/',
    'DB_PASSWORD': secrets.token_hex(16),
    'DB_ROOT_PASSWORD': secrets.token_hex(16),
    'TEST_EMAIL_DOMAIN': domain,
    'ADMIN_NAME': 'Test Admin',
    'ADMIN_EMAIL': f'test-admin@{domain}',
    'ADMIN_PASSWORD': password(),
    'ADMIN_API_TOKEN': token,
    'STAFF_EMAIL': f'test-support@{domain}',
    'STAFF_PASSWORD': password(),
    'CLIENT_PASSWORD': password(),
    'BACKUP_PASSWORD': secrets.token_hex(12),
}
with open(sys.argv[1], 'w') as f:
    for key, value in lines.items():
        f.write(f'{key}={value}\n')
PY
  )
  echo "wrote $ENV_FILE (passwords for the test site; git-ignored)"
}

env_get() {
  grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2-
}

# dev/state belongs to the web server user (uid 33) once the site runs, so the host user may not
# read it: these two look and delete from a container.
installed() {
  local code=0
  compose run --rm --no-deps -T --entrypoint test app -f /var/www/clientfence-state/config.php || code=$?
  case $code in
    0) return 0 ;;
    1) return 1 ;;
    *) echo "could not check the install (docker exit $code)" >&2; exit "$code" ;;
  esac
}

forget_install() {
  compose run --rm --no-deps -T --entrypoint rm app -f /var/www/clientfence-state/config.php
}

wait_for() {
  local url=$1
  for _ in $(seq 1 90); do
    if compose exec -T app curl -fsS -o /dev/null "$url"; then
      return 0
    fi
    sleep 2
  done
  echo "the site did not answer at $url" >&2
  return 1
}

install_site() {
  compose exec -T app rm -f /var/www/html/config.php
  wait_for "http://127.0.0.1/install/"
  # The installer form, given to curl on stdin (printf is a shell builtin: no command line).
  {
    printf 'form = "database_hostname=db"\nform = "database_port=3306"\n'
    printf 'form = "database_name=clientfence"\nform = "database_username=clientfence"\n'
    printf 'form = "database_password=%s"\n' "$(env_get DB_PASSWORD)"
    printf 'form = "admin_name=%s"\n' "$(env_get ADMIN_NAME)"
    printf 'form = "admin_email=%s"\n' "$(env_get ADMIN_EMAIL)"
    printf 'form = "admin_password=%s"\n' "$(env_get ADMIN_PASSWORD)"
    printf 'form = "currency_code=USD"\n'
    printf 'form = "system_url=%s"\n' "$(env_get SITE_URL)"
  } | compose exec -T app curl -fsS -o /dev/null --config - "http://127.0.0.1/install/install.php?a=install"
  # The installer keeps an API token only in testing mode; the seed needs one. The SQL goes in
  # on stdin and the password through MYSQL_PWD.
  local rows
  rows=$(printf "UPDATE admin SET api_token = '%s' WHERE email = '%s';\nSELECT ROW_COUNT();\n" \
      "$(env_get ADMIN_API_TOKEN)" "$(env_get ADMIN_EMAIL)" \
    | compose exec -T db sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -uroot -N clientfence')
  if [[ "$rows" != "1" ]]; then
    echo "could not set the admin API token (rows changed: $rows)" >&2
    return 1
  fi
  # config.php moves to dev/state/ (owned by the web server user, mode 600) and is linked in.
  compose exec -T app sh -c 'mv /var/www/html/config.php /var/www/clientfence-state/config.php \
    && chown www-data:www-data /var/www/clientfence-state/config.php \
    && chmod 600 /var/www/clientfence-state/config.php \
    && ln -sfn /var/www/clientfence-state/config.php /var/www/html/config.php \
    && chown -h www-data:www-data /var/www/html/config.php'
  wait_for "http://127.0.0.1/"
  echo "installed"
}

seed() {
  python3 -I "$DEV/seed.py" "$ENV_FILE"
  touch "$SEEDED"
}

show() {
  echo "site:  $(env_get SITE_URL)  (admin: $(env_get SITE_URL)admin)"
  echo "mail:  http://clientfence.localhost:$(env_get MAIL_PORT)/"
  local smtp_port
  smtp_port=$(grep -E '^SMTP_PORT=' "$ENV_FILE" | cut -d= -f2- || true)
  echo "smtp:  127.0.0.1:${smtp_port:-8092} (test mailbox server for ticket email import)"
  echo "logins and passwords: $ENV_FILE"
}

case "${1:-}" in
  up)
    make_env
    mkdir -p -m 700 "$STATE"
    compose up --detach --build
    if ! installed; then
      install_site
    fi
    # Also when an earlier first run stopped during the seed.
    if [[ ! -f "$SEEDED" ]]; then
      seed
    fi
    show
    ;;
  update)
    make_env
    compose build app
    # Starts test servers added since the first run, then the rebuilt site.
    compose up --detach
    compose up --detach --force-recreate app
    wait_for "http://127.0.0.1/"
    # The compiled templates live in the data volume: clear them, or edited templates stay old.
    compose exec -T -u www-data app php console.php cache:clear >/dev/null
    show
    ;;
  seed)
    seed
    ;;
  status)
    compose ps
    show
    ;;
  logs)
    compose logs --follow app
    ;;
  down)
    compose down
    ;;
  reset)
    forget_install
    compose down --volumes
    rm -f "$SEEDED"
    "$0" up
    ;;
  *)
    sed -n '2,9p' "$0"
    exit 2
    ;;
esac
