<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Price sources
    |--------------------------------------------------------------------------
    |
    | NEPSE's own site (nepalstock.com) is a JavaScript SPA whose JSON API is
    | protected by a rotating token, so it is not scrapeable with a plain HTTP
    | client. We therefore read the published daily price tables from public
    | mirrors instead. Sources are tried in the order listed until one returns
    | a usable number of rows — if the markup of one changes, the next takes
    | over instead of the whole pipeline failing.
    |
    | Each key must map to a driver registered in PriceSourceManager.
    |
    */

    'sources' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('NEPSE_SOURCES', 'sharesansar,merolagani'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Fundamentals sources
    |--------------------------------------------------------------------------
    |
    | Fundamentals are merged rather than failed over: each source publishes a
    | different slice, so every field falls back independently and the whole
    | list is crawled. Order decides who wins a conflict — earlier wins.
    |
    | sharesansar_quarterly leads because it carries the figures the issuer
    | actually filed (share capital, retained earnings, equity, ROE, NPL,
    | capital adequacy). merolagani follows with the market-derived headline
    | ratios, and sharesansar with the dividend split.
    |
    | May name any driver registered in PriceSourceManager, from either the
    | price-source list or the fundamentals-only list.
    |
    */

    'fundamental_sources' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'NEPSE_FUNDAMENTAL_SOURCES',
            'sharesansar_quarterly,merolagani,sharesansar'
        ))
    ))),

    'source_config' => [

        'sharesansar' => [
            'label' => 'ShareSansar',
            // Server rendered table of today's price for every listed scrip.
            'today_price_url' => env(
                'NEPSE_SHARESANSAR_URL',
                'https://www.sharesansar.com/today-share-price'
            ),
            'company_url' => env(
                'NEPSE_SHARESANSAR_COMPANY_URL',
                'https://www.sharesansar.com/company/{symbol}'
            ),
            // NEPSE Index, Sensitive Index and the sector sub-indices — a
            // different page from today_price_url above, which carries no
            // index summary at all despite publishing everything else.
            'live_trading_url' => env(
                'NEPSE_SHARESANSAR_LIVE_TRADING_URL',
                'https://www.sharesansar.com/live-trading'
            ),
            // Historical daily index bars (open/high/low/close/turnover),
            // paginated server side. See SharesansarIndexHistorySource.
            'index_history_url' => env(
                'NEPSE_SHARESANSAR_INDEX_HISTORY_URL',
                'https://www.sharesansar.com/index-history-data'
            ),
        ],

        'merolagani' => [
            'label' => 'Merolagani',
            'today_price_url' => env(
                'NEPSE_MEROLAGANI_URL',
                'https://merolagani.com/LatestMarket.aspx'
            ),
            'company_url' => env(
                'NEPSE_MEROLAGANI_COMPANY_URL',
                'https://merolagani.com/CompanyDetail.aspx?symbol={symbol}'
            ),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP behaviour
    |--------------------------------------------------------------------------
    */

    'http' => [
        'timeout' => (int) env('NEPSE_HTTP_TIMEOUT', 45),
        'connect_timeout' => (int) env('NEPSE_HTTP_CONNECT_TIMEOUT', 15),
        'retries' => (int) env('NEPSE_HTTP_RETRIES', 3),
        'retry_delay_ms' => (int) env('NEPSE_HTTP_RETRY_DELAY_MS', 2000),
        'user_agent' => env('NEPSE_USER_AGENT', 'Mozilla/5.0 (compatible; NepseAnalyzer/1.0)'),
        // Politeness delay between consecutive requests to the same host.
        'request_delay_ms' => (int) env('NEPSE_REQUEST_DELAY_MS', 1200),
        'verify_ssl' => (bool) env('NEPSE_VERIFY_SSL', true),
        // Let the fundamentals crawl wait on different hosts at the same time
        // (ShareSansar and Merolagani). Per-host politeness is unchanged: one
        // request in flight per host, request_delay_ms between starts. Turn
        // off to go back to strictly one request at a time overall.
        'concurrent_hosts' => (bool) env('NEPSE_HTTP_CONCURRENT_HOSTS', true),
        // In-memory reuse of a static page two sources read in the same pass
        // (the ShareSansar company page). Opt-in per call, never used for
        // live price pages. Bounded because the fetcher outlives a job.
        'reuse_ttl_seconds' => (int) env('NEPSE_HTTP_REUSE_TTL_SECONDS', 300),
        'reuse_max_entries' => (int) env('NEPSE_HTTP_REUSE_MAX_ENTRIES', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sanity thresholds
    |--------------------------------------------------------------------------
    |
    | A scrape that returns almost nothing usually means the page markup moved,
    | not that the market was quiet. We refuse to persist such a run so a bad
    | parse cannot overwrite good data.
    |
    */

    'validation' => [
        'min_rows' => (int) env('NEPSE_MIN_ROWS', 50),
        // NEPSE enforces a +/-10% daily circuit breaker; allow some headroom
        // for the first trading day after a book-close price adjustment.
        'max_daily_change_percent' => (float) env('NEPSE_MAX_DAILY_CHANGE', 30.0),
        'max_price' => (float) env('NEPSE_MAX_PRICE', 200000),
        'min_price' => (float) env('NEPSE_MIN_PRICE', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Index sanity thresholds
    |--------------------------------------------------------------------------
    |
    | Deliberately no min/max *level* here, unlike validation.max_price above —
    | NEPSE Index was under 200 in the late 1990s and over 3,000 by the 2020s,
    | and a sector sub-index can be rebased at any time, so no constant would
    | survive the data's own history (see App\Services\Scraper\
    | IndexRowValidator). Only relative day-on-day movement and internal
    | consistency are checked.
    |
    */

    'index_validation' => [
        // An index is a value-weighted average across ~300 scrips, each
        // individually capped at NEPSE's own +/-10% circuit breaker, so a
        // whole-index move anywhere near that is already extraordinary.
        // Headroom is still wider than a single stock's to admit a genuine
        // market-wide event without a config change.
        'max_daily_change_percent' => (float) env('NEPSE_INDEX_MAX_DAILY_CHANGE', 20.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Market calendar
    |--------------------------------------------------------------------------
    |
    | NEPSE traded Sunday through Thursday historically; it has since moved to
    | Monday-Friday, and public holidays follow the Bikram Sambat calendar,
    | which no formula reproduces. So this flat weekday list is NOT the
    | authority on whether a date is a trading day - App\Services\Scraper\
    | Backfill\MarketCalendarClassifier decides that from what has actually
    | been observed (a bar proves a session; a checked-and-empty date proves a
    | closure). This value is kept only as a cheap, non-authoritative
    | prioritisation hint and for the small number of callers that need a
    | best-guess "which day is today's session" before anything has been
    | scraped yet (NepseScraperService::latestTradingDay(), the Merolagani/
    | ShareSansar "assumed trading day" fallbacks) - update it if the current
    | regime changes, but nothing in the backfill's gap/holiday logic depends
    | on it being right.
    |
    */

    'trading_days' => [7, 1, 2, 3, 4],

    /*
    |--------------------------------------------------------------------------
    | Cash placements
    |--------------------------------------------------------------------------
    |
    | Money parked in an interest-bearing account — typically a bank fixed
    | deposit — rather than held as idle cash or invested in scrips.
    |
    | There is deliberately no default *rate* here: omitting one on create
    | falls back to `nepse.benchmark.fixed_deposit_rate` below, so a real
    | deposit and the hypothetical FD the performance page compares against
    | are quoted from one number rather than two that can drift apart.
    |
    */

    'cash_placements' => [
        'default_kind' => env('NEPSE_CASH_PLACEMENT_DEFAULT_KIND', 'fixed_deposit'),
        'default_compounding_basis' => env('NEPSE_CASH_PLACEMENT_DEFAULT_COMPOUNDING', 'simple'),
        'default_payout' => env('NEPSE_CASH_PLACEMENT_DEFAULT_PAYOUT', 'paid_out'),
        'default_status' => env('NEPSE_CASH_PLACEMENT_DEFAULT_STATUS', 'active'),
        'maturing_soon_days' => (int) env('NEPSE_CASH_PLACEMENT_MATURING_SOON_DAYS', 30),
        'bulk_max' => (int) env('NEPSE_CASH_PLACEMENT_BULK_MAX', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Benchmark
    |--------------------------------------------------------------------------
    |
    | The honest question about a stock portfolio is not "did it go up" but
    | "did it beat the boring alternative". In Nepal that alternative is a bank
    | fixed deposit, so the performance report compares against one.
    |
    | Set `fixed_deposit_rate` to the rate you could actually get, as a
    | percentage per year. Rates move, so `fixed_deposit_rates` overrides it for
    | specific years — keyed by Nepali BS fiscal year for the fiscal basis, or
    | by Gregorian year for the calendar basis. Anything not listed falls back
    | to the flat rate.
    |
    */

    'benchmark' => [
        'fixed_deposit_rate' => (float) env('NEPSE_FD_RATE', 8.0),

        'fixed_deposit_rates' => [
            // '2082' => 7.5,   // Nepali FY 2082/83
            // '2026' => 7.5,   // calendar 2026
        ],

        /*
         * The market benchmark — "did stock-picking add anything", as opposed
         * to the deposit benchmark's "did this beat the bank".
         *
         * Slug, not name: `market_indices` is keyed on the slug the scraper
         * derives from the index's published name, so this must match
         * IndexCatalog's canonical name slugified — "NEPSE Index" is stored as
         * `nepse-index`, not `nepse`.
         */
        'market_index_slug' => env('NEPSE_MARKET_INDEX_SLUG', 'nepse-index'),

        /*
         * Sector -> sub-index, so a bank holding is judged against banks
         * rather than against the whole market. Keys are `sectors.slug`,
         * values the index slug.
         *
         * Only sectors whose name differs from its index need an entry — an
         * unmapped sector falls back to trying its own slug, which already
         * matches for investment, life-insurance, mutual-fund,
         * non-life-insurance and manufacturing-and-processing.
         *
         * Debentures, government bonds, preference and promoter shares have no
         * sub-index at all and degrade to "no benchmark available", which is
         * the honest answer rather than a comparison against the wrong thing.
         */
        'sector_index_slugs' => [
            'commercial-bank' => 'banking-subindex',
            'development-bank' => 'development-bank-index',
            'finance' => 'finance-index',
            'hotel-tourism' => 'hotels-and-tourism',
            'hydropower' => 'hydropower-index',
            'microfinance' => 'microfinance-index',
            'others' => 'others-index',
            'trading' => 'trading-index',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Manual refresh
    |--------------------------------------------------------------------------
    |
    | Minimum seconds between user-triggered "fetch now" crawls. These are free
    | public sites; a refresh button with no cooldown is an invitation to hammer
    | them until we get blocked.
    |
    */

    'refresh_cooldown_seconds' => (int) env('NEPSE_REFRESH_COOLDOWN', 300),

    'timezone' => env('NEPSE_TIMEZONE', 'Asia/Kathmandu'),

    /*
    |--------------------------------------------------------------------------
    | Analysis pipeline
    |--------------------------------------------------------------------------
    */

    'analysis' => [
        // Calendar-day safety limits; allow weekends and short exchange holidays.
        'max_price_age_days' => (int) env('NEPSE_ANALYSIS_MAX_PRICE_AGE_DAYS', 7),
        'max_nav_age_days' => (int) env('NEPSE_ANALYSIS_MAX_NAV_AGE_DAYS', 45),
        // Skip illiquid scrips — Claude cannot say anything useful about a
        // stock that did not trade, and each call costs money.
        'min_turnover' => (float) env('NEPSE_ANALYSIS_MIN_TURNOVER', 100000),
        // Minimum closes required before technical indicators are meaningful.
        'min_history_days' => (int) env('NEPSE_ANALYSIS_MIN_HISTORY', 30),
        'batch_size' => (int) env('NEPSE_ANALYSIS_BATCH_SIZE', 25),
        // History window handed to the model in the prompt.
        'prompt_history_days' => (int) env('NEPSE_PROMPT_HISTORY_DAYS', 60),
        // A signal older than this is considered stale by the API layer.
        'signal_ttl_days' => (int) env('NEPSE_SIGNAL_TTL_DAYS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chart / history defaults
    |--------------------------------------------------------------------------
    */

    'history' => [
        'default_days' => (int) env('NEPSE_HISTORY_DEFAULT_DAYS', 90),
        'max_days' => (int) env('NEPSE_HISTORY_MAX_DAYS', 1825),
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-healing historical backfill
    |--------------------------------------------------------------------------
    |
    | Instead of an operator remembering to run `nepse:backfill --days=` for
    | every scrip, BackfillHistoryDripJob works the whole market towards
    | `target_years` of history on its own - a few symbols per scheduled tick,
    | never a burst. Only ONE source serves per-symbol history
    | (SharesansarChartSource); these knobs bound how hard we lean on it.
    |
    */

    'backfill' => [
        // How deep the background job tries to reach for every eligible
        // scrip. 5 years matches history.max_days (1825 days) above.
        'target_years' => (int) env('NEPSE_BACKFILL_TARGET_YEARS', 5),

        // How many symbols (== requests, for the chart-feed source: one
        // request fetches a symbol's whole window) a single drip run spends.
        // Small on purpose - these are free public sites, not a paid API.
        'symbols_per_run' => (int) env('NEPSE_BACKFILL_SYMBOLS_PER_RUN', 15),

        // Hard cap on requests in one run, independent of symbols_per_run, in
        // case a future history source needs more than one request per scrip.
        'max_requests_per_run' => (int) env('NEPSE_BACKFILL_MAX_REQUESTS_PER_RUN', 15),

        // Minimum hours between two attempts for the same symbol, so a scrip
        // short of the target on try 1 does not get re-queried again a few
        // minutes later - give the source's own publishing cadence time.
        'retry_after_hours' => (int) env('NEPSE_BACKFILL_RETRY_HOURS', 20),

        // The "liquid reference basket": a small set of scrips liquid enough
        // that they trade essentially every session, so "none of them has a
        // bar" is strong, fast-to-establish evidence of a market-wide
        // closure - it does not need to wait for the whole ~300-scrip
        // universe to be backfilled that far back. Comma separated tickers;
        // leave empty to auto-select the N stocks (holiday_basket_size) with
        // the most stored daily bars, as a liquidity/completeness proxy
        // (turnover is not reliably available for backfilled bars - see
        // README "Known limitations").
        'holiday_basket_symbols' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('NEPSE_BACKFILL_HOLIDAY_BASKET', ''))
        ))),
        'holiday_basket_size' => (int) env('NEPSE_BACKFILL_HOLIDAY_BASKET_SIZE', 5),

        // A calendar date is only trusted as "checked and empty" (a holiday)
        // once bar coverage across the basket reaches this fraction of the
        // basket's size - i.e. enough of the basket's own fetch history
        // actually covers that date for its silence to mean something. That
        // span extends backwards on its own as the drip job deepens each
        // basket symbol's history.
        'basket_coverage_fraction' => (float) env('NEPSE_BACKFILL_BASKET_COVERAGE_FRACTION', 0.8),

        // Cron expression(s) for when the drip job is allowed to run. Default
        // sits in hours the live pipeline is idle - clear of the 12:00/15:00
        // scrapes, the 15:30 analysis safety net, and the 18:00 portfolio
        // pass (all in nepse.timezone).
        'schedule' => env('NEPSE_BACKFILL_SCHEDULE', '*/15 0-10,20-23 * * *'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Index history backfill
    |--------------------------------------------------------------------------
    |
    | ShareSansar's index-history datafeed (App\Services\Scraper\Sources\
    | SharesansarIndexHistorySource) serves years of daily bars per index —
    | back to 1997 for NEPSE Index itself, to ~2011 for most sub-indices — but
    | paginates server side at a fixed page size, so reaching back years means
    | many requests per index. Unlike the per-symbol stock backfill this does
    | NOT need a drip/budget subsystem: there are ~18 indices total (a fixed,
    | small vocabulary — see IndexCatalog) rather than ~300 scrips with unknown
    | gaps, so one bounded pass comfortably finishes in one run.
    |
    */

    'index_backfill' => [
        // Default window for `nepse:indices --backfill`. 5 years matches
        // backfill.target_years above; pass --days=0 for "since inception".
        'default_days' => (int) env('NEPSE_INDEX_BACKFILL_DAYS', 1825),

        // Window BackfillIndexHistoryJob's periodic top-up asks for. Small on
        // purpose: after the first full backfill this is a catch-up for
        // whatever sessions happened since the last run, not a re-fetch of
        // years of already-stored history.
        'top_up_days' => (int) env('NEPSE_INDEX_BACKFILL_TOPUP_DAYS', 30),

        // Cron expression for the top-up job. Weekly and off-hours, like the
        // fundamentals crawl — index history changes by at most one session a
        // day and there is no need to compete with the daily price scrapes.
        'schedule' => env('NEPSE_INDEX_BACKFILL_SCHEDULE', '30 2 * * 5'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule (cron expressions)
    |--------------------------------------------------------------------------
    |
    | NEPSE closes at 15:00 NPT; published price tables settle shortly after.
    |
    | These used to read `* * 0-4` (Sunday-Thursday) baked into the cron
    | expression itself. That is exactly the mistake this whole feature exists
    | to avoid: NEPSE's trading week has already changed once (it moved to
    | Monday-Friday) and a cron field cannot be effective-dated, so a fixed
    | weekday restriction would silently stop scraping on a real trading day
    | after the next change and keep running pointlessly on a day that stopped
    | being one. The schedules below therefore run every day; a run on a
    | closed day simply observes and records "no data" (see
    | App\Services\Scraper\Backfill\MarketCalendarClassifier) instead of being
    | skipped by a guess. Downstream, non-scraping passes that only make
    | sense once today's prices exist (the analysis safety net, the portfolio
    | pass in routes/console.php) instead check whether today actually has a
    | bar before doing anything, rather than trusting the day of the week.
    |
    */

    'schedule' => [
        /*
         * Two crawls every day.
         *
         * 12:00 catches mid-session prices while the market is open (on a day
         * it is), so the dashboard is useful during trading hours rather than
         * only after the close. 15:00 is the close itself, and that run is
         * the one whose prices become the day's official record.
         *
         * Both write to the same (stock_id, trade_date) row, so the later run
         * simply overwrites the earlier one with settled figures — no duplicate
         * history, and no need to special-case the intraday pass.
         */
        'scrape' => array_values(array_filter(array_map(
            'trim',
            explode('|', (string) env('NEPSE_SCRAPE_SCHEDULE', '0 12 * * *|0 15 * * *'))
        ))),

        // Analysis runs once, after the close has settled. The job itself (or
        // its schedule guard - see routes/console.php) is responsible for
        // finding nothing to do on a day that turned out not to be a session.
        'analysis' => env('NEPSE_ANALYSIS_SCHEDULE', '30 15 * * *'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache TTLs (seconds)
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Capital gains tax
    |--------------------------------------------------------------------------
    |
    | Nepal splits the rate on the holding period, so the 365-day boundary is
    | worth a third of the bill. Configurable because the rates move with the
    | Finance Act and because an institution is charged flat.
    |
    */

    'capital_gains_tax' => [
        'default_investor_class' => env('NEPSE_CGT_INVESTOR_CLASS', 'individual'),

        'rates' => [
            'individual' => [
                'label' => 'Resident individual',
                'long_term_threshold_days' => (int) env('NEPSE_CGT_THRESHOLD_DAYS', 365),
                'short_term_rate_percent' => (float) env('NEPSE_CGT_SHORT_RATE', 7.5),
                'long_term_rate_percent' => (float) env('NEPSE_CGT_LONG_RATE', 5.0),
            ],
            'institution' => [
                'label' => 'Institution',
                'long_term_threshold_days' => (int) env('NEPSE_CGT_THRESHOLD_DAYS', 365),
                'short_term_rate_percent' => (float) env('NEPSE_CGT_INSTITUTION_RATE', 10.0),
                'long_term_rate_percent' => (float) env('NEPSE_CGT_INSTITUTION_RATE', 10.0),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction costs
    |--------------------------------------------------------------------------
    |
    | The published SEBON/NEPSE schedule: tiered broker commission, a 0.015%
    | SEBON fee and a flat per-scrip DP charge. A round trip runs about
    | 0.6-1.2%, which is a material slice of a typical annual gain, so these
    | are computed rather than typed in.
    |
    | Rates change by regulation, so a schedule carries `effective_from` and
    | the newest one at or before the trade date wins — today's rates must not
    | be used to price a trade from two years ago.
    |
    | `application` is 'slab' (the whole turnover at the tier it lands in) or
    | 'progressive' (each band charged separately). The published schedule does
    | not say which, so slab is the default and this is a one-line switch.
    |
    */

    'transaction_costs' => [
        'default_instrument_class' => env('NEPSE_COST_DEFAULT_CLASS', 'equity'),

        'schedules' => [
            [
                'instrument_class' => 'equity',
                'effective_from' => '2000-01-01',
                'application' => env('NEPSE_COMMISSION_APPLICATION', 'slab'),
                'minimum_commission' => (float) env('NEPSE_MIN_COMMISSION', 10.0),
                'sebon_fee_percent' => (float) env('NEPSE_SEBON_FEE_PERCENT', 0.015),
                'dp_charge' => (float) env('NEPSE_DP_CHARGE', 25.0),
                'dp_charge_on_buy' => true,
                'dp_charge_on_sell' => true,
                // `up_to` is the inclusive ceiling; null is the open band.
                'tiers' => [
                    ['up_to' => 50000, 'rate_percent' => 0.36],
                    ['up_to' => 500000, 'rate_percent' => 0.33],
                    ['up_to' => 2000000, 'rate_percent' => 0.31],
                    ['up_to' => 10000000, 'rate_percent' => 0.27],
                    ['up_to' => null, 'rate_percent' => 0.24],
                ],
            ],
            [
                'instrument_class' => 'mutual_fund',
                'effective_from' => '2000-01-01',
                'application' => env('NEPSE_COMMISSION_APPLICATION', 'slab'),
                'minimum_commission' => (float) env('NEPSE_MIN_COMMISSION', 10.0),
                'sebon_fee_percent' => (float) env('NEPSE_SEBON_FEE_PERCENT', 0.015),
                'dp_charge' => (float) env('NEPSE_DP_CHARGE', 25.0),
                'dp_charge_on_buy' => true,
                'dp_charge_on_sell' => true,
                'tiers' => [['up_to' => null, 'rate_percent' => 0.15]],
            ],
            [
                'instrument_class' => 'debenture',
                'effective_from' => '2000-01-01',
                'application' => env('NEPSE_COMMISSION_APPLICATION', 'slab'),
                'minimum_commission' => (float) env('NEPSE_MIN_COMMISSION', 10.0),
                'sebon_fee_percent' => 0.005,
                'dp_charge' => (float) env('NEPSE_DP_CHARGE', 25.0),
                'dp_charge_on_buy' => true,
                'dp_charge_on_sell' => true,
                'tiers' => [['up_to' => null, 'rate_percent' => 0.10]],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Marks
    |--------------------------------------------------------------------------
    |
    | How long a spot price (stocks.last_price, dated by last_traded_on) may
    | stand in for a series mark before it is reported as stale. It is never
    | suppressed — a stale mark is still the best available answer, it just
    | must not be mistaken for a measured one.
    |
    */

    'marks' => [
        'spot_stale_after_days' => (int) env('NEPSE_SPOT_MARK_STALE_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cash statement import
    |--------------------------------------------------------------------------
    |
    | Which account a broker statement's movements land in when the file does
    | not say. Deposits to a broker arrive from a bank, so bank is the default.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Corporate actions
    |--------------------------------------------------------------------------
    |
    | Book close, AGM, dividend, bonus, rights and IPO/FPO — the most
    | actionable calendar in this market. ShareSansar serves it as DataTables
    | JSON rather than rendered HTML.
    |
    | Two source quirks the scraper works around, repeated here because they
    | look like bugs otherwise:
    |
    |   1. Outside a session every feed answers `recordsTotal: 0` with HTTP
    |      200 — a crawl that looks successful and finds nothing. One page
    |      fetch first sets the host-wide cookie.
    |   2. A large `length` silently returns nothing: 50 yields 169 records,
    |      200 yields 0. DataTables' -1 ("all") is honoured and returns the
    |      whole table in one request, so leave page_length at -1.
    |
    */

    'corporate_actions' => [
        'page_length' => (int) env('NEPSE_CORPORATE_PAGE_LENGTH', -1),

        // IPO / FPO / rights / mutual fund / local / debenture / migrant / QII.
        'issue_feed_types' => [1, 2, 3, 4, 5, 7, 8, 9],

        'validation' => [
            // Cash + bonus must agree with the published total.
            'payout_tolerance_percent' => (float) env('NEPSE_CORPORATE_PAYOUT_TOLERANCE', 0.01),
            // Measured from the source's own published_date, not from now().
            'max_future_years' => (int) env('NEPSE_CORPORATE_MAX_FUTURE_YEARS', 5),
            // The issue feeds are a full archive back to about 2011.
            'max_past_years' => (int) env('NEPSE_CORPORATE_MAX_PAST_YEARS', 40),
        ],
    ],

    /*
    | Asserts a real BS quarter end, promoting a derived date from
    | `approximate` to `exact`, e.g. ['2082:3' => '2026-04-13']. Q1-Q3 end on
    | BS month boundaries that no formula gives; the dater interpolates between
    | the two fiscal-year boundaries NepaliFiscalYear does know, which lands
    | within about three days.
    */
    'fiscal_quarter_ends' => [
        // '2082:3' => '2026-04-13',
    ],

    'cash_import' => [
        'default_account' => env('NEPSE_CASH_IMPORT_ACCOUNT', 'bank'),
    ],

    'cache' => [
        'stock_list' => (int) env('CACHE_TTL_STOCK_LIST', 300),
        'stock_detail' => (int) env('CACHE_TTL_STOCK_DETAIL', 300),
        'signals' => (int) env('CACHE_TTL_SIGNALS', 900),
        'market_summary' => (int) env('CACHE_TTL_MARKET_SUMMARY', 300),
        'sectors' => (int) env('CACHE_TTL_SECTORS', 3600),

        /*
         * The market benchmark's risk section replays the portfolio at every
         * sampled interval — measured at ~200 queries for a two-year weekly
         * window — and sits on the ordinary 120/min tier. Its inputs (index
         * history, closes, the ledger) only move once a day after the scrape,
         * so it caches for longer than the market pages. An edit to the
         * portfolio bumps the version counter rather than waiting this out.
         */
        'benchmark' => (int) env('CACHE_TTL_BENCHMARK', 1800),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted reverse proxies
    |--------------------------------------------------------------------------
    | Comma-separated IPs/CIDRs allowed to set X-Forwarded-For. Empty (the
    | default) trusts nobody. Behind Cloudflare, list Cloudflare's published
    | ranges (cloudflare.com/ips) — never "*", which lets any client spoof its IP
    | when the origin is reachable directly.
    */
    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', '')),
    ))),
];
