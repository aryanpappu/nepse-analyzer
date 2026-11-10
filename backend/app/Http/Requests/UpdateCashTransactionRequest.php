<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CashTransaction;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCashTransactionRequest extends FormRequest
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
        return [
            'amount' => ['sometimes', 'numeric', 'gt:0', 'max:999999999999'],
            'account' => ['sometimes', 'string', Rule::in(CashTransaction::accounts())],
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'transacted_on' => ['sometimes', 'date', 'before_or_equal:today'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->safe()->all() === []) {
                $validator->errors()->add('amount', 'Provide at least one field to amend.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.gt' => 'Amount must be greater than zero.',
        ];
    }
}
