#!/usr/bin/env bash
set -euo pipefail

COMPOSE="docker compose -f docker-compose.dev.yml"

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
