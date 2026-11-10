#!/usr/bin/env bash
set -euo pipefail
umask 077

# Credentials belong in a private MySQL option file, never process arguments.
: "${MYSQL_DEFAULTS_FILE:?Set MYSQL_DEFAULTS_FILE to a private MySQL client option file}"
: "${DB_DATABASE:?Set DB_DATABASE to the database to back up}"
backup_dir="${BACKUP_DIR:-./backups}"
[[ "$DB_DATABASE" =~ ^[a-zA-Z0-9_]+$ ]] || { echo 'Database name must contain only letters, numbers, and underscores.' >&2; exit 1; }
[[ -r "$MYSQL_DEFAULTS_FILE" ]] || { echo 'MySQL credentials file is not readable.' >&2; exit 1; }
command -v mysqldump >/dev/null || { echo 'Install the MySQL client tools or run this inside a matching client container.' >&2; exit 1; }
mkdir -p "$backup_dir"
backup_file="$backup_dir/${DB_DATABASE}-$(date -u +%Y%m%dT%H%M%SZ)-$$.sql.gz"
partial_file="$backup_file.partial"
trap 'rm -f "$partial_file"' EXIT
mysqldump --defaults-extra-file="$MYSQL_DEFAULTS_FILE" --single-transaction --quick --routines --events --triggers --no-tablespaces --hex-blob --set-gtid-purged=OFF "$DB_DATABASE" | gzip > "$partial_file"
gzip -t "$partial_file"
mv "$partial_file" "$backup_file"
if command -v sha256sum >/dev/null; then
  (cd "$backup_dir" && sha256sum "$(basename "$backup_file")" > "$(basename "$backup_file").sha256")
else
  (cd "$backup_dir" && shasum -a 256 "$(basename "$backup_file")" > "$(basename "$backup_file").sha256")
fi
printf 'Backup created: %s\n' "$backup_file"
printf 'Copy this encrypted to independent storage and test restoration to a separate database.\n'
