<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePortfolioRequest extends FormRequest
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
        $portfolioId = $this->route('portfolio')?->id;

        return [
            'name' => [
                'required', 'string', 'max:120',
                // Names are unique per user so the picker stays unambiguous.
                Rule::unique('portfolios', 'name')
                    ->where('user_id', $this->user()?->id)
                    ->ignore($portfolioId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'currency' => ['nullable', 'string', 'size:3'],
            'cash_in_hand' => ['nullable', 'numeric', 'gte:0', 'max:999999999999'],
            'bank_balance' => ['nullable', 'numeric', 'gte:0', 'max:999999999999'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'You already have a portfolio with that name.',
        ];
    }
}
