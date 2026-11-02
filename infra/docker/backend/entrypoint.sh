#!/bin/sh
set -eu
if [ "${1:-}" = "initialize" ]; then
    cp -a /opt/dependencies/vendor/. /app/vendor/
    mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
    php artisan package:discover --ansi
    php artisan migrate --force --no-interaction
    exit 0
fi
exec "$@"
