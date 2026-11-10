#!/bin/sh
set -eu
# Runtime credentials are intentionally absent from image build layers.
if [ "${APP_ENV:-}" != production ] || [ "${APP_DEBUG:-false}" != false ]; then
    echo 'Production containers require APP_ENV=production and APP_DEBUG=false.' >&2
    exit 1
fi
if [ -z "${APP_KEY:-}" ]; then
    echo 'APP_KEY must be supplied at runtime.' >&2
    exit 1
fi
php artisan config:cache --quiet
php artisan view:cache --quiet
exec "$@"
