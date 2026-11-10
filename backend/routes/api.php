<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AccountDataController;
use App\Http\Controllers\Api\AcquisitionChannelController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashController;
use App\Http\Controllers\Api\CashPlacementController;
use App\Http\Controllers\Api\CashStatementImportController;
use App\Http\Controllers\Api\ComparisonController;
use App\Http\Controllers\Api\CorporateActionController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\HoldingController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PasswordRecoveryController;
use App\Http\Controllers\Api\PerformanceController;
use App\Http\Controllers\Api\PlaybookController;
use App\Http\Controllers\Api\PortfolioAnalysisController;
use App\Http\Controllers\Api\PortfolioController;
use App\Http\Controllers\Api\PortfolioImportController;
use App\Http\Controllers\Api\SignalController;
use App\Http\Controllers\Api\SiteInformationController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\TransactionCostController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsurePortfolioBelongsToUser;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public market data
|--------------------------------------------------------------------------
|
| Read-only and cacheable. Rate limited more generously than the write and
| AI endpoints below.
|
*/

Route::middleware('throttle:120,1')->group(function (): void {
    Route::get('/site-information', SiteInformationController::class);
    Route::get('/health', [MarketController::class, 'health']);
    Route::get('/market/summary', [MarketController::class, 'summary']);
    Route::get('/sectors', [MarketController::class, 'sectors']);

    Route::get('/stocks', [StockController::class, 'index']);
    Route::get('/stocks/{stock}', [StockController::class, 'show']);
    Route::get('/stocks/{stock}/history', [StockController::class, 'history']);
    Route::get('/stocks/{stock}/signals', [SignalController::class, 'forStock']);
    Route::get('/stocks/{stock}/peers', [ComparisonController::class, 'peers']);

    // Head-to-head fundamentals. Registered before the {stock} wildcard would
    // ever be reached for these paths, and cheap enough to sit in the public
    // read tier — it reads only what the crawl already stored.
    Route::get('/compare', [ComparisonController::class, 'compare']);
    Route::get('/compare/metrics', [ComparisonController::class, 'metrics']);

    /*
     * What a trade costs. Public because it is pure arithmetic over the
     * published schedule — no portfolio, no database — and quoting a cost
     * before placing an order is the point of it.
     */
    Route::get('/transaction-costs/quote', [TransactionCostController::class, 'quote']);

    /*
     * The corporate calendar. Public because it is the same for everybody and
     * touches no portfolio; ?portfolio= only flags which rows affect the
     * caller's own holdings, authorised separately.
     */
    Route::get('/corporate-actions', [CorporateActionController::class, 'index']);

    Route::get('/signals', [SignalController::class, 'index']);

    /*
     * Profile playbook. Rule-based, so it is free to serve and lives here
     * rather than behind the AI rate limit: the whole market can be re-ranked
     * for a different investor profile without spending anything.
     */
    Route::get('/profiles', [PlaybookController::class, 'profiles']);
    Route::get('/playbook', [PlaybookController::class, 'index']);
    Route::get('/stocks/{stock}/playbook', [PlaybookController::class, 'forStock']);
});

/*
|--------------------------------------------------------------------------
| Manual price refresh
|--------------------------------------------------------------------------
|
| The "fetch now" button. Rate limited hard and additionally cooled down in the
| controller, because it triggers a crawl of a free public site.
|
*/

Route::post('/market/refresh', [MarketController::class, 'refresh'])
    ->middleware(['auth:sanctum', EnsureAdmin::class, 'throttle:6,1']);

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function (): void {
    // Tight limits: these are the credential-handling endpoints.
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/forgot-password', [PasswordRecoveryController::class, 'requestLink'])->middleware('throttle:5,1');
    Route::post('/reset-password', [PasswordRecoveryController::class, 'reset'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])->middleware('throttle:3,1');
        Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware(['signed:relative', 'throttle:10,1'])->name('verification.verify');
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        // Name, email, password, and the comparison settings that decide what
        // the deposit and index benchmarks measure against.
        Route::patch('/me', [AuthController::class, 'updateMe']);
        Route::post('/export', [AccountDataController::class, 'export'])->middleware('throttle:5,1');
        Route::delete('/me', [AccountDataController::class, 'destroy'])->middleware('throttle:5,1');
    });
});

