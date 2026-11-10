<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCashTransactionRequest extends FormRequest
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
            'action' => ['required', 'string', Rule::in(['deposit', 'withdraw', 'transfer', 'set'])],
            'account' => ['required_unless:action,transfer', 'nullable', 'string', Rule::in(['cash', 'bank'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'from_account' => ['required_if:action,transfer', 'nullable', 'string', Rule::in(['cash', 'bank'])],
            'to_account' => [
                'required_if:action,transfer', 'nullable', 'string',
                Rule::in(['cash', 'bank']),
                'different:from_account',
            ],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'transacted_on' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to_account.different' => 'Transfer source and destination must be different accounts.',
            'amount.gt' => 'Amount must be greater than zero.',
        ];
    }
}
