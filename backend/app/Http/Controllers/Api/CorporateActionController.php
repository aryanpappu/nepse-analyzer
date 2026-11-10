<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CorporateAction;
use App\Models\Portfolio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The corporate calendar: book closes, AGMs, dividends, rights and issues.
 *
 * The most actionable data in this market — a book close decides whether you
 * receive a dividend or a bonus at all, and it is a date, not a judgement.
 *
 * Public and cacheable: it is the same for everybody and touches no portfolio.
 * A caller may pass `?portfolio=` to have the rows that affect their own
 * holdings flagged, which is authorised separately and never widens what is
 * returned.
 */
class CorporateActionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'from' => ['nullable', 'date'],
            'type' => ['nullable', 'string', 'max:24'],
            'symbol' => ['nullable', 'string', 'max:20'],
            'portfolio' => ['nullable', 'integer'],
        ]);

        $days = (int) ($validated['days'] ?? 30);
        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : Carbon::today();

        // The `integer` rule validates but does not cast, so a query string
        // arrives as "1" and a typed ?int parameter rejects it.
        $mine = $this->holdingSymbols(
            $request,
            isset($validated['portfolio']) ? (int) $validated['portfolio'] : null,
        );

        $key = sprintf(
            'corporate-actions:%s:%d:%s:%s',
            $from->toDateString(),
            $days,
            $validated['type'] ?? '-',
            strtoupper($validated['symbol'] ?? '-'),
        );

        $payload = Cache::remember(
            $key,
            (int) config('nepse.cache.sectors', 3600),
            function () use ($from, $days, $validated): array {
                $bookCloses = CorporateAction::with('stock')
                    ->bookClosingBetween($from, $days)
                    ->when(
                        isset($validated['type']),
                        static fn ($q) => $q->where('type', $validated['type']),
                    )
                    ->when(
                        isset($validated['symbol']),
                        fn ($q) => $q->whereHas(
                            'stock',
                            static fn ($s) => $s->where('symbol', strtoupper((string) $validated['symbol'])),
                        ),
                    )
                    ->get();

                return [
                    'book_closes' => $bookCloses->map(fn (CorporateAction $a): array => $this->serialize($a, $from))->all(),
                    'open_issues' => CorporateAction::with('stock')
                        ->openIssuesOn($from)
                        ->get()
                        ->map(fn (CorporateAction $a): array => $this->serialize($a, $from))
                        ->all(),
                    'meetings' => CorporateAction::with('stock')
                        ->meetingsFrom($from)
                        ->get()
                        ->map(fn (CorporateAction $a): array => $this->serialize($a, $from))
                        ->all(),
                ];
            },
        );

        // Flagging happens outside the cache: the calendar is shared, whose
        // holdings it touches is not.
        foreach (['book_closes', 'open_issues', 'meetings'] as $group) {
            $payload[$group] = array_map(
                static fn (array $row): array => $row + ['affects_me' => in_array($row['symbol'], $mine, true)],
                $payload[$group],
            );
        }

        $latest = CorporateAction::max('published_on');

        return response()->json([
            'data' => $payload + [
                'from' => $from->toDateString(),
                'days' => $days,
                'my_symbols' => $mine,
                'total_stored' => CorporateAction::count(),
                // So a stale calendar is visible rather than read as "nothing
                // is happening".
                'latest_published_on' => $latest === null ? null : Carbon::parse((string) $latest)->toDateString(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(CorporateAction $action, Carbon $from): array
    {
        $bookClose = $this->date($action->book_close_on);

        return [
            'id' => $action->id,
            'symbol' => (string) ($action->stock->symbol ?? ''),
            'name' => $action->stock->name ?? null,
            'type' => $action->type,
            'title' => $action->title,
            'book_close_on' => $bookClose?->toDateString(),
            // Negative means it has already passed — shown, not hidden, because
            // a book close that has gone is exactly what a user needs to know.
            'days_until_book_close' => $bookClose === null
                ? null
                : (int) $from->copy()->startOfDay()->diffInDays($bookClose->copy()->startOfDay(), false),
            'event_on' => $this->date($action->event_on)?->toDateString(),
            'closes_on' => $this->date($action->closes_on)?->toDateString(),
            'listing_on' => $this->date($action->listing_on)?->toDateString(),
            'cash_dividend_percent' => $action->cash_dividend_percent === null
                ? null : (float) $action->cash_dividend_percent,
            'bonus_percent' => $action->bonus_percent === null ? null : (float) $action->bonus_percent,
            'total_dividend_percent' => $action->total_dividend_percent === null
                ? null : (float) $action->total_dividend_percent,
            'ratio' => $action->ratio,
            'units' => $action->units,
            'issue_price' => $action->issue_price === null ? null : (float) $action->issue_price,
            'issue_manager' => $action->issue_manager,
            'fiscal_period' => $action->fiscal_period,
            'venue' => $action->venue,
            'agenda' => $action->agenda,
            'published_on' => $this->date($action->published_on)?->toDateString(),
        ];
    }

    /**
     * Normalise a model date attribute to a Carbon or null.
     *
     * These columns are cast on the model, so they are already Carbon at
     * runtime — but the call site should prove its own type rather than lean on
     * a cast that a later change could quietly drop.
     */
    private function date(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon ? $value : Carbon::parse((string) $value);
    }

    /**
     * The caller's own open scrips, when they ask and may see them.
     *
     * @return list<string>
     */
    private function holdingSymbols(Request $request, ?int $portfolioId): array
    {
        if ($portfolioId === null) {
            return [];
        }

        $user = $request->user();
        $portfolio = Portfolio::find($portfolioId);

        if ($user === null || $portfolio === null) {
            return [];
        }

        // Silently returns nothing rather than erroring: this is a decoration
        // on a public calendar, not an access-controlled resource of its own.
        if (! $user->isAdmin() && (int) $portfolio->user_id !== (int) $user->id) {
            return [];
        }

        return $portfolio->openHoldings()
            ->with('stock')
            ->get()
            ->map(static fn ($holding): string => strtoupper((string) ($holding->stock->symbol ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
