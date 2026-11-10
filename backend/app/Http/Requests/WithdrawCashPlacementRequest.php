<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Close a fixed deposit — at maturity, or broken early.
 *
 * Every field is optional because the sensible default is derivable:
 * `closed_on` is today, `settle_cash` mirrors whatever the placement was
 * opened with (settling the exit of an unsettled placement would credit cash
 * that never left it), and `include_interest` follows the payout handling —
 * reinvested interest is still inside the placement and comes back with the
 * principal, whereas paid-out interest was already disbursed into the ledger
 * and must not be credited a second time.
 */
class WithdrawCashPlacementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Not before NEPSE-era records, and never in the future: accrual
            // runs to this date, so a future one would invent interest.
            'closed_on' => ['nullable', 'date', 'after_or_equal:1994-01-01', 'before_or_equal:today'],
            'settle_cash' => ['nullable', 'boolean'],
            'cash_account' => ['nullable', 'string', Rule::in(['cash', 'bank'])],
            'include_interest' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'closed_on.before_or_equal' => 'A deposit cannot be closed on a future date.',
        ];
    }
}
