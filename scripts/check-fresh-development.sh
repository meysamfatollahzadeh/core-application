#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
check_project=organization-fresh-acceptance
if [ -n "$(docker ps -aq --filter "label=com.docker.compose.project=$check_project")" ] || [ -n "$(docker volume ls -q --filter "label=com.docker.compose.project=$check_project")" ]; then
  echo 'The temporary acceptance project already exists; refusing to replace it.' >&2
  exit 1
fi
restore_development() {
  rm -f .runtime/phase2-acceptance-browser.json
  docker compose -p "$check_project" down --volumes
  docker compose -p organization-dev up -d --wait --wait-timeout 360
}
trap restore_development EXIT
docker compose -p organization-dev stop
COMPOSE_PROJECT_NAME="$check_project" ./scripts/dev up
COMPOSE_PROJECT_NAME="$check_project" ./scripts/dev test
COMPOSE_PROJECT_NAME="$check_project" python3 scripts/smoke-management.py
COMPOSE_PROJECT_NAME="$check_project" python3 scripts/smoke-provider-recovery.py
printf '%s\n' 'PASS: fresh development bootstrap and acceptance checks; restoring existing development data.'
