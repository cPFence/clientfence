#!/usr/bin/env bash
# Refreshes the bundled GeoIP databases (src/library/ClientFence/GeoIP/Databases) from HostByBelle's
# IP-Geolocation-DB (CC0 country, PDDL ASN; a new build about every day), so a release ships a
# current copy. Installs then update them every 7 days themselves (System cron, GeoIP\Reader).
#
#   tools/update-geoip.sh               download both, check them, put them in place
#   tools/update-geoip.sh --check DAYS  only check the bundled ones: usable and built at most DAYS
#                                       days ago (tools/build-release.sh runs it with 7)
#
# Both use GeoIP\Reader::checkDatabase(), the check installs run on their weekly download, in the
# test image (built from this checkout). Commit the new files with the release.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
DB="$ROOT/src/library/ClientFence/GeoIP/Databases"
BASE=https://github.com/HostByBelle/IP-Geolocation-DB/releases/latest/download
MAX_DAYS=""
case ${1:-} in
  "") ;;
  --check) [[ ${2:-} =~ ^[0-9]+$ ]] || { echo "--check needs a number of days" >&2; exit 2; }; MAX_DAYS=$2 ;;
  *) sed -n '6,8p' "$0" | sed 's/^# //'; exit 2 ;;
esac

docker build -q --target test -t clientfence-test "$ROOT" >/dev/null

# Prints "<kind> <build date>" per database and fails when one is not usable or too old.
check() {
  docker run --rm -v "$1:/db:ro" -e MAX_DAYS="$MAX_DAYS" clientfence-test php -r '
    require "src/vendor/autoload.php";
    $failed = false;
    foreach (["country" => "/db/CC0-Country.mmdb", "asn" => "/db/PDDL-ASN.mmdb"] as $kind => $file) {
        try {
            ClientFence\GeoIP\Reader::checkDatabase($file, $kind);
            $built = (new MaxMind\Db\Reader($file))->metadata()->buildEpoch;
            $days = intdiv(time() - $built, 86400);
            echo "$kind database: built ", gmdate("Y-m-d", $built), " ($days days ago)\n";
            if (getenv("MAX_DAYS") !== "" && $days > (int) getenv("MAX_DAYS")) {
                echo "  older than ", getenv("MAX_DAYS"), " days: run tools/update-geoip.sh and commit the files\n";
                $failed = true;
            }
        } catch (Throwable $e) {
            echo "$kind database: not usable: ", $e->getMessage(), "\n";
            $failed = true;
        }
    }
    exit($failed ? 1 : 0);'
}

if [[ -n $MAX_DAYS ]]; then
  check "$DB"
  exit
fi

work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
chmod 755 "$work"
curl -fsSL --retry 3 -o "$work/CC0-Country.mmdb" "$BASE/cc0-both-country.mmdb"
curl -fsSL --retry 3 -o "$work/PDDL-ASN.mmdb" "$BASE/pddl-asn-both.mmdb"
chmod 644 "$work"/*.mmdb
check "$work"
cp "$work/CC0-Country.mmdb" "$work/PDDL-ASN.mmdb" "$DB/"
echo "Updated $DB"
