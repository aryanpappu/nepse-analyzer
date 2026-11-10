<?php

declare(strict_types=1);

namespace App\Services\Portfolio;

use App\Enums\AcquisitionChannel;
use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The Eloquent seam for acquisition channels: read them, set them, infer them,
 * and keep the IPO capital ledger in step.
 *
 * The maths lives in {@see AcquisitionChannelDetector} and
 * {@see ChannelProfitCalculator}, both pure. This class only supplies evidence
 * and persists decisions.
 */
final class AcquisitionChannelService
{
    public function __construct(
        private readonly AcquisitionChannelDetector $detector,
        private readonly ChannelProfitCalculator $profit,
        private readonly IpoCapitalService $capital,
        private readonly HoldingCalculator $holdings,
        private readonly MarkSourceReconciler $marks = new MarkSourceReconciler,
    ) {}

    /**
     * Every acquiring transaction, with its channel and what is known about it.
     *
     * Disposals are excluded: a sale acquires nothing and inherits the channel
     * of the lots it consumes, so offering a channel control on one would
     * invite a contradiction.
     *
     * @return array<string, mixed>
     */
    public function overview(Portfolio $portfolio): array
    {
        $transactions = $this->acquisitions($portfolio);
        $recorded = $this->capital->recordedFor(
            $portfolio,
            $transactions->map(static fn (PortfolioTransaction $t): int => (int) $t->id)->all(),
        );
        $traded = $this->brokerTradedSymbols($portfolio);

        /** @var list<array<string, mixed>> $rows */
        $rows = $transactions->map(function (PortfolioTransaction $t) use ($recorded, $traded): array {
            $channel = AcquisitionChannel::parse($t->acquisition_channel);
            $symbol = (string) ($t->stock->symbol ?? '');

            $proposal = $this->detector->detect([
                'type' => $t->type,
                'price' => (float) $t->price,
                'symbol' => $symbol,
            ], $traded);

            $capital = $this->capital->capitalFor($t);

            return [
                'id' => $t->id,
                'symbol' => $symbol,
                'type' => is_string($t->type) ? $t->type : $t->type->value,
                'quantity' => (float) $t->quantity,
                'price' => (float) $t->price,
                'transacted_on' => Carbon::parse($t->transacted_on)->toDateString(),
                'notes' => $t->notes,

                'channel' => $channel->value,
                'channel_label' => $channel->label(),
                'channel_short_label' => $channel->shortLabel(),
                'channel_basis' => $t->channel_basis,
                'is_confirmed' => $t->channel_confirmed_at !== null,
                'confirmed_at' => $t->channel_confirmed_at === null
                    ? null
                    : Carbon::parse($t->channel_confirmed_at)->toIso8601String(),

                // What detection would say, so the UI can offer it without
                // having applied it.
                'suggested_channel' => $proposal['channel']->value,
                'suggested_basis' => $proposal['basis'],
                'suggested_confidence' => $proposal['confidence'],
                'differs_from_suggestion' => $proposal['channel'] !== $channel
                    && $proposal['channel'] !== AcquisitionChannel::Unknown,

                'funded_outside_broker' => $channel->fundedOutsideBroker(),
                'capital' => $capital,
                'capital_recorded' => isset($recorded[(int) $t->id]),
                // A parcel whose price was never recorded cannot have its
                // capital added — reported rather than silently skipped.
                'capital_unknowable' => $capital === null,
                /*
                 * A market purchase whose settlement is nowhere in the ledger.
                 * "via broker" was asserting a cash movement that does not
                 * exist: these are the parcels bought through a second broker
                 * whose statement has not been imported, and together they are
                 * the whole of the contributed-capital shortfall. Saying so on
                 * the row is the only place the user can act on it.
                 */
                'cash_unaccounted' => $channel === AcquisitionChannel::Secondary
                    && ! str_starts_with((string) $t->notes, 'Broker statement'),
            ];
        })->all();

        return [
            'transactions' => $rows,
            'broker_traded_symbols' => $traded,
            'channels' => array_map(static fn (AcquisitionChannel $c): array => [
                'value' => $c->value,
                'label' => $c->label(),
                'short_label' => $c->shortLabel(),
                'description' => $c->description(),
                'funded_outside_broker' => $c->fundedOutsideBroker(),
                'is_market_priced' => $c->isMarketPriced(),
            ], AcquisitionChannel::ordered()),
            'capital_summary' => $this->capital->summary($portfolio),
            'unconfirmed_count' => count(array_filter($rows, static fn (array $r): bool => ! $r['is_confirmed'])),
            'note' => $traded === []
                ? 'No broker statement has been imported, so nothing can be inferred from a par price — '
                    .'there is nothing for a scrip to be absent from. Import one to enable detection.'
                : null,
        ];
    }

