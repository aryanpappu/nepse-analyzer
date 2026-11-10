# Public-readiness improvements

This review covered account security, public usability, operational safeguards,
and stock-analysis consistency. It does not certify investment performance or
replace validation against live market data.

## Stock analysis and recommendations

- Preserve independent fund signals and buy recommendation runs for each investor
  profile. Screening, prompt context, horizon, and stop-risk limits follow that profile.
- Reject stale/missing quotes and stale NAV before generating stock AI signals.
  Queue requests check fund NAV rather than incorrectly demanding equity history.
- Require targets above the entire buy-entry band. Compute risk/reward using the
  conservative fill at the band's upper edge; reject nonfinite or incoherent levels.
- Exclude old playbook verdicts and stale quotes from public buy rankings. Check
  current prices when filtering whether a stock is inside its entry zone.
- Accept only screened recommendation candidates, once each. Use stored quotes,
  configured transaction fees, ten-share lots, and available cash for sizing.
- Invalidate comparison caches when underlying prices or financial inputs change.
- Reject backdated AI outputs using today's metadata. Historical playbook testing
  remains the appropriate path for historical strategy evaluation.

## Accounts and privacy

- Password recovery with expiring, single-use links and token revocation.
- Optional signed email verification with expiry, tamper checks, and throttled resend.
- Atomic account/portfolio creation and cache clearing between signed-in accounts.
- Password confirmation for email changes, revocation of other API tokens after
  password changes, and password-hash checks for operator browser sessions.
- Password-confirmed account JSON export and permanent active-database deletion.
- Source/build exclusions for local secrets, personal exports, logs, and backups.

## Public experience and operations

- Useful 404 and recoverable screen errors; keyboard focus, skip navigation,
  loading/error announcements, and reduced-motion support.
- Public-friendly onboarding and error messages; administrative controls restricted
  in both UI and API.
- Public health responses omit operational diagnostics. Provider failure details
  remain in server logs.
- Atomic daily AI-attempt reservations count retries and pause paid calls if the
  configured budget cannot be checked.
- PHP 8.4 Docker runtime matches locked dependencies; Docker build context excludes
  credentials and private data. PHP 8.5 database-option deprecations are resolved.
- GitHub CI configuration checks backend tests/static analysis and frontend lint/build.

## Deployment work still required

See [public deployment](PUBLIC_RELEASE.md). Apply the profile migration before
serving this version. The local MySQL instance was unreachable during this review,
so that migration remains pending outside the isolated test database.

Configure HTTPS, SMTP, monitoring, backups, provider budgets, and deployment secrets.
Verify email delivery, browser flows, market freshness, and live model quality in
staging. Confirm market-data redistribution permissions and publish actual privacy,
retention, and licensing terms. These require decisions and infrastructure specific
to the public service; they are not implied by passing code checks.
