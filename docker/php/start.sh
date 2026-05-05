#!/bin/sh
set -e

cd /var/www/html

mkdir -p storage/logs bootstrap/cache
touch storage/logs/laravel.log

chown -R www-data:www-data storage bootstrap/cache >/dev/null 2>&1 || true
chmod -R ug+rwX storage bootstrap/cache >/dev/null 2>&1 || true

if [ "$#" -gt 0 ]; then
    exec "$@"
fi

exec php-fpm
