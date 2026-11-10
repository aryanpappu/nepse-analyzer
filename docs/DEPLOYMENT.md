# Production container deployment

> Hosting on CyberPanel without Docker? See [DEPLOYMENT-CYBERPANEL.md](DEPLOYMENT-CYBERPANEL.md).

This is a separate production stack. `docker-compose.yml` remains the local
Vite/PHP development stack. No deployment has been performed by adding these files.

## Prepare the server

Use Docker with Compose, a domain pointing to the server, and inbound TCP ports
80/443 (UDP 443 is optional HTTP/3). Caddy obtains and renews HTTPS certificates.
The backend, MySQL, and Redis have no published host ports. The application needs
outbound access for mail, market sources, and the selected AI provider.

Copy `.env.production.example` to `.env.production`, restrict it to the deployment
user (`chmod 600 .env.production`), and complete the domain, operator details,
SMTP settings, and unique database/Redis passwords. Generate `APP_KEY` once with
`php backend/artisan key:generate --show`; preserve that key across releases and
backups. Do not put completed credentials into Git or an image build argument.
Configure an actual domain and contact email; the example addresses are not usable.
The production template intentionally fails readiness until these values, policy
URLs, SMTP sender, and data-source authorization are completed.

Set a positive provider daily limit. Zero disables the spend guard. Confirm the
market-data permissions and publish the configured privacy and terms URLs before
opening registration to the public. Account messages require working SMTP.

## Build and launch

From the repository root:

```sh
./docker/deploy-production.sh
```

The script validates configuration without printing interpolated secrets, builds
the PHP and static frontend images, starts private data services, applies migrations,
checks release configuration, and starts the API, workers, scheduler, and HTTPS edge. A failed migration stops
the script before replacing application containers. Review migration compatibility
before upgrading a running service; use a maintenance window for incompatible changes.
Use a unique `RELEASE_TAG` per release if you need to retain prior images.

Before running the deployment script for a first administrator, temporarily configure `ADMIN_EMAIL` and a unique
`ADMIN_PASSWORD`, then run:

```sh
docker compose --env-file .env.production -f docker-compose.production.yml run --build --rm app php artisan db:seed --force
```

Remove the bootstrap password afterward before running the deployment script.
The configuration check deliberately refuses to open access while that bootstrap
password remains configured. Recreate any existing application containers so it
is no longer in their environment. Existing accounts are never promoted or
reset by seeding. Populate market data with `php artisan nepse:setup` using the
same `compose run --rm app` prefix. This may take time and uses public data sources.

The production backend installs no development dependencies and receives secrets
only at runtime. Caddy serves the compiled React files and routes API/operator
paths to FPM. No Vite development server or source bind mount is exposed.

## Verify and operate

Check HTTPS `/up`, sign-up/sign-in, private portfolios with two accounts, password
recovery, verification mail, imports, and the latest market date. Wait for at least one minute of worker/scheduler activity, then run
`php artisan nepse:release-check` using the same `compose run --rm app` prefix
before sharing access. Configuration-only checks run automatically during deploy. Tail service logs with
`docker compose --env-file .env.production -f docker-compose.production.yml logs --tail=100`.
Do not paste logs containing personal records into public issues.

Keep the `mysql-data`, `app-storage`, and Caddy certificate volumes persistent.
Configure encrypted off-host backups, retention, and regular restore drills; a
persistent Docker volume alone is not a backup. Protect the environment file and
`APP_KEY` separately. See [operations](OPERATIONS.md) for backup/restore scripts, monitoring checks,
and heartbeat troubleshooting.

Queue shutdown allows the longest configured pipeline job to finish and can take
about an hour. Schedule upgrades accordingly. Restart all application services
when environment values change; configuration is cached when each container starts.
Monitor HTTPS availability, free disk space, job failures, and market freshness.

## Validation limits

Compose interpolation and local shell syntax can be checked without running the
stack. Image builds, HTTPS issuance, MySQL migration execution, SMTP delivery,
and browser flows still need validation on the chosen server. DNS, credentials,
privacy/legal terms, and data redistribution authorization must be supplied by
the operator. Do not remove production volumes during a routine upgrade.
