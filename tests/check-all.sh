#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$ROOT"
TEST_DIR=$(mktemp -d /tmp/crosschapp-check-XXXXXX)
export CROSSCHAPP_TEST_DATA_DIR="$TEST_DIR/data"
export CROSSCHAPP_TEST_PORT="${CROSSCHAPP_TEST_PORT:-18082}"
COMPOSE=(docker compose -p "crosschapp-check-$$" -f docker-compose.test.yml)
CHROMEDRIVER_PID=''
RESULTS=()
mkdir -p "$CROSSCHAPP_TEST_DATA_DIR"

cleanup() {
    if [[ -n "$CHROMEDRIVER_PID" ]]; then kill "$CHROMEDRIVER_PID" 2>/dev/null || true; wait "$CHROMEDRIVER_PID" 2>/dev/null || true; fi
    "${COMPOSE[@]}" exec -T test-web sh -lc 'find /var/www/data -mindepth 1 -maxdepth 1 -delete' >/dev/null 2>&1 || true
    "${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
    [[ "$TEST_DIR" == /tmp/crosschapp-check-* ]] && rm -rf -- "$TEST_DIR"
}
trap cleanup EXIT INT TERM

summary() {
    printf '\nCrossChAPP Check Suite\n\n'
    printf '%s\n' "${RESULTS[@]}"
}
run() {
    local label=$1; shift; local log="$TEST_DIR/${label//[^A-Za-z0-9]/_}.log"
    printf '\n== %s ==\n' "$label"
    if "$@" >"$log" 2>&1; then
        RESULTS+=("[PASS] $label"); tail -n 3 "$log" || true
    else
        local status=$?; RESULTS+=("[FAIL] $label (Exit $status)"); tail -n 30 "$log" >&2 || true; summary; printf '\nRESULT: FAIL\n'; exit "$status"
    fi
}
compose_exec() { "${COMPOSE[@]}" exec -T test-web "$@"; }
compose_exec_web() { "${COMPOSE[@]}" exec -T --user www-data test-web "$@"; }
php_group() {
    local group=$1 file
    while IFS= read -r file; do
        [[ -z "$file" || "$file" == \#* ]] && continue
        case "$group:$file" in
            accounts:account-auth.php|accounts:admin-users-overview.php|accounts:admin-user-verification.php|accounts:admin-user-role.php|accounts:user-manager.php|accounts:mysql-schema-v4.php|accounts:login-rate-limit.php|accounts:bni-verification-invitations.php|accounts:legal-settings.php|accounts:text-template-editor.php) ;;
            representation:representation-*.php) ;;
            automation:automation-refresh.php|automation:bni-request-policy.php|automation:pending-chapters.php) ;;
            search:chapter-search.php) ;;
            *) continue ;;
        esac
        compose_exec php "/var/www/tests/$file"
    done < tests/php-tests.list
}

printf 'CrossChAPP Check Suite\n'
run "Production Update Script" bash tests/production-update-script.sh
run "Test Container" "${COMPOSE[@]}" up -d --build
run "Container Health" bash -c "for i in {1..40}; do curl -fsS http://127.0.0.1:$CROSSCHAPP_TEST_PORT/api/health.php && exit 0; sleep .25; done; exit 1"
run "PHP Syntax" compose_exec sh -lc 'find /var/www/html /var/www/tests -type f -name "*.php" -print0 | xargs -0 -n1 php -l'
run "Account / Auth Tests" php_group accounts
run "Representation Tests" php_group representation
run "Automation X/Y/Z Tests" php_group automation
run "Search Tests" php_group search
run "Database Integrity" compose_exec php /var/www/tests/check-database-integrity.php
run "Isolated Fixture" compose_exec_web php /var/www/tests/check-fixture.php
run "Worker Heartbeat" compose_exec php /var/www/tests/mysql-worker-heartbeat.php
run "MariaDB Race Conditions" compose_exec sh /var/www/tests/check-mysql-races.sh

chromedriver --port=9519 --allowed-ips=127.0.0.1 >"$TEST_DIR/chromedriver.log" 2>&1 & CHROMEDRIVER_PID=$!
run "Chromedriver" bash -c 'for i in {1..40}; do curl -fsS http://127.0.0.1:9519/status >/dev/null && exit 0; sleep .25; done; exit 1'
run "Chromium Smoke / Privacy" env CROSSCHAPP_WEBDRIVER_URL=http://127.0.0.1:9519 CROSSCHAPP_TEST_BASE_URL="http://127.0.0.1:$CROSSCHAPP_TEST_PORT" python3 tests/smoke-browser.py
run "Mail Guard" compose_exec php /var/www/tests/check-mail-capture.php
run "External Network Guard" compose_exec php -r 'require "/var/www/html/src/HttpClient.php"; try {(new HttpClient())->getJson("https://bni.de/forbidden-in-check-suite"); exit(1);} catch (RuntimeException $e) {if (!str_contains($e->getMessage(), "deaktiviert")) throw $e;} echo "PASS externer HTTP-Zugriff blockiert\n";'
run "git diff --check" git diff --check
if [[ "${CROSSCHAPP_CHECK_FORCE_FAIL:-0}" == 1 ]]; then run "Controlled Failure" false; fi
summary
printf '\nRESULT: PASS\n'
