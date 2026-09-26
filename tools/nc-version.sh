#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Hebt die App in einem Schritt auf eine neue Nextcloud-Hauptversion:
#   1. max-version in appinfo/info.xml
#   2. die Psalm-Stubs (nextcloud/ocp) in composer.json
#   3. das Image-Tag der Entwicklungs-Nextcloud (docker-compose.yml), wenn angegeben
# und sagt danach, was als Nächstes zu prüfen ist (UPDATE.md).
#
#   tools/nc-version.sh 35
#   tools/nc-version.sh 35 ../../docker-next/docker-compose.yml
#   NC_COMPOSE_FILE=/pfad/docker-compose.yml tools/nc-version.sh 35
#
# Composer: `composer` im PATH oder COMPOSER_BIN="php /pfad/composer.phar".
set -euo pipefail
cd "$(dirname "$0")/.."

major=${1:-}
if ! [[ "$major" =~ ^[0-9]{2,3}$ ]]; then
  echo "Aufruf: tools/nc-version.sh <hauptversion> [docker-compose.yml]" >&2
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
  echo "    $major liegt unter min-version $min – so nicht." >&2
  exit 1
fi
sed -i.bak -E "s/(<nextcloud [^>]*max-version=\")[0-9]+(\")/\1$major\2/" appinfo/info.xml && rm -f appinfo/info.xml.bak
ok "max-version $old → $major (min-version bleibt $min)"
if [ $((major - min)) -gt 1 ]; then
  note "Unterstützt jetzt $min bis $major. Nur zwei Hauptversionen? min-version von Hand auf $((major - 1)) setzen."
fi

# ------------------------------------------------------------ 2. Psalm-Stubs
say "Psalm-Stubs (nextcloud/ocp)"
ocp="dev-stable$major"
if command -v curl >/dev/null && ! curl -fsS "https://repo.packagist.org/p2/nextcloud/ocp~dev.json" 2>/dev/null | grep -q "\"$ocp\""; then
  note "$ocp gibt es noch nicht – nehme dev-master (vor der Veröffentlichung von $major)."
  ocp=dev-master
fi
sed -i.bak -E "s#(\"nextcloud/ocp\": \")[^\"]+(\")#\1$ocp\2#" composer.json && rm -f composer.json.bak
ok "composer.json: nextcloud/ocp → $ocp"
if $CMP --version >/dev/null 2>&1; then
  $CMP update nextcloud/ocp --with-dependencies --ignore-platform-reqs --no-interaction --quiet && ok "composer.lock nachgezogen"
else
  note "Composer nicht gefunden: danach 'composer update nextcloud/ocp' laufen lassen."
fi

# ------------------------------------------------------------- 3. Docker-Tag
say "Entwicklungs-Nextcloud"
if [ -n "$compose" ] && [ -f "$compose" ]; then
  sed -i.bak -E "s#nextcloud:[0-9]+-apache#nextcloud:$major-apache#g" "$compose" && rm -f "$compose.bak"
  ok "$(basename "$compose"): Image nextcloud:$major-apache"
else
  note "übersprungen – Pfad zu docker-compose.yml als 2. Argument oder NC_COMPOSE_FILE angeben."
fi

# ------------------------------------------------------ 4. PHP-Mindestfassung
# psalm.xml braucht die kleinste PHP-Fassung aller unterstützten Versionen,
# also die der min-version (so prüft es die CI).
php_of() {
  local id
  id=$(curl -fsS "https://raw.githubusercontent.com/nextcloud/server/$1/lib/versioncheck.php" 2>/dev/null \
       | sed -n 's/.*PHP_VERSION_ID < \([0-9]*\).*/\1/p' | head -n1)
  [ -n "$id" ] && echo "$((id / 10000)).$(((id / 100) % 100))"
}
if command -v curl >/dev/null; then
  say "PHP-Fassung für psalm.xml"
  want=$(php_of "stable${min}" || true)
  cur=$(sed -n 's/.*phpVersion="\([0-9.]*\)".*/\1/p' psalm.xml)
  if [ -z "$want" ]; then
    note "PHP-Mindestfassung von Nextcloud ${min} nicht ermittelt – psalm.xml von Hand prüfen."
  elif [ "$want" != "$cur" ]; then
    note "Nextcloud ${min} verlangt PHP ≥ ${want}; psalm.xml sagt ${cur}. phpVersion anpassen."
  else
    ok "phpVersion ${cur} = PHP-Mindestfassung von Nextcloud ${min}"
  fi
  top=$(php_of "stable${major}" || true)
  [ -n "$top" ] && ok "Nextcloud ${major} verlangt PHP ≥ ${top} (docker-next und CI wählen das selbst)"
fi

cat <<NEXT

  Nächste Schritte (UPDATE.md):
    1. composer run psalm:all          Psalm gegen ${min}…${major} und master
    2. Befunde beheben – nur OCP, nichts Veraltetes
    3. Entwicklungs-Nextcloud neu:     zuruecksetzen.sh (sichert vorher die Datenbank)
    4. composer run test:unit && NC_URL=… composer run test:integration
    5. <version> in appinfo/info.xml heben, CHANGELOG, Tag nc-app-v<version>
    6. signieren und in den App Store laden
NEXT
