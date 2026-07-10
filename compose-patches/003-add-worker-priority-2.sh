#!/usr/bin/env bash
# Add a second priority worker so sequential DHCP pushes don't block DNS
# and ClearPass messages while they run.
set -euo pipefail

COMPOSE_FILE_PATH="${COMPOSE_FILE_PATH:?}"

if yq '.services | has("worker_priority_2")' "$COMPOSE_FILE_PATH" | grep -q '^true$'; then
    echo "    [skip] worker_priority_2 already present"
    exit 0
fi

yq -i '.services.worker_priority_2 = .services.worker_priority' "$COMPOSE_FILE_PATH"
echo "    [done] Added worker_priority_2 service"