    /**
     * Set a channel by hand. This is final: it stamps `channel_confirmed_at`,
     * so later detection runs leave it alone.
     */
    /**
     * Set how a parcel was acquired, and optionally what it cost.
     *
     * The price is here rather than on a general transaction edit because the
     * two decisions are the same decision. Hypothetically, two parcels could
     * be sold by the broker statement but never bought in it — IPO lots and
     * bonus issues from CDSC — and sit at zero cost. Zero is misleading: they contribute nothing to the money-weighted return,
     * so they read as free shares, and their whole sale proceeds count as gain,
     * which overstates the capital-gains bill.
     *
     * Zero is not corrected automatically. A bonus share genuinely costs
     * nothing and an IPO lot costs par, and only the holder knows which a given
     * parcel was — inventing par for both would replace a visible wrong number
     * with an invisible one.
     *
     * @param  float|null  $price  Per-unit cost. Null leaves it untouched.
     */
    public function setChannel(
        Portfolio $portfolio,
        PortfolioTransaction $transaction,
        AcquisitionChannel $channel,
        ?float $price = null,
    ): PortfolioTransaction {
        if ((int) $transaction->portfolio_id !== (int) $portfolio->id) {
            throw new RuntimeException('That transaction is not in this portfolio.');
        }

        return DB::transaction(function () use ($portfolio, $transaction, $channel, $price): PortfolioTransaction {
            $changes = [
                'acquisition_channel' => $channel->value,
                'channel_basis' => AcquisitionChannelDetector::BASIS_MANUAL,
                'channel_confirmed_at' => Carbon::now(),
            ];

            if ($price !== null) {
                $changes['price'] = round($price, 4);
            }

            $transaction->update($changes);

            if ($price !== null) {
                /*
                 * Cost basis is a function of the ledger, so the position has
                 * to be replayed rather than patched — average cost, invested
                 * total and every downstream return figure derive from it.
                 */
                $this->holdings->rebuild($portfolio);
            }

            // The tick and the untick: reconcile() adds the subscription cost
            // for IPO and rights, and removes it again for anything else. Runs
            // after the price change, because the subscription amount it
            // records is derived from exactly that price.
            $this->capital->reconcile($portfolio);

            return $transaction->fresh();
        });
    }

    /**
     * Infer channels for everything nobody has confirmed.
     *
     * @return array<string, mixed>
     */
    public function detectAll(Portfolio $portfolio): array
    {
        $traded = $this->brokerTradedSymbols($portfolio);

        if ($traded === []) {
            return [
                'applied' => 0,
                'skipped_confirmed' => 0,
                'undetermined' => 0,
                'note' => 'No broker statement has been imported, so a par price proves nothing. '
                    .'Nothing was inferred.',
            ];
        }

        $applied = 0;
        $confirmed = 0;
        $undetermined = 0;

        DB::transaction(function () use ($portfolio, $traded, &$applied, &$confirmed, &$undetermined): void {
            foreach ($this->acquisitions($portfolio) as $transaction) {
                if ($transaction->channel_confirmed_at !== null) {
                    $confirmed++;

                    continue;
                }

                $proposal = $this->detector->detect([
                    'type' => $transaction->type,
                    'price' => (float) $transaction->price,
                    'symbol' => (string) ($transaction->stock->symbol ?? ''),
                ], $traded);

                if (! $this->detector->mayOverwrite(null, $proposal['channel'])) {
                    $undetermined++;

                    continue;
                }

                $transaction->update([
                    'acquisition_channel' => $proposal['channel']->value,
                    'channel_basis' => $proposal['basis'],
                ]);

                $applied++;
            }

            $this->capital->reconcile($portfolio);
        });

        return [
            'applied' => $applied,
            'skipped_confirmed' => $confirmed,
            'undetermined' => $undetermined,
            'note' => $undetermined > 0
                ? sprintf(
                    '%d parcel(s) could not be determined from the evidence and stay unknown rather than being guessed.',
                    $undetermined,
                )
                : null,
        ];
    }

    /**
     * Profit split by channel over the open positions.
     *
     * Open quantity is attributed to channels by consuming lots FIFO, which is
     * the same convention `PerformanceService` uses for disposals — so a
     * position part-bought as an IPO and part on market splits the way its
     * remaining units were actually acquired.
     *
     * @return array<string, mixed>
     */
    public function profitSplit(Portfolio $portfolio, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();
        $parcels = [];

        foreach ($this->remainingLots($portfolio) as $lot) {
            $mark = $this->markFor($lot['stock_id'], $asOf);

            $parcels[] = [
                'channel' => $lot['channel'],
                'symbol' => $lot['symbol'],
                'quantity' => $lot['quantity'],
                'cost_basis' => round($lot['quantity'] * $lot['price'], 2),
                'market_value' => $mark->valueOf($lot['quantity']),
            ];
        }

        return $this->profit->split($parcels) + ['as_of' => $asOf->toDateString()];
    }

