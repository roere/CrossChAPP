#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
SCRIPT="$ROOT/deployment/update-production.sh"
bash -n "$SCRIPT"
if grep -Eq 'down[[:space:]]+-v|volume[[:space:]]+rm' "$SCRIPT"; then
    printf 'Destruktiver Docker-Befehl im Produktionsskript gefunden.\n' >&2
    exit 1
fi

TEST_ROOT=$(mktemp -d /tmp/crosschapp-production-update-XXXXXX)
trap 'rm -rf -- "$TEST_ROOT"' EXIT
mkdir -p "$TEST_ROOT/deployment" "$TEST_ROOT/app/src" "$TEST_ROOT/bin"
cp "$SCRIPT" "$TEST_ROOT/deployment/update-production.sh"
: >"$TEST_ROOT/app/src/Database.php"
: >"$TEST_ROOT/app/src/MysqlSchema.php"
: >"$TEST_ROOT/docker-compose.yml"
: >"$TEST_ROOT/docker-compose.prod.yml"
: >"$TEST_ROOT/.env.production"
cat >"$TEST_ROOT/bin/docker" <<'SH'
#!/usr/bin/env bash
printf 'docker %s\n' "$*" >>"$DRY_RUN_COMMAND_LOG"
if [[ "${1:-}" == compose && "${2:-}" == version ]]; then printf 'Docker Compose version test\n'; fi
if [[ " $* " == *' config '* && "${FAIL_COMPOSE_CONFIG:-0}" == 1 ]]; then exit 42; fi
SH
chmod +x "$TEST_ROOT/bin/docker" "$TEST_ROOT/deployment/update-production.sh"
(
    cd "$TEST_ROOT"
    git init -q
    git config user.email test@example.invalid
    git config user.name Test
    git add .
    git commit -qm initial
    git tag vtest
)

export DRY_RUN_COMMAND_LOG="$TEST_ROOT/.git/commands.log"
if PATH="$TEST_ROOT/bin:$PATH" "$TEST_ROOT/deployment/update-production.sh" >"$TEST_ROOT/.git/usage.out" 2>&1; then
    printf 'Aufruf ohne Git-Ref wurde akzeptiert.\n' >&2
    exit 1
fi
grep -q 'Verwendung:' "$TEST_ROOT/.git/usage.out"
PATH="$TEST_ROOT/bin:$PATH" "$TEST_ROOT/deployment/update-production.sh" --dry-run vtest >"$TEST_ROOT/.git/dry-run.out"
grep -q 'keine Dateien, Images, Container oder Datenbanken wurden verändert' "$TEST_ROOT/.git/dry-run.out"
grep -q ' config$' "$DRY_RUN_COMMAND_LOG"
if grep -Eq 'build|up |exec |fetch|checkout|mariadb-dump' "$DRY_RUN_COMMAND_LOG"; then
    printf 'Dry-run hat einen mutierenden Befehl ausgeführt.\n' >&2
    exit 1
fi
if PATH="$TEST_ROOT/bin:$PATH" "$TEST_ROOT/deployment/update-production.sh" --dry-run does-not-exist >/dev/null 2>&1; then
    printf 'Ungültiger Ref wurde akzeptiert.\n' >&2
    exit 1
fi
if FAIL_COMPOSE_CONFIG=1 PATH="$TEST_ROOT/bin:$PATH" "$TEST_ROOT/deployment/update-production.sh" --dry-run vtest >"$TEST_ROOT/.git/config.out" 2>&1; then
    printf 'Ungültige Compose-Konfiguration wurde akzeptiert.\n' >&2
    exit 1
fi
printf 'dirty\n' >>"$TEST_ROOT/docker-compose.yml"
if PATH="$TEST_ROOT/bin:$PATH" "$TEST_ROOT/deployment/update-production.sh" --dry-run vtest >"$TEST_ROOT/.git/dirty.out" 2>&1; then
    printf 'Dirty Worktree wurde akzeptiert.\n' >&2
    exit 1
fi
grep -q 'Arbeitsverzeichnis enthält lokale Änderungen' "$TEST_ROOT/.git/dirty.out"
git -C "$TEST_ROOT" checkout -q -- docker-compose.yml
rm "$TEST_ROOT/.env.production"
if PATH="$TEST_ROOT/bin:$PATH" "$TEST_ROOT/deployment/update-production.sh" --dry-run vtest >"$TEST_ROOT/.git/env.out" 2>&1; then
    printf 'Fehlende .env.production wurde akzeptiert.\n' >&2
    exit 1
fi
grep -q 'Fehlende Voraussetzung: .env.production' "$TEST_ROOT/.git/env.out"
printf 'PASS Produktions-Update-Skript: Syntax, Guards, Ref, Dirty Worktree und nichtmutierender Dry-run\n'
