#!/usr/bin/env bash
# Gate locale di SEO Lab: lint, guardia di uninstall, suite S0-S4 e copertura delle fixture 01-10.
# Gira sull'host e nel container php:7.4.33-cli con mount di sola lettura: usa solo bash, php e coreutils.
# Il workflow CI invoca questo stesso entrypoint. Ogni errore termina con exit non zero.
set -eu

ROOT="$(cd -- "$(dirname -- "$0")/.." && pwd)"
cd -- "$ROOT"
WORK="${TMPDIR:-/tmp}"

fail() {
    echo "FAIL $1" >&2
    exit 1
}

echo "php: $(php -r 'echo PHP_VERSION;')"

mapfile -t PHP_FILES < <(find seo-lab.php uninstall.php src tests -name '*.php' | sort)
for file in "${PHP_FILES[@]}"; do
    php -l "$file" > /dev/null || fail "lint $file"
done
echo "OK lint: ${#PHP_FILES[@]} file PHP"

# Senza WP_UNINSTALL_PLUGIN il file deve uscire prima di qualsiasi effetto (nessun output, exit 0).
uninstall_out="$(php uninstall.php 2>&1)" || fail "uninstall senza costante: exit non zero"
[ -z "$uninstall_out" ] || fail "uninstall senza costante: output inatteso"
echo "OK uninstall: guardia WP_UNINSTALL_PLUGIN"

run_suite() {
    local name="$1" script="$2"
    local log="$WORK/ci-$name.log"
    php "$script" > "$log" 2>&1 || { cat "$log" >&2; fail "suite $name"; }
    echo "OK $name: $(grep -v '^FIXTURE ' "$log" | tail -n 1)"
}

check_fixtures() {
    local name="$1" expected="$2"
    local log="$WORK/ci-$name.log"
    grep '^FIXTURE ' "$log" | sed 's/^FIXTURE //' | sort > "$WORK/ci-$name.fixtures"
    printf '%s\n' $expected | sort > "$WORK/ci-$name.expected"
    diff "$WORK/ci-$name.expected" "$WORK/ci-$name.fixtures" > /dev/null || fail "fixture attese da $name: $expected"
    echo "OK fixture da $name: $expected"
}

run_suite s0-contracts tests/s0-contracts.php
run_suite s1-capture tests/s1-capture.php
run_suite s2-diagnosis tests/s2-diagnosis.php
check_fixtures s2-diagnosis "01 02 03 04 05 06 10"
run_suite s3-jsonld tests/s3-jsonld.php
check_fixtures s3-jsonld "07 08 09"
run_suite s4-report tests/s4-report.php
check_fixtures s4-report "10"
run_suite s5-uninstall-offline tests/s5-uninstall-offline.php
run_suite p1-foundation tests/p1-foundation.php
run_suite p1-connectors tests/p1-connectors.php
run_suite p1-admin tests/p1-admin.php

cat "$WORK"/ci-s2-diagnosis.fixtures "$WORK"/ci-s3-jsonld.fixtures "$WORK"/ci-s4-report.fixtures | sort -u > "$WORK/ci-fixtures-union.txt"
printf '%s\n' 01 02 03 04 05 06 07 08 09 10 | diff - "$WORK/ci-fixtures-union.txt" > /dev/null || fail "unione fixture diversa da 01-10"
echo "OK fixture 01-10: 10/10 coperte dai runner S2, S3, S4"
echo "OK run-ci: tutti i gate locali superati"