    /**
     * One holding's mark, decided by the shared precedence rather than by this
     * class picking a source of its own.
     *
     * `MarkSourceReconciler` is pure, so the candidate prices are fetched here
     * and handed to it: a dated series close or fund NAV at or before the
     * date, else a dated spot price. An undated spot price is refused, and a
     * mark that cannot be established returns a null price so the parcel is
     * excluded and named rather than valued at cost.
     */
    private function markFor(int $stockId, Carbon $asOf): HoldingMark
    {
        $close = DB::table('stock_prices')
            ->where('stock_id', $stockId)
            ->whereDate('trade_date', '<=', $asOf->toDateString())
            ->orderByDesc('trade_date')
            ->first(['close', 'trade_date']);

        $nav = $close === null
            ? DB::table('fund_navs')
                ->where('stock_id', $stockId)
                ->whereDate('nav_date', '<=', $asOf->toDateString())
                ->orderByDesc('nav_date')
                ->first(['nav', 'nav_date'])
            : null;

        $stock = DB::table('stocks')->where('id', $stockId)->first(['last_price', 'last_traded_on']);

        return $this->marks->reconcile(
            $stockId,
            $asOf,
            $close !== null ? (float) $close->close : ($nav !== null ? (float) $nav->nav : null),
            $close !== null
                ? Carbon::parse((string) $close->trade_date)
                : ($nav !== null ? Carbon::parse((string) $nav->nav_date) : null),
            $close !== null ? HoldingMark::SOURCE_CLOSE : ($nav !== null ? HoldingMark::SOURCE_NAV : null),
            $stock?->last_price === null ? null : (float) $stock->last_price,
            $stock?->last_traded_on === null ? null : Carbon::parse((string) $stock->last_traded_on),
        );
    }

    /**
     * Remaining lots per symbol after FIFO disposal, carrying their channel.
     *
     * @return list<array{symbol: string, stock_id: int, channel: string, quantity: float, price: float}>
     */
    private function remainingLots(Portfolio $portfolio): array
    {
        $bySymbol = [];

        $transactions = PortfolioTransaction::with('stock')
            ->where('portfolio_id', $portfolio->id)
            ->orderBy('transacted_on')
            ->orderBy('id')
            ->get();

        foreach ($transactions as $transaction) {
            $symbol = (string) ($transaction->stock->symbol ?? '');
            $type = is_string($transaction->type) ? $transaction->type : $transaction->type->value;
            $quantity = round((float) $transaction->quantity, 2);

            if ($symbol === '' || $quantity <= 0.0) {
                continue;
            }

            $bySymbol[$symbol] ??= [];

            if ($type === 'sell') {
                $remaining = $quantity;

                foreach ($bySymbol[$symbol] as $index => $lot) {
                    if ($remaining <= 0.0) {
                        break;
                    }

                    $take = min($lot['quantity'], $remaining);
                    $bySymbol[$symbol][$index]['quantity'] = round($lot['quantity'] - $take, 2);
                    $remaining = round($remaining - $take, 2);
                }

                continue;
            }

            $bySymbol[$symbol][] = [
                'symbol' => $symbol,
                'stock_id' => (int) $transaction->stock_id,
                'channel' => AcquisitionChannel::parse($transaction->acquisition_channel)->value,
                'quantity' => $quantity,
                'price' => round((float) $transaction->price, 4),
            ];
        }

        $lots = [];

        foreach ($bySymbol as $symbolLots) {
            foreach ($symbolLots as $lot) {
                if ($lot['quantity'] > 0.0) {
                    $lots[] = $lot;
                }
            }
        }

        return $lots;
    }

    /**
     * @return Collection<int, PortfolioTransaction>
     */
    private function acquisitions(Portfolio $portfolio): Collection
    {
        return PortfolioTransaction::with('stock')
            ->where('portfolio_id', $portfolio->id)
            ->acquisitions()
            ->orderByDesc('transacted_on')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Scrips a broker statement accounts for — positive evidence of a floor
     * trade, and the only thing that makes a par price meaningful.
     *
     * @return list<string>
     */
    private function brokerTradedSymbols(Portfolio $portfolio): array
    {
        return DB::table('portfolio_transactions')
            ->join('stocks', 'stocks.id', '=', 'portfolio_transactions.stock_id')
            ->where('portfolio_transactions.portfolio_id', $portfolio->id)
            ->where('portfolio_transactions.notes', 'like', 'Broker statement%')
            ->distinct()
            ->pluck('stocks.symbol')
            ->map(static fn (mixed $s): string => strtoupper(trim((string) $s)))
            ->values()
            ->all();
    }
}
