#!/bin/sh
set -e

# Ensure Symfony var subdirectories exist and are writable by www-data
for dir in /var/www/html/var/cache /var/www/html/var/log; do
    mkdir -p "$dir"
    chown -R www-data:www-data "$dir"
done

exec docker-php-entrypoint "$@"
