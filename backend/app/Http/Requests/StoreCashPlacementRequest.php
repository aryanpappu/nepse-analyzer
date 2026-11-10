<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CashPlacementKind;
use App\Enums\CashPlacementStatus;
use App\Enums\CompoundingBasis;
use App\Enums\PayoutHandling;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCashPlacementRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership is enforced by the route's portfolio binding + controller check.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['nullable', 'string', Rule::in(CashPlacementKind::values())],
            'institution' => ['nullable', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:120'],
            'principal' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            // Omit to fall back to the configured FD benchmark rate.
            'interest_rate' => ['nullable', 'numeric', 'gte:0', 'max:100'],
            'start_date' => ['required', 'date', 'before_or_equal:today'],
            'maturity_date' => ['nullable', 'date', 'after:start_date'],
            'closed_on' => ['nullable', 'date', 'after_or_equal:start_date', 'before_or_equal:today'],
            'compounding_basis' => ['nullable', 'string', Rule::in(CompoundingBasis::values())],
            'payout' => ['nullable', 'string', Rule::in(PayoutHandling::values())],
            'status' => ['nullable', 'string', Rule::in(CashPlacementStatus::values())],
            'notes' => ['nullable', 'string', 'max:1000'],
            /*
             * Optional cash settlement, identical in shape to
             * StoreTransactionRequest's. Opening a placement without it leaves
             * the principal sitting in the cash account, so valuation counts
             * that money as idle cash and deliberately does not add the
             * placement on top — otherwise the same rupees appear twice.
             */
            'settle_cash' => ['nullable', 'boolean'],
            'cash_account' => ['nullable', 'string', Rule::in(['cash', 'bank'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'principal.gt' => 'Principal must be greater than zero.',
            'maturity_date.after' => 'Maturity date must be after the start date.',
            'start_date.before_or_equal' => 'A placement cannot start in the future.',
        ];
    }
}
