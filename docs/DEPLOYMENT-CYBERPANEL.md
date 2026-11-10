# Deploying on CyberPanel (no Docker)

A native alternative to the container setup in [DEPLOYMENT.md](DEPLOYMENT.md), for a
host already running CyberPanel (OpenLiteSpeed + lsphp, MariaDB/MySQL). The app is
served from one domain: the React build and `/api` share an origin, so no CORS or API
URL configuration is needed.

## Requirements

- **PHP 8.4.1+** (`lsphp84`) — the locked Symfony packages require it, even though
  `composer.json` says `^8.2`. Extensions: `pdo_mysql`, `redis`, `intl`, `bcmath`,
  `mbstring`, `zip`, `gd`, `pcntl`, `posix`, `xml`. On Ubuntu, `lsphp84-redis` is a
  separate package.
- MariaDB 10.3+ or MySQL 8, Redis 5+, Composer, Node 20+ (build machine only).

## One-time server setup

1. **Website.** Create the site in CyberPanel, then point the vhost `docRoot` at
   `<app>/backend/public` (not `public_html`). Issue an SSL certificate. Behind
   Cloudflare with "Full (strict)", the origin needs a real certificate — a
   self-signed one returns 526.
2. **Redis.** Install it bound to `127.0.0.1` with `requirepass` set. Other site users
   on a shared host can reach localhost ports, so always use a password.
3. **Database.** Create a dedicated database and user; never reuse the CyberPanel root.
4. **Environment.** Copy `backend/.env.example` to `backend/.env` (mode 600, owned by
   the site user) and set at least:

   ```dotenv
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://your.domain
   FRONTEND_URL=https://your.domain
   SANCTUM_STATEFUL_DOMAINS=your.domain
   SESSION_DOMAIN=your.domain
   SESSION_SECURE_COOKIE=true
   DB_DATABASE=… DB_USERNAME=… DB_PASSWORD=…
   REDIS_PASSWORD=…
   TRUSTED_PROXIES=<Cloudflare ranges from cloudflare.com/ips, comma-separated>
   ```

   Then `php artisan key:generate`. Without `TRUSTED_PROXIES` behind a CDN, every
   visitor appears as the CDN's IP and shares one rate-limit bucket. Never use `*`.
5. **Queue workers.** Run Horizon as a systemd unit owned by the site user
   (`ExecStart=<php> <app>/backend/artisan horizon`, `Restart=always`,
   `TimeoutStopSec` above the longest job timeout). CyberPanel's Python can break
   the distribution `supervisor` package; systemd avoids that dependency.
6. **Scheduler.** In the site user's crontab:
   `* * * * * cd <app>/backend && <php> artisan schedule:run >> /dev/null 2>&1`
   Horizon does not run the scheduler.
7. **Memory.** lsphp's CLI default is often 128M, below the Horizon workers' 256M
   budget. Put `memory_limit=512M` in an `.ini` file in a private directory and set
   `PHP_INI_SCAN_DIR=:<that dir>` for the Horizon unit and the cron line. The leading
   colon keeps PHP's default scan directory, so extensions still load.

## Deploying

`scripts/deploy-cyberpanel.sh` builds the frontend, syncs the source, installs
production dependencies, migrates, publishes the SPA into `backend/public`
(`index.html` becomes `spa.html`, plus an idempotent `.htaccess` fallback), and
restarts Horizon. It never touches `backend/.env` or `storage/`.

```bash
DEPLOY_HOST=myserver DEPLOY_PATH=/home/your.domain/app DEPLOY_USER=siteuser \
PHP_INI_EXTRA=/home/your.domain/.php-cli scripts/deploy-cyberpanel.sh
```

## Closing registration

There is no registration toggle in the application. To run a private instance,
deny the endpoint in the vhost rewrite rules:

```apache
RewriteRule ^/?api/register$ - [F,L]
```

## Checks after deploying

`/`, a client route such as `/portfolio`, `/api/market/summary` and `/up` should
return 200; `/.env` must return 403. Then check
`php artisan horizon:status` and `php artisan schedule:list`.
