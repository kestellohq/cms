#!/bin/sh
set -e

# Ensure writable directories exist and have correct permissions
mkdir -p /var/www/html/config \
         /var/www/html/storage/cache \
         /var/www/html/storage/logs \
         /var/www/html/storage/sessions \
         /var/www/html/storage/compiled

chown -R www-data:www-data /var/www/html/config \
                           /var/www/html/storage

chmod -R 775 /var/www/html/config \
             /var/www/html/storage

exec "$@"
