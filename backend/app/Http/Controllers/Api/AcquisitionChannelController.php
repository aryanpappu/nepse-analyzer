<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AcquisitionChannel;
use App\Http\Controllers\Concerns\AuthorizesPortfolioAccess;
use App\Http\Controllers\Controller;
use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Services\Portfolio\AcquisitionChannelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * How each parcel was acquired, and what that does to the profit figures.
 *
 * An IPO allotted at par by lottery and a scrip somebody chose to buy are
 * different activities; only the second is evidence about the investor. `type`
 * cannot tell them apart because `buy` covers both, so the channel is recorded
 * per transaction and can be corrected by hand.
 */
class AcquisitionChannelController extends Controller
{
    use AuthorizesPortfolioAccess;

    public function __construct(
        private readonly AcquisitionChannelService $channels,
    ) {}

    /**
     * Every acquiring parcel with its channel, what detection would propose,
     * and whether its subscription capital is on the ledger.
     */
    public function index(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        return response()->json(['data' => $this->channels->overview($portfolio)]);
    }

    /**
     * Set one parcel's channel.
     *
     * Final: it stamps `channel_confirmed_at`, so later detection runs leave it
     * alone. Marking a parcel IPO or rights also writes the subscription cost
     * that never passed through the broker; moving it away removes that row
     * again.
     */
    public function update(
        Request $request,
        Portfolio $portfolio,
        PortfolioTransaction $transaction,
    ): JsonResponse {
        $this->authorizeOwner($request, $portfolio);
        // Message-less by design; see the note in CashController.
        abort_unless($transaction->portfolio_id === $portfolio->id, 404);

        $validated = $request->validate([
            'channel' => ['required', 'string', Rule::in(AcquisitionChannel::values())],
            /*
             * Optional, and `0` is a legitimate value: a bonus share really did
             * cost nothing. `nullable` rather than `sometimes` so clearing the
             * field leaves the stored price alone instead of zeroing it.
             */
            'price' => ['sometimes', 'nullable', 'numeric', 'gte:0', 'max:9999999'],
        ]);

        try {
            $this->channels->setChannel(
                $portfolio,
                $transaction,
                AcquisitionChannel::from($validated['channel']),
                isset($validated['price']) ? (float) $validated['price'] : null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->forget($portfolio);

        return response()->json([
            'message' => 'Channel set.',
            'data' => $this->channels->overview($portfolio),
        ]);
    }

    /**
     * Infer channels for every parcel nobody has confirmed.
     *
     * Proposes only where the evidence supports it — anything undetermined
     * stays `unknown` rather than being guessed, and the response says how
     * many.
     */
    public function detect(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        $result = $this->channels->detectAll($portfolio);

        $this->forget($portfolio);

        return response()->json([
            'message' => sprintf('%d parcel(s) classified.', $result['applied']),
            'data' => $result + ['overview' => $this->channels->overview($portfolio)],
        ]);
    }

    /**
     * Profit split by channel over the open positions.
     *
     * The number that matters is `market_priced`: shares bought on the floor at
     * a chosen price. Everything else was handed over at a price nobody picked.
     */
    public function profit(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizeOwner($request, $portfolio);

        $validated = $request->validate([
            'as_of' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $asOf = isset($validated['as_of']) ? Carbon::parse($validated['as_of']) : null;

        $key = sprintf(
            'portfolio:%d:channel-profit:v%d:%s',
            $portfolio->id,
            // Reuses the benchmark counter: a trade or a cash correction
            // changes this answer too.
            PerformanceController::channelProfitVersion($portfolio),
            $asOf?->toDateString() ?? 'today',
        );

        $data = Cache::remember(
            $key,
            (int) config('nepse.cache.benchmark', 1800),
            fn (): array => $this->channels->profitSplit($portfolio, $asOf),
        );

        return response()->json(['data' => $data]);
    }

    private function forget(Portfolio $portfolio): void
    {
        Cache::forget("portfolio:summary:{$portfolio->id}");
        // Setting a channel can add or remove a ledger row, which moves the
        // cash balance and therefore every money-weighted figure.
        PerformanceController::invalidateBenchmark($portfolio);
    }
}
