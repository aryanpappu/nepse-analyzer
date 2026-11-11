# NEPSE Analyzer

Stock analysis and portfolio management for the Nepal Stock Exchange.

> **Educational project — not investment advice.** Free and open source under the
> [MIT License](LICENSE). Not affiliated with NEPSE, SEBON or CDSC. Outputs may be
> wrong; use at your own risk. Read the [Disclaimer](DISCLAIMER.md) before using it.

Crawls daily prices from public NEPSE mirrors, computes technical indicators,
runs LLM-powered analysis, and tracks a real portfolio — including a two-step
import of MeroShare CSV exports.

- **Backend** — Laravel 12, MySQL, Redis (cache + queue + session)
- **Frontend** — React 18, Vite, TypeScript, TailwindCSS, Recharts
- **AI** — pluggable provider (Gemini by default, Anthropic Claude optional) via
  forced tool-use for schema-validated output

---

## Quick start

You need **PHP 8.4.1+**, **Composer**, **Node 20+**, **MySQL** and **Redis**. If you
would rather not install MySQL and Redis, skip to [Docker](#docker) — the rest is
the same.

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate

mysql -u root -e "CREATE DATABASE IF NOT EXISTS nepse_analyzer
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

php artisan nepse:setup        # does everything else, in order
```

`nepse:setup` migrates, seeds, and runs the five market crawls in the order each
depends on the last — prices, history, fundamentals, classification, NAVs,
indices, indicators. It takes about fifteen minutes on a first run, almost all of
it the history backfill, and it prints what it is doing.

**Every step is skipped if its work is already done**, so if something fails
part-way, fix it and run the same command again. Nothing is re-crawled
needlessly.

```
✓ Database reachable          nepse_analyzer
✓ Redis reachable             127.0.0.1
✓ Schema is up to date        already done
✓ Today's prices              already done
✓ 2 year(s) of price history  already done
```

Useful flags: `--fresh` drops everything and starts over (it asks first),
`--skip-market` sets up the database without crawling, `--years=5` backfills
deeper.

### Then run it

Three processes, each in its own terminal:

```bash
php artisan serve           # the API
php artisan horizon         # queue workers
php artisan schedule:work   # the daily crawl
```

```bash
cd frontend && npm install && npm run dev    # http://localhost:5173
```

Create an account on the sign-in page. To bootstrap an administrator, set
`ADMIN_EMAIL` and a unique `ADMIN_PASSWORD` (at least 12 characters) before seeding.
No personal portfolio or default password is installed. See [public deployment](docs/PUBLIC_RELEASE.md).

> **`schedule:work` is not optional.** Horizon supervises queue workers; it does
> not dispatch anything. Without the scheduler the app looks entirely healthy —
> Horizon busy, pages rendering — while prices quietly stop updating. This is the
> single most common way to find it "not syncing", and the app now warns you in a
> banner once the data is three days old.

> The Vite dev server proxies `/api` to `http://127.0.0.1:8000`, so there is no
> CORS setup and no API URL to configure. If port 8000 is taken, run
> `php artisan serve --port=8123` and start Vite with
> `VITE_API_PROXY=http://127.0.0.1:8123 npm run dev`.

### Without an API key

Everything above works with no AI key at all. Signals and recommendations are the
only parts that need one — see [Enabling AI analysis](#enabling-ai-analysis).

---

## Where the data comes from

NEPSE's own site is a JavaScript SPA whose JSON API is behind a rotating token,
so it is not scrapeable with a plain HTTP client. Everything is crawled from
public server-rendered pages instead.

| Data | Source | Notes |
|---|---|---|
| Daily OHLC, volume, turnover, 52-week range | ShareSansar `today-share-price` | 343 scrips in one request |
| Fallback daily prices | Merolagani `LatestMarket.aspx` | Close + volume only, see below |
| Historical daily bars | ShareSansar chart datafeed (`/company-chart/history`) | UDF JSON; needs `countback` |
| Company name, sector, EPS, P/E, book value | Merolagani `CompanyDetail.aspx` | Server-rendered |
| **Filed balance sheet, P&L and regulatory ratios** | **ShareSansar `company-quarterly-report`** | **POST; see below** |
| Symbol → company name **and company id** (all listings) | JSON embedded in every ShareSansar page | 1,627 entries, one request |
| Mutual fund NAV history | ShareSansar `mutual-fund-navs-chart` | Daily NAV, keyed by company id |

Prices fail over: sources are tried in the order set by `NEPSE_SOURCES`, so a
markup change on one site does not stop the pipeline.

Fundamentals **merge** instead, in the order set by `NEPSE_FUNDAMENTAL_SOURCES`
(default `sharesansar_quarterly,merolagani,sharesansar`). No single site
publishes the whole metric set, so every field falls back independently and
earlier sources win on conflict.

### The quarterly report is where the real numbers are

Everything a NEPSE investor actually compares scrips on — share capital,
retained earnings, total equity, ROE, ROA, non-performing loans, capital
adequacy, base rate — is only published on the issuer's filed quarterly report.
The market pages carry a derived subset.

That tab is rendered client side, so `SharesansarQuarterlySource` reproduces
what a browser does:

1. Fetch the today-price page **once**. It yields three things at once: a
   session cookie, the CSRF token, and the symbol → company-id map for the whole
   market. One request, not one 260 KB company page per symbol.
2. `POST /company-quarterly-report` with `company`, `symbol` and `sector`.

**The `sector` parameter selects the filing template** and the endpoint answers
with *empty tables rather than an error* when it is wrong. The sector we already
hold is passed as a hint; if it turns out stale, the true sector is read from the
company page and the request retried once, so a mis-classified scrip
self-corrects instead of silently yielding nothing forever.

**Every sector files on a different template.** The same concept arrives as
`Share capital` (bank), `Paid Up Capital` (hydropower) or `Equity Share Capital`
(manufacturing). `QuarterlyReportParser` therefore matches on declared phrase
rules rather than exact labels, with explicit exclusions for the near-misses that
cause real damage — `Profit before income tax` is not net profit, `Total Equity &
Liabilities` is not total equity, and `Tier I Capital to RWA` is not the capital
adequacy ratio. Balance-sheet tables are headed `Particulars (Rs. in '000)` and
are scaled; the ratio tables are not.

Fixtures for four sector templates live in `tests/Fixtures/Scraper/` and are real
captured markup. Re-capture them if a template changes; do not hand-edit them.

### Two source quirks worth knowing

**Merolagani's `<thead>` lies.** Its live-trading table is labelled
`Open | High | Low` while the cells are actually `High | Low | Open`. Mapping by
header name there would silently transpose the values, so that source uses an
explicit positional map and then validates every row (`high >= close >= low`),
dropping the bar rather than trusting it.

**The date filter is ignored.** `?date=YYYY-MM-DD` is accepted but the page
always renders the last completed session. The trade date is therefore read
*off the page* ("As of : …"), never inferred from the local clock — otherwise a
scrape run on a Wednesday afternoon would file Tuesday's prices under Wednesday.
Asking for a date the source cannot serve fails loudly instead of writing the
wrong day.

---

## Instrument classification

How the app decides whether a scrip is a normal share or a fund, in strict
precedence. It refuses to guess: anything unresolved becomes `unknown` and is
excluded from AI analysis until a human decides.

| # | Signal | Verdict |
|---|---|---|
| 1 | Manual override (`nepse:classify --set=`) | Used as-is, never auto-changed |
| 2 | Publishes a daily NAV | **Definitely a mutual fund** |
| 3 | NEPSE sector is "Mutual Fund" / "Corporate Debentures" / … | Taken from the sector |
| 4 | Name contains Fund / Scheme / **Yojana** / **Kosh** | Fund — unless it is a *management* company |
| 5 | Ticker shape (`…D83` debenture, `…PO` promoter, `…F1` fund) | Inferred |
| 6 | Nothing matched | `unknown` → flagged for review |

Nepali fund names matter here: many NEPSE funds are named *Yojana* (scheme) or
*Kosh* (fund) rather than "Fund", and matching only English words left a large
share of the fund universe unclassified. Conversely, `NMBMF` and `SWMF` have
fund-shaped tickers but are microfinance *companies* — the name check correctly
overrides the ticker.

Current classification: **277 equity · 41 mutual fund · 21 debenture · 4 promoter**.

```bash
php artisan nepse:classify --review                       # what needs a decision
php artisan nepse:classify --symbol=CIT --set=equity      # decide one
```

### Why funds are analysed differently

Fund units trade in a few-paisa band, so RSI and MACD on them are noise, and a
model shown those numbers will invent momentum narratives about rounding. Funds
therefore get a separate prompt that reasons about:

- **NAV** and its trend
- **Premium/discount to NAV**, judged against *that fund's own* historical range
  (Nepali closed-end funds normally trade at a discount, so a discount is not a
  buy signal by itself)
- Distribution history and time to maturity — at maturity units redeem at NAV,
  so a persistent discount converges

Portfolio rows for funds show P/L on both a market and a NAV basis, since the
two can differ materially.

---

## MeroShare import

Two files, because they answer two different questions.

| File | Answers | Authoritative for |
|---|---|---|
| `my_portfolio_….csv` | What do I hold right now? | **Quantity** |
| `wacc_….csv` | What did I pay for it? | **Cost basis** |

The merge takes each number from the file that actually knows it. Notably the
WACC file also lists scrips sold in full — 3 of 8 rows in a fictional example — and
those are detected as closed positions and excluded rather than resurrected as
holdings. MeroShare recalculates WACC lazily, so when the two files disagree on
quantity the holdings file wins and the row is flagged.

Row statuses in the review step:

- **Ready** — both files agree, will be imported
- **Needs price** — held, but no WACC entry; type a price to include it
- **Qty differs** — files disagree; holdings file used, flagged for you
- **Sold** — in WACC only; a closed position, excluded by default
- **Unknown scrip** — not in the stock table; a stub is created automatically

Everything is editable before you commit. The demat account number is reduced to
its last four digits on ingest — the full number is never stored.

UI: **Import** in the nav, or `POST /api/portfolios/{id}/imports`.

---

## Broker statement import

The MeroShare files describe a **position snapshot** — what is held now. So the
ledger they build holds one synthetic buy per open holding and **not a single
sell**: no realised gains, no round trips for FIFO to match, no cash
settlements. The broker's own account statement is the actual history.

```bash
php artisan nepse:broker-import {portfolio} statement.xlsx          # review
php artisan nepse:broker-import {portfolio} statement.xlsx --commit  # write
```

A fictional import could replace 8 snapshot rows with **20 transactions
(12 buy, 8 sell)** and 25 cash movements, enabling realised-gain calculations.

### Four row kinds, and why all four are needed

The `Reference No.` column carries an unambiguous prefix, so direction is read
rather than guessed: `B/` purchase, `S/` sale, `R/` client receipt. Only the
payments back to the client lack one, and they are matched by phrase.

```
deposits          + money in from the client's bank
buy settlements   - cash converted into shares
sell settlements  + shares converted back into cash
payments out      - money returned to the client's bank
```

Importing only the deposits would put the entire contributed capital in the cash
column while the shares it bought are also counted, roughly **doubling net
worth**. They have to land together.

### It checks itself

The statement prints its own closing balance, so the import is verifiable rather
than merely plausible. The rows are replayed and compared **before** anything is
touched, and the resulting ledger is compared again **after** writing. Both run
inside one transaction, so the portfolio either lands on the broker's figure
exactly or is left untouched. A fictional statement with credits of
150,000.00 and debits of 145,000.00 replays to **5,000.00 CR**.

### Statement ordering and historical debit balances

**Row order is not the statement's order.** In a fictional example, replaying
as printed dips to −2,000.00 mid-day because of row order. Chronological replay
with same-day credits first leaves only −250.00 of actual exposure.

**Historical debit must be preserved.** In the fictional example, a
250.00 DR balance on 2001-01-15 is funded on 2001-01-18: the broker settles T+2 and
extends credit in between. `CashLedger` refuses a negative balance, which is
right for a bank account and makes faithful import impossible, so `record()`
takes an `allowOverdraft` flag documented for historical import only — and the
importer *reports* the exposure rather than hiding it.

### Positions the statement sells but never bought

In a fictional example, parcels TESTA and TESTB are sold in full without
matching purchases, alongside 5 units of TESTC. Allotments and bonus issues
arrive through CDSC, so a broker statement alone cannot explain those parcels.

They are seeded at **zero recorded cost with an `unknown` channel**, never at
the sale price. Pricing them at the sale would report a tidy zero gain on a
parcel that probably quadrupled; assuming par would be inventing a number.
Zero cost keeps the units real while leaving the cost visibly unrecorded, and
the command names every one so they can be classified and priced by hand.

---

## IPO or traded: the acquisition channel

A blended return answers the wrong question. An IPO allotted at par by lottery
and a scrip somebody chose to buy are different activities, and only the second
is evidence about the investor.

The distinction is invisible in `type`, because `buy` covers an IPO allotment, a
market purchase and a snapshot row alike. So each transaction carries an
`acquisition_channel` — `ipo`, `secondary`, `bonus`, `rights` or `unknown` — on
the **transaction** rather than the holding, since one position is often several
channels stacked: an allotment topped up on market, then a bonus on top.

### Detection proposes; it never decides

| # | Evidence | Verdict |
|---|---|---|
| 1 | The type declares it (`bonus`, `rights`) | Certain |
| 2 | No price recorded | **`unknown`** — see below |
| 3 | The scrip appears in the broker statement | `secondary`, strong |
| 4 | Price exactly at par **and** absent from the statement | `ipo`, likely |
| 5 | Nothing matched | `unknown` |

Row 4 needs both halves. Par alone is a coincidence a market purchase can
produce; absence alone could mean another broker, or a bonus, or simply predate
the statement.

Row 2 exists because of the seeded parcels above. Every one of them is a scrip
the statement *also* trades, so matching on the symbol alone would file all
seven as trading. `secondary` means "bought at a price somebody chose", and an
unpriced parcel is the opposite.

Two guards make correction safe: a channel confirmed by hand stamps
`channel_confirmed_at` and is **never** overwritten by a later run, and an
`unknown` proposal can never *downgrade* an earlier finding — otherwise a run
made before the statement was imported would wipe what a later one established.

### Ticking IPO records the money it cost

An IPO application is paid straight from the applicant's bank through
MeroShare/CASBA, so the shares arrive and the money that bought them appears in
no broker record. In a fictional example, **12,000.00** of cost basis sits
outside the statement, alongside **58,000.00** of broker-traded cost. A portfolio built from a MeroShare import therefore
counts those shares as value while counting none of their cost.

Marking a parcel `ipo` or `rights` writes the subscription cost into the ledger;
un-marking it removes it again. The round trip is exact and idempotent — tick,
untick, re-tick leaves one row, never two and never a residue.

**Whether it has been recorded is derived from the ledger, never stored.** A
boolean column would be a second source of truth able to disagree; here,
removing the row *is* the untick. This mirrors `CashPlacementSettlement`, which
decides the same question for fixed deposits the same way.

Nothing is invented: capital is quantity times the price already on the
transaction. A zero price yields `null` and writes nothing — a bonus issue costs
nothing, and an unpriced row is missing data rather than a free share.

### Why the split matters more than the total

```
IPO        cost 10,000 -> 40,000    gain +30,000
Secondary  cost 100,000 -> 80,000   gain -20,000
                     blended:       +10,000
               market-priced:       -20,000   <- the honest answer
```

Reported together, that book looks profitable. Split, the stock picking lost
20%. Bucket gains are constrained to sum to the total gain — a tested invariant,
because a split that does not reconcile is hiding a parcel.

---

## Data pipeline

```
nepse:scrape        →  stock_prices          (daily OHLC, upsert on stock+date)
      ↓
nepse:indicators    →  technical_indicators  (RSI, MACD, SMA/EMA, Bollinger, ATR)
      ↓
nepse:analyze       →  stock_signals         (LLM: buy/sell/hold + levels)

nepse:backfill      →  stock_prices          (400 sessions of history)
nepse:fundamentals  →  stock_fundamentals    (EPS, P/E, dividends, sector)
nepse:navs          →  fund_navs             (daily NAV + premium/discount)
nepse:classify      →  stocks.instrument_type
```

Scheduled (`php artisan schedule:work`, NEPSE trades Sun–Thu, closes 15:00 NPT):

| When (NPT) | What |
|---|---|
| **12:00 Sun–Thu** | Midday crawl — intraday prices while the market is open |
| **15:00 Sun–Thu** | Closing crawl — the settled figures for the day |
| 15:30 Sun–Thu | Safety net: queue analysis for anything still missing a signal |
| 18:00 Sun–Thu | Portfolio analyses and buy recommendations |
| Fri 02:00 | Fundamentals crawl (weekly; NEPSE is closed) |

Both crawls upsert the same `(stock_id, trade_date)` row, so the 15:00 pass
simply supersedes the midday snapshot — no duplicate history, and no special
casing for the intraday run. Adjust with `NEPSE_SCRAPE_SCHEDULE` (pipe-separated
cron expressions).

### Fetch now

There is also a **↻ Fetch now** button on the dashboard for an immediate crawl
(`POST /api/market/refresh`). It dispatches to the queue rather than running
inline, because a full crawl plus indicator recalculation takes longer than a
browser will wait, and the job is unique-per-day so double-clicking cannot start
two concurrent crawls.

A 5-minute cooldown (`NEPSE_REFRESH_COOLDOWN`) applies, returning `429` with a
`retry_after` when you are inside it. These are free public sites; a refresh
button with no cooldown is how you get blocked. **The button needs a running
queue worker** (`php artisan horizon`) to actually do anything.

### Guardrails

Scraped market data is not trusted blindly — a shifted column turns a turnover
figure into a price, and that would poison every moving average computed from
it. So:

- A run returning fewer than `NEPSE_MIN_ROWS` (50) rows is rejected as a likely
  bad parse rather than persisted.
- Prices outside a plausible range, negative volumes, and day-on-day moves far
  beyond NEPSE's ±10% circuit breaker are rejected per-row.
- A reported change percentage that disagrees with `(close − prev) / prev` by
  more than 1pp is rejected as a mismapped column. Percentages are computed from
  arithmetic we control and only fall back to the scraped column.
- Rows whose intraday bar contradicts itself are *repaired* (bar dropped, close
  kept) rather than discarded — some sources publish a separately-determined
  close for thinly traded debentures that sits outside their own high/low range.
- Every run is recorded in `scrape_runs` with counts and a sample of rejections.

### AI cost controls

- A Redis-backed daily call counter (`GEMINI_DAILY_CALL_LIMIT`, default 450 —
  the free tier's ceiling; `ANTHROPIC_DAILY_CALL_LIMIT`, default 500) hard-stops
  runaway spend, and a free-tier lockout, from a scheduling bug.
- Only scrips with real turnover (`NEPSE_ANALYSIS_MIN_TURNOVER`) and enough
  history are analysed — there is nothing useful to say about a scrip that
  did not trade, and each call costs money.
- Callers pick a **tier**, never a model name, and each driver maps the tier onto
  its own model: a cheap fast model for the high-volume per-stock signals
  (Flash Lite / Sonnet) and a stronger one for the lower-volume portfolio,
  fundamental and buy-list reasoning (Flash / Opus).
- Structured output comes from **forced tool use**, not "please reply in JSON",
  so the API validates against the schema and the "model wrapped it in prose"
  failure mode disappears.
- Model output is still checked: a stop above entry on a buy, targets on the
  wrong side, or a fair value 10× off the traded price are dropped rather than
  displayed. Risk/reward is always recomputed from the stored levels so it can
  never disagree with them.

---

## API

Public (no auth):

```
GET  /api/health                      pipeline + AI status
GET  /api/market/summary              breadth, turnover, top movers
POST /api/market/refresh              "Fetch now" — queue an immediate crawl
GET  /api/sectors                     sector performance
GET  /api/stocks                      list; search, sector, signal, sort, paginate
GET  /api/stocks/{symbol}             detail + indicators + fundamentals
GET  /api/stocks/{symbol}/history     OHLC series (?days, ?with_indicators)
GET  /api/stocks/{symbol}/signals     signal history
GET  /api/stocks/{symbol}/peers       where it ranks in its sector, per metric
GET  /api/signals                     all signals; filter by type/confidence/risk/profile

GET  /api/profiles                    the three investor profiles and their tuning
GET  /api/playbook?profile=swing      market ranked for one profile (?grade, ?in_zone,
                                      ?sector, ?min_score)
GET  /api/stocks/{symbol}/playbook    all three profiles' verdicts + zones for one scrip

GET  /api/compare?symbols=NABIL,EBL   head-to-head fundamentals, 2-4 scrips
GET  /api/compare/metrics             the metric vocabulary (labels, directions)
GET  /api/transaction-costs/quote     commission + SEBON fee + DP charge for a trade
```

Authenticated (Sanctum bearer token):

```
POST   /api/auth/register | /api/auth/login | /api/auth/logout
GET    /api/auth/me

GET    /api/portfolios
POST   /api/portfolios
GET    /api/portfolios/{id}                    holdings + latest analysis
PATCH  /api/portfolios/{id}
DELETE /api/portfolios/{id}

GET    /api/portfolios/{id}/holdings
POST   /api/portfolios/{id}/transactions       buy/sell/bonus/dividend/rights
PATCH  /api/portfolios/{id}/holdings/{holding}  notes only — positions come from the ledger
DELETE /api/portfolios/{id}/holdings/{holding}
POST   /api/portfolios/{id}/rebuild            replay ledger → positions

GET    /api/portfolios/{id}/actions            add/hold/trim/exit per position (?profile)
GET    /api/portfolios/{id}/performance        year-by-year P&L (?basis=fiscal|calendar)
GET    /api/portfolios/{id}/benchmark          vs the index: money-weighted, per-year,
                                               beta/alpha, per-sector (?index, ?interval,
                                               ?sections)

GET    /api/portfolios/{id}/cash
POST   /api/portfolios/{id}/cash               deposit/withdraw/transfer/set
PATCH  /api/portfolios/{id}/cash/{tx}          amend a mis-entered movement
DELETE /api/portfolios/{id}/cash/{tx}          void one entered in error

GET    /api/portfolios/{id}/cash-placements    fixed deposits + accrued interest
POST   /api/portfolios/{id}/cash-placements
POST   /api/portfolios/{id}/cash-placements/bulk
GET    /api/portfolios/{id}/cash-placements/{p}
PATCH  /api/portfolios/{id}/cash-placements/{p}
DELETE /api/portfolios/{id}/cash-placements/{p}
POST   /api/portfolios/{id}/cash-placements/{p}/withdraw

GET    /api/portfolios/{id}/attribution        why the portfolio trails: per-holding
                                               contributions, per-sector vs sub-index
GET    /api/portfolios/{id}/capital-gains      estimated CGT (?investor_class, ?basis)
GET    /api/portfolios/{id}/cash-imports       broker statement import wizard
POST   /api/portfolios/{id}/cash-imports
PATCH  /api/portfolios/{id}/cash-imports/{i}/rows
POST   /api/portfolios/{id}/cash-imports/{i}/commit

PATCH  /api/auth/me                            name, email, password, and the
                                               benchmark rate + tax class used
                                               by /performance and /benchmark

GET    /api/portfolios/{id}/imports
POST   /api/portfolios/{id}/imports            step 1: holdings CSV
POST   /api/portfolios/{id}/imports/{i}/wacc   step 2: WACC CSV
PATCH  /api/portfolios/{id}/imports/{i}/rows   edit a staged row
POST   /api/portfolios/{id}/imports/{i}/commit write into the portfolio

POST   /api/portfolios/{id}/analyze            AI health + rebalancing
POST   /api/portfolios/{id}/recommend          AI buy list sized to your cash
GET    /api/portfolios/{id}/recommendations    cached, no API call
POST   /api/stocks/{symbol}/analyze            queue a fresh signal
POST   /api/compare/analyze                    AI verdict on a head-to-head
```

AI endpoints are rate limited to 20/min separately from the 120/min on ordinary
authenticated routes, because each one spends money.

---

## Comparing scrips

`GET /api/compare` answers the question every NEPSE forum post asks — "२ Bank
कुन राम्रो" — but with the arithmetic done properly. It is deterministic, free,
and needs no account. `POST /api/compare/analyze` adds the weighting judgement a
tally cannot express, and is the only part that spends an API call.

Four rules make the verdict mean something:

- **Only defensible metrics are scored.** Share price and market capitalisation
  are shown for context and explicitly not counted. Published comparison tables
  hand the win to whichever share is numerically cheaper, which rewards a low
  price for its own sake — cheapness belongs in P/E and P/B, and both are
  already scored.
- **A metric is only contested when every scrip reports it.** Awarding a point
  because the other company did not publish a figure measures disclosure, not
  quality.
- **Percentages are compared as percentages.** The usual table quotes the rupee
  gap to the 52-week high, which is not comparable between a NPR 725 share and a
  NPR 552 one. An NPR 8 gap on the latter is a bigger move than an NPR 5 gap on
  the former.
- **Ties are recorded as ties**, and each metric states why its direction is what
  it is (`MetricCatalog`). Smaller share capital wins, for instance, because it
  dilutes less on each bonus issue — a dilution argument, not a size one.

**Distributable EPS (DEPS)** is the headline metric: retained earnings ÷ shares
in issue, i.e. the pool available for the next dividend and the ceiling on the
next bonus. It is deliberately not EPS, which is one period's profit and says
nothing about what has accumulated. DEPS yield is DEPS as a percentage of the
current price — recomputed live rather than read back from the weekly crawl, so
it is never quoted against a stale price.

Sector percentiles from the same engine are fed into the per-stock signal
prompt, so the model judges a P/E of 19 against Nepali commercial banks rather
than against developed-market norms.

---

## Portfolio accounting

Positions are always a function of the transaction ledger — `quantity` and
`average_cost` are never patched directly by a controller, so a portfolio can
always be reconciled by replaying its transactions (`POST .../rebuild`).

- Buys use a weighted average with **fees capitalised into the cost base**, so
  reported gains are net of brokerage.
- Bonus shares increase quantity at zero cost, diluting average cost.
- Sells compute realised P/L against the average cost in force at the time.
- Overselling is rejected with a clear message, not silently clamped.
- Concurrent writes to the same position lock the row, so two trades cannot both
  read the same average cost and clobber each other.
- Cash lives in two columns (`cash_in_hand`, `bank_balance`) with a full ledger.
- A mis-entered movement is **amended in place** or **voided**, not offset with a
  second entry — a fat-fingered 500,000 should leave one right row, not two
  confusing ones. The pre-edit values are kept in `original_values` and a voided
  row stays readable, so the correction is still auditable. Balances are then
  replayed from the ledger rather than patched, every later `balance_after`
  included, and a correction that would overdraw an account is refused on the
  same rule that governs an ordinary withdrawal. Transfers carry a
  `transfer_group_id` so correcting one leg cannot leave the pair unbalanced.
- Money in a **fixed deposit** is tracked separately from idle cash
  (`cash_placements`), with its start date, annual rate, maturity and payout
  handling, and interest accrued on the same Actual/365 basis as the FD
  benchmark below — so a real deposit and the hypothetical one are never quoted
  on different arithmetic. It is deliberately excluded from `investableCash()`:
  the money is committed until maturity, so offering it to the buy-recommendation
  sizer would propose trades it cannot fund.
- Holdings with no available price are **excluded** from return figures rather
  than counted at cost, and the affected symbols are surfaced in the response —
  treating cost as value would silently report a 0% gain.

---

## Investor profiles

The same scrip is a different proposition to different people, so nothing here
emits one blended verdict. Every scrip is scored once per profile:

| Profile | Horizon | Weights | Targets | Max stop | Position cap |
|---|---|---|---|---|---|
| `long_term` | 6 months – 3 years | fundamentals 45%, valuation 20%, trend 25% | +25% / +60% | 15% | 15% |
| `swing` | 1 – 6 weeks | momentum 45%, trend 25%, volume 15% | +8% / +15% | 5% | 10% |
| `momentum` | 3 – 14 days | momentum 45%, volume 30%, trend 20% | +4% / +8% | 3% | 7% |

Tuning lives in `App\Enums\InvestorProfile` — one place, and the API serves it
at `GET /api/profiles` so the UI never hardcodes a hold band that could drift.

### The playbook engine

`nepse:playbook` scores every scrip for every profile from bars and ratios
already in the database. No LLM call, so the whole market re-scores in under a
second and costs nothing; it runs automatically after every indicator rebuild.

Each verdict carries a grade (`strong_buy` … `avoid`), a 0–100 score with
per-bucket sub-scores and reason lines, and the trade geometry: an **entry
band**, a **stop**, and **two targets**. Three invariants are enforced and
tested — the stop is always below the entry floor, the first target is always
above the entry ceiling, and the entry band is never wider than the profile's
risk budget. A band wider than the stop tolerance is incoherent: fill at the top
and the stop is already behind you.

Stops are placed off ATR (so they respect how much a given scrip actually moves)
then clamped to the profile's loss tolerance — an ATR stop on an illiquid scrip
would otherwise sit 30% away and stop being a stop.

Buckets return `null` when the data to judge them is missing, and the missing
weight is redistributed. Penalising a scrip for having no filed fundamentals
would just rank the best-documented companies to the bottom.

The AI signal is profile-aware too: `POST /api/stocks/{stock}/analyze` takes a
`profile`, the prompt is briefed on whose question it is answering, and signals
are stored per profile, so one scrip can hold a long-term and a swing view for
the same session.

### Position advice

A verdict is priced for someone entering today. Once you hold something, the
question changes, and the answer depends on your average cost as much as on the
chart. `GET /api/portfolios/{id}/actions?profile=` returns one of
`accumulate` / `add_on_dip` / `hold` / `trim` / `exit` / `stop_loss` per open
position, with sizing, the levels, rupees at risk, and the reasoning.

The rules are ordered by what ignoring them costs: a breached stop outranks a
reached target, which outranks a size top-up. Two details worth knowing:

- **The stop is anchored to your cost**, not to today's entry zone. Applying an
  entry-side stop to a position bought months ago fires constantly and means
  nothing. Once the technical stop climbs above your cost it takes over as a
  trailing stop, which protects a gain rather than merely limiting a loss.
- **The hold window alone never forces an exit.** Any long-held position is past
  a swing window, so the window plus a thesis that has stopped working is what
  makes it dead money.

Advice is lens-dependent by design. A book of long-term holdings viewed as a
swing trader's will show a lot of exits — that is the honest answer to "should a
swing trader be in these", not an alarm.

## Performance

`GET /api/portfolios/{id}/performance?basis=fiscal|calendar` reports what each
year actually earned, bucketed by **Nepali fiscal year** (Shrawan 1 – Ashad end,
how capital gains and broker statements are reported) or by calendar year.

Realised figures come from the transaction ledger and are historical fact.
Unrealised is a mark at the latest close and therefore belongs to the live
period only — a closed year reports what it realised, and its unrealised column
is blank rather than zero.

Round trips are reconstructed rather than stored, because the ledger records
transactions, not trades. Gains use the same weighted-average cost as the rest
of the app, so they always reconcile with a holding's `realized_gain`, while the
acquisition date is matched **FIFO** — the only way to say how long the money
was actually at work. Bonus units inherit the position's age rather than the
award date, so a long holding is not made to look new.

Headline return is **XIRR**, not gain ÷ invested: Rs 1 lakh added last month and
Rs 1 lakh added three years ago are not comparable. Solved by bisection rather
than Newton-Raphson, which cannot diverge on the irregular flows a real
portfolio produces.

### Return per year, and the fixed-deposit benchmark

A per-year return needs the capital the year started with, which nothing
records. It does not need to: positions are a function of the ledger and every
close is already stored, so `PortfolioTimeSeries` replays the ledger to any past
date and marks it at that date's prices. No snapshot table, and no waiting a
year to start collecting one. Closed-end funds are marked from `fund_navs`
(market price, else NAV) since they never appear in the daily price table.

Each period therefore reports:

- **Profit** — closing − opening − money put in. Realised and unrealised
  together: what the year actually earned.
- **Return** — not annualised, so a part-year stays honest. The annualised
  money-weighted figure is shown underneath, but only once the period is
  complete: six weeks at −6% annualises to −48%, which is an extrapolation, not
  a result.
- **vs FD** — rupees ahead of or behind a fixed deposit.

The benchmark answers the only question that matters about a stock portfolio:
did it beat leaving the money in the bank. The same deposits and withdrawals are
compounded at the deposit rate to the same end date — money put in earns until
the end, money taken out (a sale, a dividend) stops earning when you took it, so
neither side is a strawman. Rate comes from `nepse.benchmark.fixed_deposit_rate`
(`NEPSE_FD_RATE`), can be set per year in `fixed_deposit_rates`, and is
overridable per request with `?fd_rate=` so the page can re-base it live.

A boundary the price history cannot reach reports **n/a**, never zero — held
positions with no price between them is a date before the history starts, not a
portfolio that went to nothing.

FY boundaries come from a BS conversion table in `App\Support\NepaliFiscalYear`
(Shrawan 1 falls on 16 or 17 July depending on the year, which no formula
gives). Override `nepse.fiscal_year_starts` if a year is wrong; years outside
the table fall back to 16 July and report themselves as approximate.

## Docker

Only needed for a clean machine, CI, or deployment — local development uses your
native MySQL and Redis.

Two commands, and you do not need PHP, MySQL or Redis installed:

```bash
docker compose up -d --build
docker compose exec app php artisan nepse:setup
```

The first brings up every service; the second migrates, seeds and crawls, and is
safe to re-run. Then open **http://localhost:5173** and create your account.
For public hosting, follow [the deployment guide](docs/PUBLIC_RELEASE.md).

Services: `app` (PHP-FPM), `nginx`, `queue` (Horizon), `scheduler`, `frontend`,
`mysql`, `redis`. The scheduler runs in its own container here, so unlike the
local setup you do not have to remember to start it.

Host ports are 3307/6380 for the databases so they cannot collide with native
instances on 3306/6379.

The containerised database is MySQL rather than PostgreSQL, deliberately: running
one dialect locally and another in containers is a reliable way to ship a
migration that passes in development and fails in production.

---

## Beating the bank, and beating the market

The FD benchmark below answers *"did this beat leaving the money in the bank"*.
It does not answer *"did picking these scrips beat simply buying the index"* —
a different question, and the one that says whether stock selection added
anything. `GET /api/portfolios/{id}/benchmark` answers it, built the same way:
the portfolio's real cash flows on their real dates buy index **units** at that
date's level, money put in works until the end, money taken out stops working
when it was taken out. Units rather than a compounding rate, because the index
does not move at a fixed one.

It also reports **beta** (how much more than the market this book moves),
**alpha** (return beyond what that risk explains) and **correlation**, from a
paired return series. Sampling is weekly by default: `PortfolioTimeSeries`
issues one query per sample date, so a multi-year daily replay is hundreds of
queries for a smoother line nobody reads. `?interval=daily|weekly|monthly`.

Holdings in a sector are additionally judged against their **sub-index** — a
bank against banks, not against hydropower. Debentures, government bonds,
preference and promoter shares have no sub-index and report that plainly rather
than being compared against the wrong thing.

`?sections=market,periods,risk,sectors` keeps a page from paying for what it
does not render.

### Coverage is not a footnote

**A partial index window can invert the verdict.** In a fictional example,
60% coverage suggests the portfolio is **beating** the market by NPR 2,000.00;
complete history places it NPR 1,500.00 **behind**. That is a 3,500.00 swing
caused by the gains in the previously uncovered stretch.

So every response carries `index_coverage_percent`,
`index_simulation_complete`, `index_uncovered_dates` and a plain-English
`index_coverage_note`, the UI labels an incomplete comparison *provisional*, and
a window with no index data reports that rather than `0%`. Same rule the rest of
the app follows: **n/a, never zero**.

Index history comes from a ShareSansar DataTables feed
(`/index-history-data`, 50 rows per page, so deep history is paginated). The
NEPSE index goes back to **1997**; most sector sub-indices only to around 2011,
when ShareSansar began publishing them separately. Index-level volume,
transaction counts and advance/decline breadth are **not** published anywhere
crawlable — those columns stay `null` rather than being fabricated.

Daily values come from `/live-trading`, which is a *separate* request from the
price crawl: the `today-share-price` page carries no index data at all.

```bash
php artisan nepse:indices                                  # today's 17 indices
php artisan nepse:indices --backfill --days=1200            # all indices
php artisan nepse:indices --backfill --index="NEPSE Index"  # one
php artisan nepse:indices --show
```

---

## Historical backfill and the market calendar

`nepse:backfill --days=400` per symbol is the manual tool. The system also fills
its own history in the background: it works towards
`NEPSE_BACKFILL_TARGET_YEARS` (default 5) of bars for every eligible scrip,
**drip-fed** rather than in one burst. These are free public sites, and a
thousand-request catch-up is how you get blocked. A budgeted handful of symbols
per run, in the hours the live pipeline is idle, reaches a 5-year target in a
day or two and never spikes.

```bash
php artisan nepse:backfill-drip [--status] [--force] [--reset] [--symbol=]
                               [--mark-holiday=] [--mark-trading-day=]
```

### The trading calendar is observed, not predicted

Deciding whether a date with no bars was a **market holiday** or a **hole in our
data** is the whole problem, and it cannot be answered from a weekday rule.

NEPSE traded Sunday–Thursday, then moved to Monday–Friday, and may move again.
Nepali closures follow the Bikram Sambat festival calendar — Dashain alone shuts
the market for around a fortnight, on different Gregorian dates each year — plus
ad-hoc closures nobody can predict. No constant survives that, in either
direction: a Sun–Thu rule invents gaps every Friday it will chase forever, and
declares every Sunday a holiday.

So nothing is predicted. `market_calendar_days` records one row per date with
what was attempted and what was seen, and the classification follows from the
evidence in strict precedence:

| # | Evidence | Verdict |
|---|---|---|
| 1 | Manual override (`--mark-holiday=` / `--mark-trading-day=`) | Used as-is |
| 2 | A bar was observed | **Trading day** — whatever weekday it is |
| 3 | Checked, and the market was empty | **Holiday** |
| 4 | Never attempted, or the attempt failed | `unknown` — never a holiday, stays eligible |

Row 4 is the one that matters. A dead crawler and a shut market both produce
zero rows, and branding the first a holiday is permanent damage: the date is
excluded from every future backfill and the data never returns. "Checked" is
therefore evidence, not an assumption — a date counts as checked only when a
liquid reference basket (`NEPSE_BACKFILL_HOLIDAY_BASKET`, else the N
best-covered scrips) has history spanning it, or a successful `scrape_runs`
entry covered it.

Judging on a liquid basket rather than on all 343 scrips matters twice over:
those symbols trade nearly every session, so their silence is meaningful, and
they are the first to be backfilled, so the test works while history is still
shallow.

**This costs nothing extra.** The chart datafeed returns a whole date window in
one request per symbol, so which dates traded is already in a response we had to
fetch anyway. The calendar is a byproduct of the bars, not a separate crawl.

Because the calendar is observed, the trading-week change needs no
configuration: Fridays simply start appearing and are recorded as sessions. For
the same reason the schedules no longer encode `* * 0-4` — **a cron weekday
field cannot follow a regime change**, so the jobs run daily and skip a date
with no trading data instead.

### What this still cannot do

It is inference from evidence, not an authoritative calendar. A closure older
than the basket's backfilled history stays `unknown` rather than being confirmed
— correctly not a false gap, but not proactively known either, until the basket
deepens or someone asserts it with `--mark-holiday=`. Making it authoritative
would mean crawling NEPSE's published holiday notices, the way
`App\Support\NepaliFiscalYear` concedes the BS calendar needs a lookup table
because no formula gives it.

---

## Queues

Jobs are supervised by **Laravel Horizon** — one `php artisan horizon` process
replaces hand-rolled `queue:work` invocations, and the worker pool, its size and
its timeouts live in `backend/config/horizon.php` instead of in whatever flags
the last person typed. The dashboard is at **`/horizon`**: throughput, runtime,
failures, and a retry button per job.

Two supervisors, because the two workloads have incompatible shapes:

| Supervisor | Connection → queue | Processes | Carries |
|---|---|---|---|
| `pipeline` | `redis` → `nepse-default` | 3 local / 6 prod | Crawling, indicators, playbook |
| `ai` | `redis-ai` → `nepse-ai` | **1, deliberately** | Every job that calls a model |

**The AI queue is capped at one process on purpose.** `GeminiClient::pace()`
holds requests apart using an instance property, so the free tier's
requests-per-minute limit is only respected *within a single process*. Two
workers would each pace independently and double the real request rate straight
into 429s. Raise `maxProcesses` there only after that pacing moves into Redis.

Splitting them also stops a model backlog from starving the crawl: an evening
that queues 300 analyses no longer leaves the next morning's scrape waiting
behind them.

Each job declares its own `$tries`, `$timeout` and `$backoff`. Two numbers have
to stay ordered around the longest of them, or a slow job is made visible again
and runs a second time while the first is still going:

```
job $timeout  <  horizon supervisor timeout  <  connection retry_after
   3600s      <          3660s               <        3720s            (pipeline)
    300s      <           360s               <         420s            (ai)
```

`ScrapeFundamentalsJob` is the long pole on the pipeline queue at an hour, which
is what sets those figures. Raising any job's timeout means raising both.

The dashboard is admin-only outside local (`users.role = admin`). The API is
stateless — Sanctum bearer tokens, no web session — so reaching `/horizon` in
production needs a web guard; until one exists, keep `HORIZON_PATH` unguessable
or put the reverse proxy's auth in front of it.

```bash
php artisan horizon                  # start the pool
php artisan horizon:status
php artisan horizon:list             # running master processes
php artisan horizon:terminate        # graceful; also the deploy hook
php artisan horizon:pause | :continue
```

Horizon must be terminated with SIGTERM so a worker finishes the job in hand — a
crawl killed mid-flight restarts from the beginning. The Compose service sets a
120s `stop_grace_period` for that reason.

---

## Commands

```bash
php artisan nepse:scrape [--date=] [--source=] [--queue]
php artisan nepse:backfill [--symbol=] [--days=400] [--indicators]
php artisan nepse:backfill-drip [--status] [--force] [--reset] [--symbol=]
php artisan nepse:cash-placements
php artisan nepse:broker-import {portfolio} {file.xlsx} [--commit] [--account=]
php artisan nepse:date-fundamentals [--dry-run] [--formats]
php artisan nepse:indicators [--symbol=] [--full]   # also rescores the playbook
php artisan nepse:playbook [--profile=] [--date=]
php artisan nepse:fundamentals [--symbol=] [--limit=]
php artisan nepse:classify [--probe-nav] [--review] [--symbol= --set=]
php artisan nepse:navs [--symbol=] [--show]
php artisan nepse:indices [--backfill] [--days=] [--index=] [--show]
php artisan nepse:analyze [--symbol=] [--limit=] [--queue]
php artisan nepse:portfolio-analyze [id] [--recommend]
```

## Configuration

`backend/config/nepse.php`, `backend/config/ai.php`, `backend/config/gemini.php`
and `backend/config/anthropic.php` hold every tunable
(sources, HTTP timeouts and politeness delay, validation thresholds, trading
calendar, analysis filters, cache TTLs, schedule, models, spend limits). All are
env-overridable — see `.env.example`.

---

## What has no user interface yet

Several subsystems are complete and tested but reachable only by API or CLI.
Listed because "it exists" and "you can use it" are different claims:

| Feature | Reachable by |
|---|---|
| Broker statement import | `nepse:broker-import` |
| Acquisition channel (IPO vs traded) | ✅ **IPO / Traded** page |
| Profit split by channel | ✅ same page |
| Capital gains tax | ✅ **Tax** page |
| Return attribution | ✅ **Attribution** page |
| Corporate calendar | ✅ **Calendar** page |
| Transaction cost quote | `GET /transaction-costs/quote` |
| Playbook backtest | `nepse:playbook-backtest` — deliberate; it is a full-market sweep |

Screens exist for cash correction, fixed deposits, the net-worth breakdown,
both benchmarks, and profile settings.

---

## Known limitations

- **Historical backfill depends on one source.** Only the ShareSansar chart
  datafeed serves per-symbol history; if it changes, existing history is safe but
  new backfills stop until a second history source is added.
- **Turnover is not available for backfilled bars.** The chart feed omits it.
  Backfilled rows carry `turnover = 0` rather than a fabricated
  `volume × close`, and the daily scrape fills the real figure going forward.
  Liquidity filters therefore only apply to recent sessions.
- **Debenture and preference scrips are not analysed.** They have fixed coupons;
  the equity and fund models would both be meaningless.
- **Floated (public) share capital is not available.** The published comparison
  tables carry a "Floated Share" row, but no free source publishes the
  promoter/public split per company: ShareSansar and Merolagani both omit it, and
  NEPSE's own API returns 401 without its rotating token. Rather than infer it
  from a promoter ratio that varies per issuer, the field is left out entirely —
  every other parameter in those tables is reproduced from the filings.
- **Quarterly filings cover 257 of 300 listed equities.** The remainder return
  "No data available" from the source; debentures, promoter shares and mutual
  funds file no quarterly report at all. Distributable EPS additionally needs a
  retained-earnings line, which the hydropower template does not carry — it
  reports only "Reserve & Surplus".
- **Fiscal periods can differ between compared scrips.** A Q4 filing compared
  against a Q3 one flatters the Q4 side on annualised figures. The period is
  shown per scrip and the AI verdict is told to flag it, but the metric tally
  cannot correct for it.
- **Laravel 11 has open CVEs.** `composer audit` reports a CRLF-injection
  advisory in the framework's default `email` validation rule affecting all of
  11.x, with fixes only in 12.60+/13.10+. Laravel 11 was pinned as specified;
  upgrading to 12.x is the remedy if that matters for your deployment.
- **AI output is advisory.** Levels are sanity-checked, not verified. This is not
  investment advice.

## License

Source code is distributed under the [MIT License](LICENSE): anyone may use, copy,
modify and share it, including commercially, provided the copyright notice is kept.
It comes with no warranty. Read the [Disclaimer](DISCLAIMER.md): this is an
educational project, not investment advice, and operators of a deployment are
responsible for market-data permissions, user privacy and AI-provider terms.
Market-data rights, third-party service terms, and personal financial exports are
separate from the source-code license. See [public deployment](docs/PUBLIC_RELEASE.md).