/*
|--------------------------------------------------------------------------
| Authenticated: portfolio management
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {

    /*
     * What is still missing before the app can say anything useful.
     *
     * Derived from the database on every read rather than from a stored
     * "onboarded" flag, because data arrives by routes the UI never sees — the
     * CLI importers, a restored backup — and a flag would then be wrong in the
     * one direction that matters, telling a user to redo work already done.
     */
    Route::get('/onboarding', [OnboardingController::class, 'index']);

    /*
     * show/update/destroy bind a {portfolio}, so they need the same
     * before-validation ownership gate as the nested group below. index/store
     * carry no binding and the middleware passes them straight through.
     */
    Route::apiResource('portfolios', PortfolioController::class)
        ->middleware(EnsurePortfolioBelongsToUser::class);

    /*
     * Ownership is authorized by middleware rather than only in each
     * controller, so it runs *before* any FormRequest validates. Otherwise a
     * request to someone else's portfolio with an invalid body answers 422,
     * which reveals that the portfolio id exists before authorization has been
     * evaluated.
     */
    Route::prefix('portfolios/{portfolio}')
        ->middleware(EnsurePortfolioBelongsToUser::class)
        ->group(function (): void {
            // Holdings and the transaction ledger
            Route::get('/holdings', [HoldingController::class, 'index']);
            Route::post('/transactions', [HoldingController::class, 'store']);
            Route::patch('/holdings/{holding}', [HoldingController::class, 'update']);
            Route::delete('/holdings/{holding}', [HoldingController::class, 'destroy']);
            Route::post('/rebuild', [HoldingController::class, 'rebuild']);

            // Cash: in hand and in bank. A mis-entered movement is amended in
            // place or voided rather than offset with a second entry — the running
            // balances are then replayed from the ledger, never patched.
            Route::get('/cash', [CashController::class, 'index']);
            Route::post('/cash', [CashController::class, 'store']);
            Route::patch('/cash/{transaction}', [CashController::class, 'update']);
            Route::delete('/cash/{transaction}', [CashController::class, 'destroy']);

            /*
             * Interest-bearing placements — money parked in a fixed deposit rather
             * than held as idle cash. Accrued interest is computed on read from the
             * placement's own terms, on the same Actual/365 basis as the "vs FD"
             * benchmark in /performance, so a real deposit and the hypothetical one
             * are never quoted on different arithmetic.
             *
             * /bulk is registered before the {cashPlacement} wildcard so the
             * literal segment always wins.
             */
            Route::get('/cash-placements', [CashPlacementController::class, 'index']);
            Route::post('/cash-placements', [CashPlacementController::class, 'store']);
            Route::post('/cash-placements/bulk', [CashPlacementController::class, 'bulkStore']);
            Route::get('/cash-placements/{cashPlacement}', [CashPlacementController::class, 'show']);
            Route::patch('/cash-placements/{cashPlacement}', [CashPlacementController::class, 'update']);
            Route::delete('/cash-placements/{cashPlacement}', [CashPlacementController::class, 'destroy']);

            /*
             * Closing a deposit is not an edit: it stops accrual and moves money
             * back into the cash ledger, so it gets its own verb rather than
             * riding on a PATCH of `status`.
             */
            Route::post('/cash-placements/{cashPlacement}/withdraw', [CashPlacementController::class, 'withdraw']);

            // What to do about each position today, and what each year earned.
            // Both read the ledger and the stored verdicts, so no AI spend.
            Route::get('/actions', [PerformanceController::class, 'actions']);
            Route::get('/performance', [PerformanceController::class, 'performance']);

            /*
             * Market benchmark. Its own endpoint because the risk metrics replay
             * the portfolio at every sampled interval — far more work than the
             * year buckets — and ?sections= lets a page ask only for what it
             * renders. Rule-based and free to serve; no AI spend.
             */
            Route::get('/benchmark', [PerformanceController::class, 'benchmark']);

            /*
             * Why that verdict — per-holding contributions (constrained to sum to
             * the total return) and each sector against its own sub-index.
             */
            Route::get('/attribution', [PerformanceController::class, 'attribution']);

            // Estimated CGT on realised disposals; the 365-day boundary is worth
            // a third of the bill, so the day count is reported per disposal.
            Route::get('/capital-gains', [PerformanceController::class, 'capitalGains']);

            /*
             * IPO or traded. `type` cannot answer it — `buy` covers an allotment
             * and a floor purchase alike — so the channel is per transaction and
             * correctable by hand. Setting it to ipo/rights also records the
             * subscription cost that never passed through the broker.
             */
            Route::get('/channels', [AcquisitionChannelController::class, 'index']);
            Route::patch('/channels/{transaction}', [AcquisitionChannelController::class, 'update']);
            Route::post('/channels/detect', [AcquisitionChannelController::class, 'detect']);
            Route::get('/channels/profit', [AcquisitionChannelController::class, 'profit']);

            /*
             * Broker cash statement import. The two MeroShare imports answer what
             * you hold and what you paid; this answers what money you actually put
             * in, without which XIRR and both benchmarks compute against
             * placeholder cash. Staged and reviewable — nothing reaches the ledger
             * until commit.
             */
            Route::get('/cash-imports', [CashStatementImportController::class, 'index']);
            Route::post('/cash-imports', [CashStatementImportController::class, 'store']);
            Route::get('/cash-imports/{import}', [CashStatementImportController::class, 'show']);
            Route::patch('/cash-imports/{import}/rows', [CashStatementImportController::class, 'updateRow']);
            Route::post('/cash-imports/{import}/commit', [CashStatementImportController::class, 'commit']);
            Route::delete('/cash-imports/{import}', [CashStatementImportController::class, 'destroy']);

            // Cached AI output — free to read
            Route::get('/recommendations', [PortfolioAnalysisController::class, 'latestRecommendations']);

            /*
             * MeroShare import wizard.
             *
             * Two steps by design: the "My Portfolio" export establishes what is
             * held, then the WACC export supplies what it cost. Rows stay editable
             * until commit, so a missing or wrong cost can be corrected first.
             */
            Route::prefix('imports')->group(function (): void {
                Route::get('/', [PortfolioImportController::class, 'index']);
                Route::post('/', [PortfolioImportController::class, 'store']);
                Route::get('/{import}', [PortfolioImportController::class, 'show']);
                Route::post('/{import}/wacc', [PortfolioImportController::class, 'attachWacc']);
                Route::patch('/{import}/rows', [PortfolioImportController::class, 'updateRow']);
                Route::post('/{import}/commit', [PortfolioImportController::class, 'commit']);
                Route::delete('/{import}', [PortfolioImportController::class, 'destroy']);
            });
        });

    // Manual stock/scrip maintenance
    Route::post('/stocks', [StockController::class, 'store'])->middleware(EnsureAdmin::class);
    Route::patch('/stocks/{stock}', [StockController::class, 'update'])->middleware(EnsureAdmin::class);
    Route::delete('/stocks/{stock}', [StockController::class, 'destroy'])->middleware(EnsureAdmin::class);
});

/*
|--------------------------------------------------------------------------
| Authenticated: AI analysis
|--------------------------------------------------------------------------
|
| Every route here spends money on an API call, so it gets its own, much
| tighter rate limit than the ordinary authenticated endpoints.
|
*/

Route::middleware(['auth:sanctum', 'throttle:20,1'])->group(function (): void {
    Route::post('/portfolios/{portfolio}/analyze', [PortfolioAnalysisController::class, 'analyze']);
    Route::post('/portfolios/{portfolio}/recommend', [PortfolioAnalysisController::class, 'recommend']);
    Route::post('/stocks/{stock}/analyze', [SignalController::class, 'analyze'])->middleware(EnsureAdmin::class);

    // The computed table at GET /compare stays free; only the weighted verdict
    // costs a call, so a user can browse comparisons without spending.
    Route::post('/compare/analyze', [ComparisonController::class, 'analyze']);
});
