#!/bin/sh
set -eu
if [ "${1:-}" = "initialize" ]; then
    cp -a /opt/dependencies/node_modules/. /app/node_modules/
    npx --no-install nuxt prepare
    exit 0
fi
exec "$@"
