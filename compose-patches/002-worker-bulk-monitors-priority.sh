#!/usr/bin/env bash
# worker_bulk also monitors async_priority when idle so fast messages are
# never blocked if worker_priority is busy.
set -euo pipefail

COMPOSE_FILE_PATH="${COMPOSE_FILE_PATH:?}"
# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

if yq '.services.worker_bulk.command | contains(["async_priority"])' "$COMPOSE_FILE_PATH" | grep -q '^true$'; then
    echo "    [skip] worker_bulk already monitors async_priority"
    exit 0
fi

yq_edit '.services.worker_bulk.command = ["php", "bin/console", "messenger:consume", "async_priority", "async_bulk", "failed_bulk", "--time-limit=3600"]' "$COMPOSE_FILE_PATH"
echo "    [done] worker_bulk: added async_priority to queue list"
