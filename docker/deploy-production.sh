#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
if [ ! -f .env.production ]; then
    echo 'Create .env.production from .env.production.example first.' >&2
    exit 1
fi
compose() {
    docker compose --env-file .env.production -f docker-compose.production.yml "$@"
}
# Validation deliberately does not print interpolated credentials.
compose config --quiet
compose build app edge
compose up -d mysql redis
# A failed migration stops this script before replacing the running application.
compose run --rm migrate
# Refuse to open the edge until required production/operator settings are complete.
compose run --rm app php artisan nepse:release-check --configuration-only
compose up -d app queue scheduler edge
printf '%s\n' 'Containers started. Verify HTTPS /up, mail delivery, and release readiness before sharing access.'
