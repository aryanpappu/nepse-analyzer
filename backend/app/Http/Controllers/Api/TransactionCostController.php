<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Portfolio\TransactionCostCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What a trade actually costs: tiered broker commission, the SEBON fee and the
 * DP charge.
 *
 * Public and unauthenticated because it is pure arithmetic over the published
 * schedule — it touches no portfolio and no database. Quoting a cost before
 * placing an order is exactly the case for it, and a round trip runs roughly
 * 0.6-1.2%, which is a material slice of a typical annual gain.
 *
 * The schedule is date-effective, so `date` prices a past trade at the rates
 * that applied then rather than today's.
 */
class TransactionCostController extends Controller
{
    public function __construct(
        private readonly TransactionCostCalculator $calculator,
    ) {}

    public function quote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'side' => ['required', 'string', 'in:buy,sell'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'price' => ['required', 'numeric', 'gt:0'],
            // Never a future date: the schedule that applies then is unknown.
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'instrument_class' => ['nullable', 'string', 'max:32'],
        ]);

        $breakdown = $this->calculator->compute(
            $validated['side'],
            (float) $validated['quantity'],
            (float) $validated['price'],
            isset($validated['date']) ? Carbon::parse($validated['date']) : null,
            $validated['instrument_class'] ?? null,
        );

        return response()->json(['data' => $breakdown->toArray()]);
    }
}
