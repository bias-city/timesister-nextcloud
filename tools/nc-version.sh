#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Raises the app to a new Nextcloud major version in one step:
#   1. max-version in appinfo/info.xml
#   2. the Psalm stubs (nextcloud/ocp) in composer.json
#   3. the image tag of the development Nextcloud (docker-compose.yml), if given
# and afterwards says what to check next (UPDATE.md).
#
#   tools/nc-version.sh 35
#   tools/nc-version.sh 35 ../../docker-next/docker-compose.yml
#   NC_COMPOSE_FILE=/path/docker-compose.yml tools/nc-version.sh 35
#
# Composer: `composer` in the PATH, or COMPOSER_BIN="php /path/composer.phar".
set -euo pipefail
cd "$(dirname "$0")/.."

major=${1:-}
if ! [[ "$major" =~ ^[0-9]{2,3}$ ]]; then
  echo "Usage: tools/nc-version.sh <major-version> [docker-compose.yml]" >&2
  exit 2
fi
compose=${2:-${NC_COMPOSE_FILE:-}}
CMP=${COMPOSER_BIN:-composer}

say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
ok()  { printf '    \033[32mok\033[0m  %s\n' "$*"; }
note(){ printf '    \033[33m--\033[0m  %s\n' "$*"; }

# ---------------------------------------------------------------- 1. info.xml
say "appinfo/info.xml"
line=$(grep -o '<nextcloud [^>]*>' appinfo/info.xml)
min=$(sed -n 's/.*min-version="\([0-9]*\)".*/\1/p' <<<"$line")
old=$(sed -n 's/.*max-version="\([0-9]*\)".*/\1/p' <<<"$line")
if [ "$major" -lt "$min" ]; then
  echo "    $major is below min-version $min – not doing that." >&2
  exit 1
fi
sed -i.bak -E "s/(<nextcloud [^>]*max-version=\")[0-9]+(\")/\1$major\2/" appinfo/info.xml && rm -f appinfo/info.xml.bak
ok "max-version $old → $major (min-version stays $min)"
if [ $((major - min)) -gt 1 ]; then
  note "Now supports $min through $major. Only two major versions? Set min-version to $((major - 1)) by hand."
fi

# ------------------------------------------------------------ 2. Psalm stubs
say "Psalm stubs (nextcloud/ocp)"
ocp="dev-stable$major"
if command -v curl >/dev/null && ! curl -fsS "https://repo.packagist.org/p2/nextcloud/ocp~dev.json" 2>/dev/null | grep -q "\"$ocp\""; then
  note "$ocp doesn't exist yet – using dev-master (before $major is released)."
  ocp=dev-master
fi
sed -i.bak -E "s#(\"nextcloud/ocp\": \")[^\"]+(\")#\1$ocp\2#" composer.json && rm -f composer.json.bak
ok "composer.json: nextcloud/ocp → $ocp"
if $CMP --version >/dev/null 2>&1; then
  $CMP update nextcloud/ocp --with-dependencies --ignore-platform-reqs --no-interaction --quiet && ok "composer.lock updated"
else
  note "Composer not found: run 'composer update nextcloud/ocp' afterwards."
fi

# ------------------------------------------------------------- 3. Docker tag
say "Development Nextcloud"
if [ -n "$compose" ] && [ -f "$compose" ]; then
  sed -i.bak -E "s#nextcloud:[0-9]+-apache#nextcloud:$major-apache#g" "$compose" && rm -f "$compose.bak"
  ok "$(basename "$compose"): image nextcloud:$major-apache"
else
  note "skipped – give the path to docker-compose.yml as the 2nd argument, or set NC_COMPOSE_FILE."
fi

# ------------------------------------------------------ 4. Minimum PHP version
# psalm.xml needs the smallest PHP version of all supported Nextcloud
# versions, i.e. that of min-version (that's what the CI checks against).
php_of() {
  local id
  id=$(curl -fsS "https://raw.githubusercontent.com/nextcloud/server/$1/lib/versioncheck.php" 2>/dev/null \
       | sed -n 's/.*PHP_VERSION_ID < \([0-9]*\).*/\1/p' | head -n1)
  [ -n "$id" ] && echo "$((id / 10000)).$(((id / 100) % 100))"
}
if command -v curl >/dev/null; then
  say "PHP version for psalm.xml"
  want=$(php_of "stable${min}" || true)
  cur=$(sed -n 's/.*phpVersion="\([0-9.]*\)".*/\1/p' psalm.xml)
  if [ -z "$want" ]; then
    note "Could not determine the minimum PHP version of Nextcloud ${min} – check psalm.xml by hand."
  elif [ "$want" != "$cur" ]; then
    note "Nextcloud ${min} requires PHP ≥ ${want}; psalm.xml says ${cur}. Adjust phpVersion."
  else
    ok "phpVersion ${cur} = minimum PHP version of Nextcloud ${min}"
  fi
  top=$(php_of "stable${major}" || true)
  [ -n "$top" ] && ok "Nextcloud ${major} requires PHP ≥ ${top} (docker-next and CI pick that themselves)"
fi

cat <<NEXT

  Next steps (UPDATE.md):
    1. composer run psalm:all          Psalm against ${min}…${major} and master
    2. Fix findings – OCP only, nothing deprecated
    3. Development Nextcloud, fresh:   zuruecksetzen.sh (backs up the database first)
    4. composer run test:unit && NC_URL=… composer run test:integration
    5. Raise <version> in appinfo/info.xml, CHANGELOG, tag nc-app-v<version>
    6. sign and upload to the App Store
NEXT
