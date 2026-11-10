# Public deployment

Public visitors can browse market data. Registered users manage their own
portfolios. Only administrators can edit the shared stock catalog or request
stock analysis that updates shared signals.

## Prepare a clean release

Publish source from a clean checkout. The root `.gitignore` excludes personal
exports, environment files, dependencies, runtime logs, and the historical
backend backup. Ignore rules do not remove previously tracked files or erase
Git history. Review actual release contents before publishing; do not upload
this working folder wholesale. Preserve personal exports privately.

Fresh seeding creates sectors and an optional administrator without personal
holdings or a known password. Existing installations retain their accounts:
rotate old default credentials and review administrator roles.

## Configure and deploy

1. Use PHP 8.4.1+ (required by the locked Symfony dependencies), then run `composer install --no-dev --optimize-autoloader` in `backend/` and
   `npm ci && npm run build` in `frontend/`.
2. Configure the server from `backend/.env.example`. Set `APP_ENV=production`,
   `APP_DEBUG=false`, a generated `APP_KEY`, your HTTPS `APP_URL`, the correct
   `FRONTEND_URL`, and private database/Redis credentials.
3. Optionally set `ADMIN_EMAIL` and a unique `ADMIN_PASSWORD` of at least 12
   characters. Run `php artisan migrate --force` and `php artisan db:seed --force`.
   Remove the bootstrap password afterward. Seeding never resets credentials
   or promotes existing accounts.
4. Run `php artisan config:cache` after finalizing environment values. Serve
   `frontend/dist/` with SPA fallback to `index.html`, proxy `/api` to Laravel,
   and expose only `backend/public/` as the backend document root. Use HTTPS.
5. Supervise `php artisan horizon` and `php artisan schedule:work` separately.
   Restart workers after deployments. Run `php artisan nepse:setup` for the
   initial market backfill.

The supplied Docker Compose configuration is a development starting point.
Do not expose its MySQL/Redis ports or use its example passwords in production.

## Validate before opening access

Run backend tests, Pint checks, PHPStan, and frontend build/lint checks. Verify
registration, sign-in, logout, imports, and account isolation with two separate
accounts. Confirm debug output is disabled, scheduled prices update, and database
backups can be restored.

## Upgrade existing installations

Run `php artisan migrate --force` before serving this version. Migration
`2026_11_08_200000_add_profile_to_buy_recommendation_runs.php` adds independent
recommendation runs per investor profile. Old mixed-profile runs remain stored
but are excluded from profile-specific results; generate a new list for each
profile. The rollback refuses to collapse multiple strategies into the old unique
key: export and reconcile those runs before attempting that downgrade.

## Account features

Users can request password recovery from the sign-in page and verify their email
from Settings. Both links expire; reset links are single-use. Configure a working
mail transport and `FRONTEND_URL` matching the deployed UI. Test delivery to your
own account before opening registration. Resetting a password revokes API tokens; operator browser sessions check the
current password hash. Require existing operators to sign in again when upgrading
from a version that did not track session password hashes.
Email verification is available without blocking existing portfolio access.

Settings also offers password-confirmed JSON data export and permanent account
deletion. Deletion clears active account and portfolio records. Publish the actual
retention periods for backups and operational logs separately.

## Analysis safeguards

AI recommendations only accept screened candidates with fresh quotes, use real
stored prices and configured transaction fees, and fit the available cash budget.
Each selected investor profile has its own screened signals and saved buy list.
Trade plans reject contradictory stop/target levels; buy risk/reward uses the top
of the entry range. Fund analysis uses NAV and stores each investor profile
independently. Old quotes and old playbook verdicts are withheld from public buy
rankings. Price/NAV age limits are configurable in `.env.example`.

AI signals and buy lists use current inputs and cannot honestly reconstruct past
knowledge. Use the historical playbook backtest for past dates. AI confidence is a
model assessment, not a calibrated probability of profit. These checks establish
input/output consistency; they do not demonstrate investment performance.

Provider daily budgets reserve every outbound attempt, including retries. With a
positive daily limit, Redis failure pauses AI calls. A limit of zero disables this
protection. Public health responses hide operator diagnostics and provider usage.

## Automated checks and remaining launch decisions

`.github/workflows/checks.yml` runs backend tests/static analysis and frontend
lint/build checks on pushes and pull requests after source is put in a GitHub
repository. It does not deploy the application.

Set up monitoring, backup retention/restoration, HTTPS hosting, mail delivery, and
provider budgets. Publish privacy and retention terms matching actual hosting and
AI provider use. Confirm permission to redistribute market data and choose a
source license before advertising the repository as open source. Live provider
quality, browser interaction, production mail delivery, and hosting still require
validation in the chosen deployment environment.
