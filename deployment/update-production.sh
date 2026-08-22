#!/usr/bin/env bash
set -Eeuo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
COMPOSE=(docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml)
HEALTH_URL=${CROSSCHAPP_HEALTH_URL:-https://bni.crosschapp.de/api/health.php}
CURRENT_STEP='Initialisierung'
PREVIOUS_REF='unbekannt'
BACKUP_PATH='noch nicht erstellt'
TARGET_REF=''
DRY_RUN=0

usage() {
    printf 'Verwendung: %s [--dry-run] <Git-Ref>\n' "$0" >&2
    printf 'Beispiele: %s v0.13.0 | %s --dry-run main\n' "$0" "$0" >&2
}

failure_report() {
    local exit_code=$1 line=$2
    set +e
    printf '\nFEHLER: Schritt "%s" (Zeile %s, Exit %s).\n' "$CURRENT_STEP" "$line" "$exit_code" >&2
    printf 'Automatischer Rollback wurde nicht ausgeführt.\n' >&2
    printf 'Vorheriger Git-Ref: %s\nBackup: %s\n' "$PREVIOUS_REF" "$BACKUP_PATH" >&2
    if command -v docker >/dev/null 2>&1 && [[ -f "$ROOT/.env.production" ]]; then
        (cd "$ROOT" && "${COMPOSE[@]}" ps) >&2 || true
    fi
    printf 'Rollback nur manuell nach Prüfung von Code, Schema und Backup durchführen.\n' >&2
}
trap 'status=$?; failure_report "$status" "$LINENO"; exit "$status"' ERR

for argument in "$@"; do
    case "$argument" in
        --dry-run) DRY_RUN=1 ;;
        -h|--help) usage; exit 0 ;;
        -*) usage; exit 2 ;;
        *) if [[ -n "$TARGET_REF" ]]; then usage; exit 2; fi; TARGET_REF=$argument ;;
    esac
done
if [[ -z "$TARGET_REF" ]]; then usage; exit 2; fi
cd "$ROOT"

step() { CURRENT_STEP=$2; printf '\n[%s/8] %s\n' "$1" "$2"; }
require_file() { [[ -f "$1" ]] || { printf 'Fehlende Voraussetzung: %s\n' "$1" >&2; return 1; }; }
resolve_ref() {
    local candidate
    if [[ "$TARGET_REF" == main ]]; then candidate=refs/remotes/origin/main
    elif git show-ref --verify --quiet "refs/tags/$TARGET_REF"; then candidate="refs/tags/$TARGET_REF"
    else candidate="refs/remotes/origin/$TARGET_REF"; fi
    git rev-parse --verify "${candidate}^{commit}"
}
db_container_id() { "${COMPOSE[@]}" ps -q db; }
require_healthy_db() {
    local container_id status
    container_id=$(db_container_id)
    [[ -n "$container_id" ]] || { printf 'MariaDB-Container läuft nicht.\n' >&2; return 1; }
    status=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container_id")
    [[ "$status" == healthy ]] || { printf 'MariaDB ist nicht healthy (Status: %s).\n' "$status" >&2; return 1; }
}
wait_for_web() {
    local attempt response
    for attempt in {1..12}; do
        if response=$(curl -fsS --max-time 10 "$HEALTH_URL" 2>/dev/null) && grep -Eq '"ok"[[:space:]]*:[[:space:]]*true' <<<"$response"; then return 0; fi
        sleep 5
    done
    "${COMPOSE[@]}" logs --tail=100 web >&2 || true
    return 1
}
schema_versions() {
    "${COMPOSE[@]}" exec -T web php -r 'require_once "/var/www/html/src/Database.php"; require_once "/var/www/html/src/MysqlSchema.php"; $db=(new Database())->connection(); echo (int)$db->query("SELECT COALESCE(MAX(version),0) FROM schema_migrations")->fetchColumn()," ",MysqlSchema::LATEST_VERSION,PHP_EOL;'
}
migrate_schema() {
    if ! "${COMPOSE[@]}" exec -T web php -r 'require "/var/www/html/src/Database.php"; (new Database())->connection(); echo "Schema migration complete\n";'; then
        "${COMPOSE[@]}" logs --tail=100 web >&2 || true
        return 1
    fi
}
wait_for_worker() {
    local started_epoch=$1 attempt heartbeat
    for attempt in {1..18}; do
        heartbeat=$("${COMPOSE[@]}" exec -T web php -r 'require "/var/www/html/src/Database.php"; $value=(new Database())->connection()->query("SELECT worker_last_seen_at FROM automation_runtime WHERE id=1")->fetchColumn(); echo is_string($value)&&strtotime($value)!==false&&strtotime($value)>=(int)$argv[1]?$value:"";' "$started_epoch")
        if [[ -n "$heartbeat" ]]; then printf '%s\n' "$heartbeat"; return 0; fi
        sleep 5
    done
    "${COMPOSE[@]}" --profile worker logs --tail=100 worker >&2 || true
    return 1
}

