#!/usr/bin/env bash
set -euo pipefail
umask 077

if [[ $# -ne 3 || "$2" != '--confirm-database' ]]; then
  echo 'Usage: restore-database.sh backup.sql.gz --confirm-database exact_target_database' >&2
  exit 1
fi
: "${MYSQL_DEFAULTS_FILE:?Set MYSQL_DEFAULTS_FILE to a private MySQL client option file}"
: "${DB_DATABASE:?Set DB_DATABASE to the exact restore target}"
[[ "$DB_DATABASE" =~ ^[a-zA-Z0-9_]+$ ]] || { echo 'Invalid target database name.' >&2; exit 1; }
[[ "$3" == "$DB_DATABASE" ]] || { echo 'Confirmation must exactly match DB_DATABASE.' >&2; exit 1; }
[[ -r "$MYSQL_DEFAULTS_FILE" && -f "$1" && -f "$1.sha256" ]] || { echo 'Require readable credentials, backup, and its .sha256 sidecar.' >&2; exit 1; }
command -v mysql >/dev/null || { echo 'Install the MySQL client tools first.' >&2; exit 1; }
backup_path="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"
if command -v sha256sum >/dev/null; then
  (cd "$(dirname "$backup_path")" && sha256sum -c "$(basename "$backup_path").sha256")
else
  (cd "$(dirname "$backup_path")" && shasum -a 256 -c "$(basename "$backup_path").sha256")
fi
gzip -t "$backup_path"
printf 'Restoring into %s. Existing tables may be replaced.\n' "$DB_DATABASE"
gzip -dc "$backup_path" | mysql --defaults-extra-file="$MYSQL_DEFAULTS_FILE" "$DB_DATABASE"
printf 'Restore finished. Validate accounts, ledger totals, migrations, and application access before reopening traffic.\n'
