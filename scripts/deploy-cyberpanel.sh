#!/usr/bin/env bash
# Deploy to a CyberPanel (OpenLiteSpeed + lsphp) host over SSH, without Docker.
#
# Builds the React app locally, syncs the source, then on the server: installs
# production Composer dependencies, migrates, caches config, publishes the SPA
# into backend/public, and restarts the Horizon service.
#
# Nothing host-specific lives in this file. Configure it through the environment:
#
#   DEPLOY_HOST      ssh destination (an ~/.ssh/config alias works)        required
#   DEPLOY_PATH      app checkout on the server, e.g. /home/example.com/app  required
#   DEPLOY_USER      the CyberPanel site user that owns DEPLOY_PATH          required
#   PHP_BIN          default /usr/local/lsws/lsphp84/bin/php (PHP >= 8.4.1)
#   PHP_INI_EXTRA    optional dir with extra .ini (e.g. memory_limit=512M), appended
#                    to PHP's scan dir for CLI runs
#   HORIZON_SERVICE  systemd unit running `artisan horizon`, default stock-horizon
#
# One-time server setup (vhost docRoot = DEPLOY_PATH/backend/public, Redis,
# the Horizon systemd unit, the scheduler cron, backend/.env) is described in
# docs/DEPLOYMENT.md. This script never creates or overwrites backend/.env.
set -euo pipefail

: "${DEPLOY_HOST:?set DEPLOY_HOST}"
: "${DEPLOY_PATH:?set DEPLOY_PATH}"
: "${DEPLOY_USER:?set DEPLOY_USER}"
PHP_BIN="${PHP_BIN:-/usr/local/lsws/lsphp84/bin/php}"
PHP_INI_EXTRA="${PHP_INI_EXTRA:-}"
HORIZON_SERVICE="${HORIZON_SERVICE:-stock-horizon}"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

echo "==> Building frontend"
(cd "$ROOT/frontend" && npm ci --no-audit --no-fund && npm run build)

echo "==> Syncing source to $DEPLOY_HOST:$DEPLOY_PATH"
# Rules are evaluated in order; protect server-only files before excluding anything.
rsync -az --delete \
  --rsync-path="sudo -u $DEPLOY_USER rsync" \
  --filter='P backend/.env' \
  --filter='P backend/storage/***' \
  --filter='P backend/vendor/***' \
  --filter='P backend/bootstrap/cache/*.php' \
  --filter='P backend/public/spa.html' \
  --filter='P backend/public/assets/***' \
  --include='backend/.env.example' \
  --exclude='.git/' --exclude='.idea/' --exclude='.claude/' --exclude='.codex/' \
  --exclude='.DS_Store' --exclude='/CLAUDE.md' \
  --exclude='/*.csv' --exclude='/*.xlsx' --exclude='/*.xls' --exclude='/*.pdf' \
  --exclude='.env' --exclude='.env.*' \
  --exclude='backend/vendor/' --exclude='backend/node_modules/' --exclude='backend/storage/' \
  --exclude='backend/bootstrap/cache/*.php' --exclude='backend/backup-pre-laravel12/' \
  --exclude='frontend/node_modules/' --exclude='frontend/test-results/' \
  --exclude='frontend/playwright-report/' \
  "$ROOT/" "$DEPLOY_HOST:$DEPLOY_PATH/"

echo "==> Server-side install"
# shellcheck disable=SC2087 # variables are intentionally expanded locally
ssh "$DEPLOY_HOST" bash -s <<EOF
set -euo pipefail
cd "$DEPLOY_PATH/backend"
run() { sudo -u "$DEPLOY_USER" env HOME="\$(dirname "$DEPLOY_PATH")" ${PHP_INI_EXTRA:+PHP_INI_SCAN_DIR=:$PHP_INI_EXTRA} "\$@"; }

run "$PHP_BIN" /usr/bin/composer install --no-dev --optimize-autoloader --no-interaction --no-progress
run "$PHP_BIN" artisan down --retry=30 || true
run "$PHP_BIN" artisan migrate --force --no-interaction

# Publish the SPA next to Laravel's front controller.
run rsync -a --delete-after --exclude index.html "$DEPLOY_PATH/frontend/dist/assets/" public/assets/
run rsync -a --exclude index.html --exclude assets "$DEPLOY_PATH/frontend/dist/" public/
run cp "$DEPLOY_PATH/frontend/dist/index.html" public/spa.html

# Route client-side paths to the SPA (idempotent; the repo copy of .htaccess stays stock Laravel).
if ! grep -q 'spa.html' public/.htaccess; then
  run python3 -I - <<'PY'
p = "public/.htaccess"
s = open(p).read()
marker = "    # Send Requests To Front Controller..."
spa = (
    "    # React SPA: everything that is not the API, Horizon, a health check or a real file\n"
    "    # is a client-side route and gets the built index page.\n"
    "    RewriteRule ^\$ spa.html [L]\n"
    "    RewriteCond %{REQUEST_URI} !^/(api|sanctum|horizon|up|storage|vendor)(/|\$)\n"
    "    RewriteCond %{REQUEST_FILENAME} !-f\n"
    "    RewriteRule ^ spa.html [L]\n\n"
)
open(p, "w").write(s.replace(marker, spa + marker))
PY
fi

run "$PHP_BIN" artisan optimize
run "$PHP_BIN" artisan up
systemctl restart "$HORIZON_SERVICE"
sleep 3
systemctl is-active "$HORIZON_SERVICE"
EOF

echo "==> Done"
