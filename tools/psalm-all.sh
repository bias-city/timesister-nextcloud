#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Psalm against every Nextcloud major version from appinfo/info.xml
# (min..max) and against master – like the CI, just local and in one command.
#
#   tools/psalm-all.sh              # min..max from info.xml, plus master
#   tools/psalm-all.sh 35 master    # only these versions
#
# Composer: `composer` in the PATH, or COMPOSER_BIN="php /path/composer.phar".
# composer.json and composer.lock are back to how they were afterwards.
# Result: 0 if every version from info.xml is clean. master is only an
# early warning and doesn't fail the command (STRICT_MASTER=1 does).
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
  printf '\n\033[1;34m==>\033[0m Psalm against nextcloud/ocp:%s\n' "$ocp"
  if ! $CMP require --dev "nextcloud/ocp:$ocp" --ignore-platform-reqs --with-dependencies --no-interaction --quiet >"$tmp/composer-$v.log" 2>&1; then
    summary+=("$v  composer failed (does $ocp exist?)")
    [ "$v" = master ] && [ "${STRICT_MASTER:-0}" != 1 ] || fail=1
    tail -n 5 "$tmp/composer-$v.log"
    continue
  fi
  if vendor/bin/psalm --threads=1 --no-cache --no-progress --monochrome >"$tmp/psalm-$v.log" 2>&1; then
    summary+=("$v  clean")
  else
    n=$(grep -cE '^ERROR:' "$tmp/psalm-$v.log" || true)
    summary+=("$v  $n error(s)")
    grep -E '^ERROR:' -A2 "$tmp/psalm-$v.log" | head -n 60
    if [ "$v" != master ] || [ "${STRICT_MASTER:-0}" = 1 ]; then fail=1; fi
  fi
done

printf '\n\033[1mResult\033[0m (info.xml: %s to %s)\n' "$min" "$max"
for s in "${summary[@]}"; do printf '  %s\n' "$s"; done
exit $fail
