<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Models\StockPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What a new user still has to do before the app can tell them anything.
 *
 * After `nepse:setup` the market data is there, but the pages that matter —
 * performance, attribution, tax — are all about *your* money, and none of it
 * exists yet. A newcomer lands on a working app full of empty tiles with no
 * indication of whether something is broken or simply not filled in.
 *
 * So this answers that directly: each step reports whether it is done, what it
 * gets you, and where to go. Every check is derived from the database rather
 * than from a stored "has_onboarded" flag, because a flag lies the moment data
 * is imported another way — through the CLI, or by restoring a backup.
 */
class OnboardingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $portfolio = $user === null
            ? null
            : Portfolio::where('user_id', $user->id)->orderBy('id')->first();

        $steps = [
            $this->portfolioStep($portfolio),
            $this->holdingsStep($portfolio),
            $this->cashStep($portfolio),
            $this->channelStep($portfolio),
            $this->benchmarkStep($user?->benchmark_fd_rate),
        ];

        // Shared pipeline setup is an operator task, never a public user's
        // first step: they can create and import a portfolio while data loads.
        if ($user?->isAdmin()) {
            array_unshift($steps, $this->marketDataStep());
        }

        $done = count(array_filter($steps, static fn (array $s): bool => $s['done']));

        // The first thing still outstanding — the one question the card should
        // answer, rather than presenting six equal options.
        $next = null;

        foreach ($steps as $step) {
            if (! $step['done']) {
                $next = $step['key'];

                break;
            }
        }

        return response()->json([
            'data' => [
                'steps' => $steps,
                'completed' => $done,
                'total' => count($steps),
                'complete' => $done === count($steps),
                'next' => $next,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function marketDataStep(): array
    {
        $prices = StockPrice::query()->count();
        $indicators = DB::table('technical_indicators')->count();

        return [
            'key' => 'market-data',
            'title' => 'Load the market',
            'done' => $prices > 0 && $indicators > 0,
            'why' => 'Load available prices and indicators for market comparisons.',
            'how' => match (true) {
                $prices === 0 => 'Run php artisan nepse:setup in the backend directory.',
                $indicators === 0 => 'Indicators have not been calculated yet. Run php artisan nepse:indicators.',
                default => 'Nothing to do — the daily crawl keeps this current.',
            },
            'link' => null,
            'detail' => $prices === 0
                ? null
                : sprintf('%s price rows, %s indicator rows', number_format($prices), number_format($indicators)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function portfolioStep(?Portfolio $portfolio): array
    {
        return [
            'key' => 'portfolio',
            'title' => 'Create a portfolio',
            'done' => $portfolio !== null,
            'why' => 'A container for your holdings, trades and cash.',
            'how' => 'Create one on the Portfolio page.',
            'link' => '/portfolio',
            'detail' => $portfolio?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function holdingsStep(?Portfolio $portfolio): array
    {
        $count = $portfolio === null
            ? 0
            : DB::table('portfolio_transactions')->where('portfolio_id', $portfolio->id)->count();

        return [
            'key' => 'holdings',
            'title' => 'Bring in what you hold',
            'done' => $count > 0,
            'why' => 'Without your holdings there is nothing to value, compare or tax.',
            'how' => 'Import your two MeroShare CSV exports — one says what you hold, the other what you paid.',
            'link' => '/import',
            'detail' => $count === 0 ? null : sprintf('%d transaction(s) recorded', $count),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cashStep(?Portfolio $portfolio): array
    {
        $count = $portfolio === null
            ? 0
            : DB::table('cash_transactions')->where('portfolio_id', $portfolio->id)->count();

        return [
            'key' => 'cash',
            'title' => 'Record the money you put in',
            'done' => $count > 0,
            // This is the step people skip, and skipping it does not look like
            // an error — it quietly makes every timing-aware figure meaningless.
            'why' => 'Your annualised return, and both benchmarks, compare against the money you actually '
                .'contributed and when. Without it they have nothing real to measure.',
            'how' => 'Upload your broker account statement — the .xlsx your broker gives you.',
            'link' => '/cash-import',
            'detail' => $count === 0 ? null : sprintf('%d cash movement(s)', $count),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function channelStep(?Portfolio $portfolio): array
    {
        if ($portfolio === null) {
            $unknown = 0;
            $total = 0;
        } else {
            /*
             * The same set the IPO/Traded screen lists — acquisitions only. A
             * sale has no channel, and counting sales here made the checklist
             * demand work on rows the screen never showed.
             */
            $total = PortfolioTransaction::where('portfolio_id', $portfolio->id)->acquisitions()->count();
            $unknown = PortfolioTransaction::where('portfolio_id', $portfolio->id)
                ->acquisitions()
                ->where('acquisition_channel', 'unknown')
                ->count();
        }

        return [
            'key' => 'channels',
            'title' => 'Say how you acquired each parcel',
            // Nothing to classify is not an outstanding task.
            'done' => $total > 0 && $unknown === 0,
            'why' => 'An IPO allotted at par by lottery and a scrip you chose to buy are different '
                .'activities. Only the second says anything about your judgement.',
            'how' => 'Open IPO / Traded and use "Suggest from evidence", then correct anything it could not settle.',
            'link' => '/channels',
            'detail' => $total === 0 ? null : sprintf('%d of %d still unclassified', $unknown, $total),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function benchmarkStep(mixed $rate): array
    {
        return [
            'key' => 'benchmark',
            'title' => 'Set your deposit rate',
            'done' => $rate !== null,
            'why' => 'The honest question is whether this beat leaving the money in *your* bank. Rates '
                .'differ by bank and by negotiation.',
            'how' => 'Set it on the Settings page. Until then the configured default is used.',
            'link' => '/settings',
            'detail' => $rate === null ? null : sprintf('%s%%', $rate),
        ];
    }
}
