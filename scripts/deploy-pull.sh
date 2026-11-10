#!/usr/bin/env bash
# Continuous deployment by pull. Run on the server by a systemd timer (as root).
#
# Each run: fetch the production branch; if it moved, wait until the GitHub
# Actions check "CI passed" succeeded for that exact commit, back up the
# database, check out the commit, run scripts/server-install.sh and verify the
# site answers. If anything fails, the previous commit is restored and
# reinstalled. Nothing about the server is stored in GitHub — no SSH keys,
# no deploy secrets — because the server only reads a public repository.
#
#   APP_DIR          git checkout of the app                             required
#   SITE_USER        CyberPanel site user that owns APP_DIR              required
#   GITHUB_REPO      owner/name, e.g. someone/nepse-analyzer             required
#   HEALTH_URL       URL that must return 200 after deploying            required
#   DEPLOY_BRANCH    default main
#   CHECK_NAME       required GitHub check, default "CI passed"
#   STATE_DIR        default /var/lib/stock-deploy
#   BACKUP_DIR       database dumps before each deploy, default $STATE_DIR/backups
#   KEEP_BACKUPS     default 10
#   plus PHP_BIN / PHP_INI_EXTRA / HORIZON_SERVICE for server-install.sh
set -euo pipefail

: "${APP_DIR:?set APP_DIR}"
: "${SITE_USER:?set SITE_USER}"
: "${GITHUB_REPO:?set GITHUB_REPO}"
: "${HEALTH_URL:?set HEALTH_URL}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-main}"
CHECK_NAME="${CHECK_NAME:-CI passed}"
STATE_DIR="${STATE_DIR:-/var/lib/stock-deploy}"
BACKUP_DIR="${BACKUP_DIR:-$STATE_DIR/backups}"
KEEP_BACKUPS="${KEEP_BACKUPS:-10}"
export APP_DIR SITE_USER

log() { echo "[$(date '+%F %T')] $*"; }
git_app() { sudo -u "$SITE_USER" git -C "$APP_DIR" "$@"; }

install -d -m 700 "$STATE_DIR" "$BACKUP_DIR"

# Bash reads a script while running it, and the checkout below may rewrite this
# file. Run from a private copy so a deploy never executes a half-old script.
if [ "${DEPLOY_PULL_COPY:-0}" != 1 ]; then
  cp "$0" "$STATE_DIR/deploy-pull.run.sh"
  DEPLOY_PULL_COPY=1 exec bash "$STATE_DIR/deploy-pull.run.sh" "$@"
fi
exec 9>"$STATE_DIR/lock"
flock -n 9 || { log "another deploy is running"; exit 0; }

git_app fetch --quiet origin "$DEPLOY_BRANCH"
current="$(git_app rev-parse HEAD)"
target="$(git_app rev-parse "origin/$DEPLOY_BRANCH")"
[ "$current" = "$target" ] && exit 0

if [ -f "$STATE_DIR/failed_sha" ] && [ "$(cat "$STATE_DIR/failed_sha")" = "$target" ]; then
  exit 0 # already tried and rolled back; wait for a new commit
fi

# Gate on CI for this exact commit (public API, no token needed for a public repo).
check="$(curl -fsS --max-time 20 -H 'Accept: application/vnd.github+json' \
  "https://api.github.com/repos/$GITHUB_REPO/commits/$target/check-runs?check_name=$(python3 -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1]))' "$CHECK_NAME")" \
  | python3 -c '
import json, sys
runs = json.load(sys.stdin).get("check_runs", [])
if not runs: print("missing"); sys.exit()
run = max(runs, key=lambda r: r.get("started_at") or "")
print(run["conclusion"] or run["status"])')"

case "$check" in
  success) ;;
  missing|queued|in_progress|pending|waiting|requested) log "waiting for CI on ${target:0:7} ($check)"; exit 0 ;;
  *) log "CI did not pass for ${target:0:7} ($check); not deploying"; echo "$target" > "$STATE_DIR/failed_sha"; exit 1 ;;
esac

log "deploying ${current:0:7} -> ${target:0:7}"

# Database backup before migrations, so a bad migration is recoverable.
envf="$APP_DIR/backend/.env"
val() { grep -E "^$1=" "$envf" | tail -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/'; }
dump="$BACKUP_DIR/$(date +%Y%m%d-%H%M%S)-${current:0:7}.sql.gz"
MYSQL_PWD="$(val DB_PASSWORD)" mysqldump --single-transaction --quick --no-tablespaces \
  -h "$(val DB_HOST)" -P "$(val DB_PORT)" -u "$(val DB_USERNAME)" "$(val DB_DATABASE)" | gzip > "$dump"
chmod 600 "$dump"
ls -1t "$BACKUP_DIR"/*.sql.gz 2>/dev/null | tail -n +"$((KEEP_BACKUPS + 1))" | xargs -r rm -f
log "database backed up to $dump"

# Explicit `|| return 1`: errexit is suspended inside a function called from `if`.
deploy_to() {
  git_app checkout --quiet --force "$1" || return 1
  BUILD_FRONTEND=1 bash "$APP_DIR/scripts/server-install.sh" || return 1
  local code
  for _ in 1 2 3 4 5 6; do
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$HEALTH_URL" || true)"
    [ "$code" = "200" ] && return 0
    sleep 5
  done
  log "health check failed ($HEALTH_URL -> $code)"
  return 1
}

if deploy_to "$target"; then
  rm -f "$STATE_DIR/failed_sha"
  log "deployed ${target:0:7}"
else
  log "rolling back to ${current:0:7} (database backup: $dump — migrations are NOT reverted automatically)"
  echo "$target" > "$STATE_DIR/failed_sha"
  deploy_to "$current" && log "rollback complete" || log "ROLLBACK FAILED — manual attention needed"
  exit 1
fi