step 1 'Voraussetzungen prüfen'
[[ "$ROOT" == /opt/crosschapp || -f "$ROOT/app/src/Database.php" ]] || { printf 'Dieses Skript muss im CrossChAPP-Projekt ausgeführt werden.\n' >&2; exit 1; }
for command_name in git docker curl gzip grep; do command -v "$command_name" >/dev/null || { printf 'Erforderliches Programm fehlt: %s\n' "$command_name" >&2; exit 1; }; done
docker compose version >/dev/null
require_file .env.production
require_file docker-compose.yml
require_file docker-compose.prod.yml
require_file app/src/Database.php
require_file app/src/MysqlSchema.php
[[ -z $(git status --porcelain) ]] || { printf 'Deployment abgebrochen: Arbeitsverzeichnis enthält lokale Änderungen.\n' >&2; exit 1; }
PREVIOUS_REF=$(git rev-parse --verify HEAD)
"${COMPOSE[@]}" config >/dev/null
if (( DRY_RUN )); then
    TARGET_COMMIT=$(resolve_ref) || { printf 'Git-Ref ist lokal nicht verfügbar: %s\n' "$TARGET_REF" >&2; exit 1; }
    printf 'Dry-run: Ref %s -> %s\n' "$TARGET_REF" "$TARGET_COMMIT"
    printf 'Geplant: Fetch/Checkout, DB-Healthcheck, Backup, Build, Web-Healthcheck, Migration, Schema-Prüfung, Worker-Heartbeat.\n'
    printf 'Dry-run abgeschlossen; keine Dateien, Images, Container oder Datenbanken wurden verändert.\n'
    exit 0
fi
"${COMPOSE[@]}" ps
require_healthy_db

step 2 'Git aktualisieren'
git fetch --tags origin
TARGET_COMMIT=$(resolve_ref) || { printf 'Git-Ref wurde nicht gefunden: %s\n' "$TARGET_REF" >&2; exit 1; }
git checkout --detach "$TARGET_COMMIT"
printf 'Ref: %s\nCommit: %s\nNachricht: %s\n' "$TARGET_REF" "$(git rev-parse HEAD)" "$(git log -1 --pretty=%s)"
"${COMPOSE[@]}" config >/dev/null
require_healthy_db

step 3 'Backup erstellen'
mkdir -p /opt/crosschapp/backups
BACKUP_PATH="/opt/crosschapp/backups/crosschapp-before-update-$(date -u +%Y%m%d-%H%M%S).sql.gz"
backup_temp="${BACKUP_PATH}.tmp"
"${COMPOSE[@]}" exec -T db sh -c 'exec mariadb-dump --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' | gzip -c >"$backup_temp"
[[ -s "$backup_temp" ]] || { printf 'Das Datenbankbackup ist leer.\n' >&2; exit 1; }
gzip -t "$backup_temp"
mv "$backup_temp" "$BACKUP_PATH"
printf 'Backup: %s (%s Bytes)\n' "$BACKUP_PATH" "$(stat -c %s "$BACKUP_PATH")"

step 4 'Images bauen'
"${COMPOSE[@]}" build web worker
step 5 'Web aktualisieren'
"${COMPOSE[@]}" up -d --no-deps --force-recreate web
wait_for_web
step 6 'Schema migrieren'
migrate_schema
read -r SCHEMA_VERSION EXPECTED_SCHEMA_VERSION < <(schema_versions)
printf 'Schema: %s (erwartet: %s)\n' "$SCHEMA_VERSION" "$EXPECTED_SCHEMA_VERSION"
[[ "$SCHEMA_VERSION" == "$EXPECTED_SCHEMA_VERSION" ]] || { printf 'Die Schemaversion stimmt nicht mit der Anwendung überein.\n' >&2; exit 1; }
step 7 'Worker aktualisieren'
WORKER_STARTED_EPOCH=$(date -u +%s)
"${COMPOSE[@]}" --profile worker up -d --no-deps --force-recreate worker
WORKER_HEARTBEAT=$(wait_for_worker "$WORKER_STARTED_EPOCH")
printf 'Worker-Heartbeat: %s\n' "$WORKER_HEARTBEAT"
step 8 'Abschlussprüfung'
"${COMPOSE[@]}" --profile worker ps
require_healthy_db
wait_for_web
read -r FINAL_SCHEMA EXPECTED_FINAL_SCHEMA < <(schema_versions)
[[ "$FINAL_SCHEMA" == "$EXPECTED_FINAL_SCHEMA" ]] || { printf 'Die finale Schemaversion ist ungültig.\n' >&2; exit 1; }
FINAL_COMMIT=$(git rev-parse HEAD)
printf '\n================================\nCrossChAPP Update erfolgreich\nVersion: %s\nCommit: %s\nWeb: healthy\nDB: healthy\nSchema: %s\nWorker: active (%s)\nBackup: %s\n================================\n' "$TARGET_REF" "$FINAL_COMMIT" "$FINAL_SCHEMA" "$WORKER_HEARTBEAT" "$BACKUP_PATH"
