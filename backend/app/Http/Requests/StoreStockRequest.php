<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Manual stock/scrip creation and editing, for scrips the crawler has not
 * picked up yet or whose details need correcting by hand.
 */
class StoreStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $stockId = $this->route('stock')?->id;
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'symbol' => [
                $required, 'string', 'max:24', 'regex:/^[A-Z0-9\-]+$/',
                Rule::unique('stocks', 'symbol')->ignore($stockId),
            ],
            'name' => [$required, 'string', 'max:255'],
            'sector' => ['nullable', 'string', 'max:120'],
            'instrument_type' => [
                'nullable', 'string',
                Rule::in(['equity', 'promoter', 'debenture', 'mutual_fund', 'preference']),
            ],
            'listed_shares' => ['nullable', 'numeric', 'gte:0'],
            'paid_up_value' => ['nullable', 'numeric', 'gte:0'],
            'market_capitalization' => ['nullable', 'numeric', 'gte:0'],
            'last_price' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'is_active' => ['nullable', 'boolean'],
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
            'symbol.regex' => 'A ticker may only contain capital letters, digits and hyphens.',
            'symbol.unique' => 'That ticker already exists.',
        ];
    }
}
