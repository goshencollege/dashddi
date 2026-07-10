#!/usr/bin/env bash
# Give each worker its own failure transport queue.
set -euo pipefail

COMPOSE_FILE_PATH="${COMPOSE_FILE_PATH:?}"

changed=0

# worker_priority: failed -> failed_priority
if ! yq '.services.worker_priority.command | contains(["failed_priority"])' "$COMPOSE_FILE_PATH" | grep -q '^true$'; then
    yq -i '.services.worker_priority.command = ["php", "bin/console", "messenger:consume", "async_priority", "failed_priority", "--time-limit=3600"]' "$COMPOSE_FILE_PATH"
    echo "    [done] worker_priority: updated to use failed_priority transport"
    changed=1
fi

# worker_bulk: add failed_bulk
if ! yq '.services.worker_bulk.command | contains(["failed_bulk"])' "$COMPOSE_FILE_PATH" | grep -q '^true$'; then
    yq -i '.services.worker_bulk.command += ["failed_bulk"]' "$COMPOSE_FILE_PATH"
    echo "    [done] worker_bulk: added failed_bulk transport"
    changed=1
fi

[ "$changed" -eq 0 ] && echo "    [skip] worker failure transports already configured"
