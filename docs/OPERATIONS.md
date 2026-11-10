# Operating the public service

Run backend commands from `backend/` or inside the production application container.
These commands inspect configuration and local dependencies. They do not send mail,
call market sources or AI providers, or assert that a legal policy is approved.

## Release preflight

```bash
php artisan nepse:release-check --configuration-only
php artisan nepse:release-check --json
```

Both return a nonzero exit code when a required check fails. The first can run
before services start. The full check also requires database/cache access, recent
market data, and fresh scheduler/worker heartbeats. Fill production operator,
contact, HTTPS policy URLs, and market-data permission fields deliberately. A
configured provider must have a positive daily limit; zero disables its budget.
No credential values are printed. A configured mail transport still needs a real
staging delivery test before password recovery is considered operational.

## Monitoring

`/up` is process liveness only. Monitor `php artisan nepse:health --json` separately;
its exit status becomes nonzero when a dependency, scheduler, worker pool, or
market data check fails. Record the JSON in private monitoring, not a public log.
Administrator `/api/health` includes these observations; public responses omit them.

The scheduler records a timestamp every minute and enqueues one probe for each
worker pool. A busy pool delays its own probe; the result says delayed rather
than claiming the process is dead. Defaults allow 180 seconds for the scheduler,
4500 for the long-running pipeline pool, and 600 for the AI pool. Configure
`SCHEDULER_HEARTBEAT_MAX_AGE`, `PIPELINE_HEARTBEAT_MAX_AGE`, and
`AI_HEARTBEAT_MAX_AGE` to match the actual workload. Keep all processes on the same
shared Redis cache and namespace. Allow a minute after starting services for the
first observations. Alert on failures and sustained queue backlog; do not restart
workers blindly while long crawls are active. Check exchange closures before
interpreting a market freshness alert as an ingestion failure.

## Backup and restore

The scripts require MySQL client binaries matching the server and a private
option file (mode `0600`) containing `[client]` connection credentials. Supply
`MYSQL_DEFAULTS_FILE`, `DB_DATABASE`, and optionally `BACKUP_DIR` as environment
variables. Do not put the password in command arguments or commit the option file.

```bash
scripts/backup-database.sh
scripts/restore-database.sh /private/backups/backup.sql.gz --confirm-database separate_restore_database
```

The backup uses a consistent transaction, includes triggers/routines/events,
compresses to a private file, and writes a SHA-256 sidecar. A failed dump never
becomes a completed backup. It does not encrypt the file: transfer it into encrypted
storage with separately managed access. Schedule backup execution in the chosen
hosting environment and define retention based on the approved privacy policy.
Do not store backups in web roots. `backups/` is ignored by Git.

Restore requires the checksum sidecar and exact database confirmation because
existing tables may be replaced. Always rehearse on an empty, separate database
with an isolated application instance and separate Redis namespace. Verify account
access, portfolio/ledger totals, migrations, and a representative import. Record the
time taken and test again after schema upgrades. Preserve the production `APP_KEY`
in a secure secret store; losing it can make encrypted values unrecoverable.

These scripts were validated with fake client binaries and file checks. A real
MySQL restoration and off-host storage remain deployment-environment tests.
