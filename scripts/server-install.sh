#!/usr/bin/env bash
# Server-side half of a deploy on a CyberPanel (OpenLiteSpeed + lsphp) host.
# Run as root from the app checkout, after the source has been updated:
#
#   APP_DIR=/home/example.com/app SITE_USER=siteuser scripts/server-install.sh
#
# Steps: Composer install, optional frontend build, maintenance mode, migrate,
# publish the SPA into backend/public, cache config, leave maintenance mode,
# restart Horizon. Never creates or modifies backend/.env.
#
#   APP_DIR          app checkout                                        required
#   SITE_USER        CyberPanel site user that owns APP_DIR              required
#   PHP_BIN          default /usr/local/lsws/lsphp84/bin/php (PHP >= 8.4.1)
#   PHP_INI_EXTRA    optional dir of extra .ini files (e.g. memory_limit=512M)
#   HORIZON_SERVICE  systemd unit running `artisan horizon`, default stock-horizon
#   BUILD_FRONTEND   1 = run `npm ci && npm run build` here (pull deploys);
#                    0 = frontend/dist was built elsewhere and synced (default)
set -euo pipefail

: "${APP_DIR:?set APP_DIR}"
: "${SITE_USER:?set SITE_USER}"
PHP_BIN="${PHP_BIN:-/usr/local/lsws/lsphp84/bin/php}"
PHP_INI_EXTRA="${PHP_INI_EXTRA:-}"
HORIZON_SERVICE="${HORIZON_SERVICE:-stock-horizon}"
BUILD_FRONTEND="${BUILD_FRONTEND:-0}"
SITE_HOME="$(dirname "$APP_DIR")"

run() {
  local env_args=(HOME="$SITE_HOME")
  [ -n "$PHP_INI_EXTRA" ] && env_args+=(PHP_INI_SCAN_DIR=":$PHP_INI_EXTRA")
  sudo -u "$SITE_USER" env "${env_args[@]}" "$@"
}
artisan() { (cd "$APP_DIR/backend" && run "$PHP_BIN" artisan "$@"); }

echo "--> composer install"
(cd "$APP_DIR/backend" && run "$PHP_BIN" /usr/bin/composer install --no-dev --optimize-autoloader --no-interaction --no-progress)

if [ "$BUILD_FRONTEND" = "1" ]; then
  echo "--> frontend build"
  (cd "$APP_DIR/frontend" && run npm ci --no-audit --no-fund && run npm run build)
fi
[ -f "$APP_DIR/frontend/dist/index.html" ] || { echo "frontend/dist is missing" >&2; exit 1; }

echo "--> migrate"
artisan down --retry=30 || true
# Leave maintenance mode even if a later step fails; a failed deploy is rolled
# back by the caller, and a site stuck in 503 is worse than either version.
trap 'artisan up >/dev/null 2>&1 || true' EXIT
artisan migrate --force --no-interaction

echo "--> publish SPA"
PUB="$APP_DIR/backend/public"
run rsync -a --delete-after "$APP_DIR/frontend/dist/assets/" "$PUB/assets/"
run rsync -a --exclude index.html --exclude assets "$APP_DIR/frontend/dist/" "$PUB/"
run cp "$APP_DIR/frontend/dist/index.html" "$PUB/spa.html"

# Route client-side paths to the SPA (idempotent; the committed .htaccess stays stock Laravel).
if ! grep -q 'spa.html' "$PUB/.htaccess"; then
  run python3 -I - "$PUB/.htaccess" <<'PY'
import sys
p = sys.argv[1]
s = open(p).read()
marker = "    # Send Requests To Front Controller..."
spa = (
    "    # React SPA: everything that is not the API, Horizon, a health check or a real file\n"
    "    # is a client-side route and gets the built index page.\n"
    "    RewriteRule ^$ spa.html [L]\n"
    "    RewriteCond %{REQUEST_URI} !^/(api|sanctum|horizon|up|storage|vendor)(/|$)\n"
    "    RewriteCond %{REQUEST_FILENAME} !-f\n"
    "    RewriteRule ^ spa.html [L]\n\n"
)
if marker not in s:
    sys.exit("front-controller marker not found in .htaccess")
open(p, "w").write(s.replace(marker, spa + marker))
PY
fi

echo "--> cache and restart"
artisan optimize
artisan up
trap - EXIT
systemctl restart "$HORIZON_SERVICE"
sleep 3
systemctl is-active --quiet "$HORIZON_SERVICE"
echo "--> install complete"
