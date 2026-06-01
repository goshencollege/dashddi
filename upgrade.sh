#!/usr/bin/env bash
set -euo pipefail

if [ -n "${COMPOSE_FILE:-}" ]; then
    COMPOSE="docker compose -f $COMPOSE_FILE"
elif [ -f docker-compose.prod.yml ]; then
    COMPOSE="docker compose -f docker-compose.prod.yml"
elif [ -f docker-compose.dev.yml ]; then
    COMPOSE="docker compose -f docker-compose.dev.yml"
else
    echo "Error: no docker-compose.prod.yml or docker-compose.dev.yml found" >&2
    exit 1
fi

echo "==> Pulling latest code..."
git pull

echo "==> Rebuilding and restarting containers..."
$COMPOSE up -d --build

echo "==> Waiting for app container to be ready..."
until $COMPOSE exec -T app php -r 'echo "ok";' 2>/dev/null | grep -q ok; do
    sleep 2
done

echo "==> Running migrations..."
$COMPOSE exec -T app php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "==> Clearing cache..."
$COMPOSE exec -T app php bin/console cache:clear

echo "==> Done."
