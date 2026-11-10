# Roadmap

Verified status, not aspiration — each "done" line was checked against the
running code or database, and each "open" line names what blocks it.

Last verified: 2026-09-09.

## Done

| Item | Notes |
|---|---|
| Laravel Horizon | Two supervisors. AI queue capped at one process because `GeminiClient::pace()` is per-process. Fixed `retry_after` (90s) sitting below `ScrapeFundamentalsJob`'s 3600s timeout, which would have run the hour-long crawl twice. |
| Cash correction | `PATCH`/`DELETE` on a cash transaction; neither existed. Amend in place or void, balances replayed from the ledger, corrections refused if they would overdraw. |
| Fixed-deposit register | `cash_placements` with date, annual rate, maturity, payout handling, bulk create, Actual/365 accrual. |
| FD wired into valuation | Settled placements count toward net worth; unsettled ones are excluded **and flagged**, because the principal is still sitting in the cash account. Settlement is derived from the ledger row, not a flag. |
| vs-FD is equity-only | Real FD interest reported separately. Otherwise deposits appear on both sides of their own benchmark — a guaranteed tie that dilutes the equity verdict in proportion to the FD share. |
| Market (index) benchmark | Money-weighted "bought the index instead", per-year comparison, beta/alpha/correlation, per-sector vs sub-index. |
| Index data | 17 indices daily from `/live-trading`; history from the paginated `/index-history-data` feed. NEPSE back to 1997; sub-indices to ~2011. |
| Return attribution | Per-holding contributions constrained to sum to total return (tested invariant); per-sector vs sub-index. |
| Benchmark caching | The risk section measured **200 queries/request** on a 120/min tier with no cache. Now TTL-cached with a version counter every portfolio write bumps. Cold 0.29s → warm 0.01s. |
| Observed trading calendar | `market_calendar_days`. No weekday rule: NEPSE moved Sun–Thu → Mon–Fri and may move again. Absence of data is never read as absence of a market. |
| Drip backfill | Works toward a 5-year history target on a request budget, in the live pipeline's idle hours. |
| Sector duplication fixed | `Str::title()` turned `&amp;` into `&Amp;`, which can never decode, creating a second sector row — one scrip's peer percentiles were drawn from a population of one. Root cause fixed, data merged (18 → 17 sectors). |
| `npm run lint` works | No ESLint config had ever existed. Found a real bug: `?? []` outside a `useMemo` re-rendered every profile-context consumer on each pass. |

## Open

### High value

**Playbook backtesting.** Now genuinely unblocked *and* meaningful: index history
exists, the drip backfill is deepening stock history, and there is finally a
benchmark to measure against. "`strong_buy` beat `avoid`" is a weak claim;
"`strong_buy` beat the NEPSE index after fees" decides whether the scoring engine
earns its place. Given the portfolio currently trails the index, this is the
honest test of whether following the playbook would have beaten doing nothing.

**HTTP-level tests.** `tests/Feature/` contains one `ExampleTest.php`. All 301
tests are pure-unit, so roughly ten money-handling endpoints added recently —
cash amend/void, six placement routes, benchmark, attribution — have no HTTP
coverage at all, including the ownership checks that stop one user reading
another's portfolio. Blocked only by two commented-out lines in `phpunit.xml`;
uncomment them, add factories, and the category opens.

**Virtual (paper) trading.** *User is supplying requirements — do not build ahead
of them.* Design notes so nothing is lost:

- Cheap here, because a paper portfolio *is* a portfolio whose money isn't real.
  The ledger, `HoldingCalculator`, cash accounts, valuation, XIRR, vs-FD,
  vs-index, beta/alpha and attribution all already apply. The core is a
  discriminator column on `portfolios`, not a parallel system.
- **Fills must be at market, not user-chosen.** Free entry prices turn paper
  trading into self-flattery. Fill at the stored close with real costs —
  commission tiers, 0.015% SEBON fee, DP charge. In NEPSE that is roughly
  0.6–1.2% per round trip, enough to flip a marginal strategy. This makes the
  fee calculator (below) a prerequisite for honest results.
- **Gate AI spend.** The 18:00 pass runs two AI jobs *per portfolio*. Ten paper
  portfolios is 10× nightly spend against a 450/day free-tier ceiling. Exclude
  virtual portfolios from the scheduled sweep by default.
- **Never mix paper with real** — net-worth totals, the default portfolio, and
  MeroShare imports must all refuse.
- The version with the most value is one that *mechanically follows the playbook*
  for a chosen profile, turning "is the playbook any good?" into a forward-tested
  track record.

### Correctness and data quality

- **`stock_fundamentals.reported_on` is never populated** — NULL on all 1,078
  rows, and `fiscal_period` is a non-uniform BS string (`2082/2083 Q3`,
  `2077/2078`). This is the sole blocker on Brinson allocation attribution:
  `outstanding_shares` covers 93.6% of equities, which is ample, but an undated
  share count cannot produce benchmark weights as at a window start. Attribution
  re-measures each call, so allocation effect switches itself on the day this is
  fixed.
- **NICADF has `stocks.last_price` set but zero rows in both `stock_prices` and
  `fund_navs`** — so `PortfolioValuationService` prices it while
  `PortfolioTimeSeries` correctly will not. Two mark sources disagreeing about
  one holding.
- **Amending a `trade_debit`/`trade_credit` cash row** fixes the cash ledger but
  not its paired `PortfolioTransaction`.
- **380 legacy `AnalyzeStockJob` payloads** still queued on `nepse-default` from
  before the AI-queue split. Horizon runs that queue 3–6 wide, which is the rate
  limit breach the split exists to prevent. Either migrate them to `nepse-ai` or
  drop them — the 15:30 safety net re-queues anything missing a signal.

### Engineering hygiene

- **No CI and no static analysis.** No `.github`, no PHPStan/Larastan. CI would
  have caught a `Carbon::diffInDays()` float-into-int crash that only surfaced on
  first real use, because the unit tests are deliberately DB-free.
- **`composer audit` reports 7 advisories across 2 packages**, and Laravel is
  pinned at 11.55.0 with the CRLF advisory the README already concedes. Fix is
  12.60+.
- **`brick/math` deprecations** on every money operation under PHP 8.4; breaks at
  0.15.
- **`/horizon` is unreachable in production** — admin-gated, but the API is
  stateless Sanctum with no web session to log in through.

### Domain features

- **Capital gains tax** — 7.5% under 365 days, 5% over. `PerformanceService`
  already does FIFO acquisition-date matching, so the rule is the only missing
  piece.
- **Exact transaction costs** — tiered broker commission, SEBON fee, DP charge,
  computed rather than typed in. Prerequisite for honest paper trading.
- **Book close / AGM / rights / IPO calendar** — ShareSansar publishes it
  server-rendered, so the existing scraper handles it. The most actionable alert
  in this market.
- **Stop and target breach alerts** — the playbook already computes both per
  profile; a job comparing today's close against open positions is small.
- **Richer `/api/health`** — it reports pipeline and AI status but not queue
  depth, age of the last successful scrape, index coverage, or drip-backfill
  progress, which are the things that now actually break.
