#!/usr/bin/env bash
set -euo pipefail

if [ -n "${COMPOSE_FILE:-}" ]; then
    COMPOSE_FILE_PATH="$COMPOSE_FILE"
elif [ -f docker-compose.prod.yml ]; then
    COMPOSE_FILE_PATH="docker-compose.prod.yml"
elif [ -f docker-compose.dev.yml ]; then
    COMPOSE_FILE_PATH="docker-compose.dev.yml"
else
    echo "Error: no docker-compose.prod.yml or docker-compose.dev.yml found" >&2
    exit 1
fi
COMPOSE="docker compose -f $COMPOSE_FILE_PATH"

echo "==> Pulling latest code..."
git pull

echo "==> Applying compose patches..."
if command -v yq &>/dev/null; then
    for patch in compose-patches/*.sh; do
        [ -f "$patch" ] || continue
        echo "  $(basename "$patch")"
        COMPOSE_FILE_PATH="$COMPOSE_FILE_PATH" bash "$patch"
    done
else
    echo "  WARNING: yq not installed — compose patches skipped."
    echo "  Structural compose file changes will not be applied automatically."
    echo "  Install yq to enable patching:"
    echo "    sudo wget -qO /usr/local/bin/yq https://github.com/mikefarah/yq/releases/latest/download/yq_linux_amd64"
    echo "    sudo chmod +x /usr/local/bin/yq"
fi

echo "==> Rebuilding and restarting containers..."
$COMPOSE up -d --build

echo "==> Waiting for app container to be ready..."
until $COMPOSE exec -T app php -r 'echo "ok";' 2>/dev/null | grep -q ok; do
    sleep 2
done

echo "==> Running migrations..."
$COMPOSE exec -T app php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "==> Setting up messenger transports..."
$COMPOSE exec -T app php bin/console messenger:setup-transports --no-interaction

echo "==> Clearing cache..."
$COMPOSE exec -T app php bin/console cache:clear

echo "==> Done."
