#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd);cd "$ROOT"
printf '%s\n' 'ACHTUNG: Dieser Test führt reale Requests an öffentliche BNI-Endpunkte aus.'
printf '%s\n' 'Genau ein kontrollierter, chapterbegrenzter Membercheck; keine Parallelisierung und keine Wiederholung.'
export CROSSCHAPP_ALLOW_LIVE_BNI=1
docker compose exec -T -e CROSSCHAPP_ALLOW_LIVE_BNI=1 web php < tests/live-bni-member-check.php
printf '%s\n' 'RESULT: PASS'
