#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Psalm gegen jede Nextcloud-Hauptversion aus appinfo/info.xml (min..max)
# und gegen master – wie die CI, nur lokal und in einem Befehl.
#
#   tools/psalm-all.sh              # min..max aus info.xml, dazu master
#   tools/psalm-all.sh 35 master    # nur diese Versionen
#
# Composer: `composer` im PATH oder COMPOSER_BIN="php /pfad/composer.phar".
# composer.json und composer.lock sind danach wieder wie vorher.
# Ergebnis: 0, wenn alle Versionen aus info.xml sauber sind. master ist
# eine Frühwarnung und lässt den Befehl nicht scheitern (STRICT_MASTER=1 doch).
set -uo pipefail
cd "$(dirname "$0")/.."

CMP=${COMPOSER_BIN:-composer}
line=$(grep -o '<nextcloud [^>]*>' appinfo/info.xml)
min=$(sed -n 's/.*min-version="\([0-9]*\)".*/\1/p' <<<"$line")
max=$(sed -n 's/.*max-version="\([0-9]*\)".*/\1/p' <<<"$line")
if [ $# -gt 0 ]; then
  versions=("$@")
else
  versions=($(seq "$min" "$max") master)
fi

tmp=$(mktemp -d)
cp composer.json "$tmp/"
[ -f composer.lock ] && cp composer.lock "$tmp/"
restore() {
  cp "$tmp/composer.json" composer.json
  [ -f "$tmp/composer.lock" ] && cp "$tmp/composer.lock" composer.lock
  $CMP install --no-interaction --quiet >/dev/null 2>&1 || true
  rm -rf "$tmp"
}
trap restore EXIT

declare -a summary
fail=0
for v in "${versions[@]}"; do
  if [ "$v" = master ]; then ocp=dev-master; else ocp="dev-stable$v"; fi
  printf '\n\033[1;34m==>\033[0m Psalm gegen nextcloud/ocp:%s\n' "$ocp"
  if ! $CMP require --dev "nextcloud/ocp:$ocp" --ignore-platform-reqs --with-dependencies --no-interaction --quiet >"$tmp/composer-$v.log" 2>&1; then
    summary+=("$v  composer fehlgeschlagen (gibt es $ocp?)")
    [ "$v" = master ] && [ "${STRICT_MASTER:-0}" != 1 ] || fail=1
    tail -n 5 "$tmp/composer-$v.log"
    continue
  fi
  if vendor/bin/psalm --threads=1 --no-cache --no-progress --monochrome >"$tmp/psalm-$v.log" 2>&1; then
    summary+=("$v  sauber")
  else
    n=$(grep -cE '^ERROR:' "$tmp/psalm-$v.log" || true)
    summary+=("$v  $n Fehler")
    grep -E '^ERROR:' -A2 "$tmp/psalm-$v.log" | head -n 60
    if [ "$v" != master ] || [ "${STRICT_MASTER:-0}" = 1 ]; then fail=1; fi
  fi
done

printf '\n\033[1mErgebnis\033[0m (info.xml: %s bis %s)\n' "$min" "$max"
for s in "${summary[@]}"; do printf '  %s\n' "$s"; done
exit $fail
