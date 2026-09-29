#!/usr/bin/env bash
# Enable IPv6 on the default docker network. Without this, the network has no
# IPv6 subnet, so any client connecting over IPv6 gets NATed through Docker's
# userland proxy and loses its real source address before nginx/the app ever
# see it — this breaks host-scoped API token IP checks (e.g. ACME DNS-01
# publishing) for any host reaching DashDDI over IPv6.
set -euo pipefail

COMPOSE_FILE_PATH="${COMPOSE_FILE_PATH:?}"
# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

if yq '.networks.default.enable_ipv6' "$COMPOSE_FILE_PATH" | grep -q '^true$'; then
    echo "    [skip] IPv6 already enabled on the default network"
    exit 0
fi

yq_edit '.networks.default.enable_ipv6 = true' "$COMPOSE_FILE_PATH"
yq_edit '.networks.default.ipam.config = [{"subnet": "fd00:dead:beef::/64"}]' "$COMPOSE_FILE_PATH"
echo "    [done] Enabled IPv6 on the default network (recreate the stack for this to take effect: docker compose -f \"$COMPOSE_FILE_PATH\" down && docker compose -f \"$COMPOSE_FILE_PATH\" up -d)"
