#!/usr/bin/env bash
# Deploy to a CyberPanel (OpenLiteSpeed + lsphp) host over SSH, without Docker.
#
# Manual deploy: builds the React app locally, syncs the working tree, then runs
# scripts/server-install.sh on the server. For automatic deploys from the develop
# branch, see scripts/deploy-pull.sh. Do not mix the two on one server: this
# script overwrites the checkout that the pull deploy manages with git.
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
ssh "$DEPLOY_HOST" "APP_DIR='$DEPLOY_PATH' SITE_USER='$DEPLOY_USER' PHP_BIN='$PHP_BIN' PHP_INI_EXTRA='$PHP_INI_EXTRA' HORIZON_SERVICE='$HORIZON_SERVICE' BUILD_FRONTEND=0 bash '$DEPLOY_PATH/scripts/server-install.sh'"

echo "==> Done"
