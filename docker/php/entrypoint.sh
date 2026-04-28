#!/bin/sh
set -e

# Copy the SSH key to a www-data-accessible location so the web process can use scp.
# The bind-mounted key at /root/.ssh/ is only readable by root; this runs before
# php-fpm drops privileges, so we can copy it here.
if [ -f /root/.ssh/id_ed25519 ]; then
    mkdir -p /var/www/.ssh
    cp /root/.ssh/id_ed25519 /var/www/.ssh/id_ed25519
    chown www-data:www-data /var/www/.ssh/id_ed25519
    chmod 600 /var/www/.ssh/id_ed25519
fi

exec docker-php-entrypoint "$@"
