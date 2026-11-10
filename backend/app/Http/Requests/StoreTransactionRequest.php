<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership is enforced by the route's portfolio binding + policy.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'symbol' => ['required', 'string', 'max:24', Rule::exists('stocks', 'symbol')],
            'type' => ['required', 'string', Rule::in(TransactionType::values())],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'price' => ['required', 'numeric', 'gte:0', 'max:1000000'],
            'commission' => ['nullable', 'numeric', 'gte:0', 'max:10000000'],
            'other_fees' => ['nullable', 'numeric', 'gte:0', 'max:10000000'],
            // A trade cannot settle in the future, and NEPSE has no records
            // before it opened in 1994.
            'transacted_on' => ['required', 'date', 'after_or_equal:1994-01-01', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // When true, the cash ledger is debited/credited alongside the trade.
            'settle_cash' => ['nullable', 'boolean'],
            'cash_account' => ['nullable', 'string', Rule::in(['cash', 'bank'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('symbol')) {
            $this->merge(['symbol' => strtoupper(trim((string) $this->input('symbol')))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'symbol.exists' => 'That ticker is not in the stock list yet. Run the NEPSE scrape, or add the stock first.',
            'transacted_on.before_or_equal' => 'A transaction cannot be dated in the future.',
            'quantity.gt' => 'Quantity must be greater than zero.',
        ];
    }
}
