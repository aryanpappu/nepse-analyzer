# Repository Guidelines

## Project Structure & Module Organization
NEPSE Analyzer combines stock data collection, analysis, and portfolio accounting. `backend/` contains the Laravel 12 API: domain logic in `app/Services/`, models in `app/Models/`, HTTP endpoints in `routes/api.php`, and migrations and seeders in `database/`. PHPUnit tests live in `backend/tests/Unit/` and `backend/tests/Feature/`; captured scraper inputs live in `tests/Fixtures/Scraper/`.

`frontend/` uses React 18, TypeScript, Vite, and Tailwind CSS. Route screens belong in `src/pages/`, reusable UI in `src/components/`, shared helpers and types in `src/lib/`, and static assets in `public/`. Container configuration lives in `docker-compose.yml` and `docker/`. Read the relevant root `README.md` design section before changing financial calculations or data ingestion.

## Build, Test, and Development Commands
Run backend commands from `backend/` and frontend commands from `frontend/`.

- `composer install`: install PHP dependencies; copy `.env.example` to `.env` and run `php artisan key:generate` for initial setup.
- `php artisan nepse:setup --skip-market`: initialize the database without market crawls; omit the flag to populate market data.
- `php artisan serve`: start the API on port 8000.
- `php artisan horizon` and `php artisan schedule:work`: run queue workers and the scheduler in separate terminals; both are required for automatic updates.
- `npm install && npm run dev`: start Vite on port 5173 with an API proxy.
- `npm run build`: type-check and build production assets.
- `npm run lint` and `npm run typecheck`: validate frontend code.
- `vendor/bin/pint` and `vendor/bin/phpstan analyse`: format PHP and run level-5 static analysis.

## Coding Style & Naming Conventions
Use four-space PHP indentation, strict types, typed signatures, and Laravel Pint conventions. Match existing TypeScript style: two spaces, single quotes, and no semicolons. Use PascalCase component filenames, camelCase helpers, and descriptive service classes.

## Testing Guidelines
Run `php artisan test`, or narrow checks with `--testsuite=Unit` or `--filter=CapitalGainsFifoTest`. Name PHPUnit classes `*Test.php`. Keep unit tests database-free; database tests must extend `Tests\FeatureTestCase` for isolated SQLite storage. Re-capture scraper fixtures instead of editing captured markup. No numeric coverage threshold or frontend test runner is configured.

## Commit & Pull Request Guidelines
This checkout has no Git metadata, so existing commit conventions cannot be verified. Use concise imperative subjects. PRs should describe behavior changes, link relevant issues, report validation commands, and include screenshots for UI changes. Document migration and configuration impacts. Keep credentials and personal portfolio exports out of new commits.

## Public Release Privacy Check
Use invented identities, dates, references, holdings, and amounts in published financial examples. Keep private exports in an ignored `private-fixtures/` directory; deliberately synthetic financial test exports belong in `backend/tests/Fixtures/Synthetic/`. Public market markup in `backend/tests/Fixtures/Scraper/` remains published.

Before release, use `git add -A --dry-run` to enumerate files Git would publish and scan their contents for private identifiers, owner names, memos, and owner-derived financial figures. Include assertions, comments, documentation, and generated-output strings in that check. Ignore rules do not redact text inside published source files.
