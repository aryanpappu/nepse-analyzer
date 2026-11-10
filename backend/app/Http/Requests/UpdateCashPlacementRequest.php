<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CashPlacementKind;
use App\Enums\CashPlacementStatus;
use App\Enums\CompoundingBasis;
use App\Enums\PayoutHandling;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCashPlacementRequest extends FormRequest
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
            'kind' => ['sometimes', 'string', Rule::in(CashPlacementKind::values())],
            'institution' => ['sometimes', 'nullable', 'string', 'max:120'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'principal' => ['sometimes', 'numeric', 'gt:0', 'max:999999999999'],
            'interest_rate' => ['sometimes', 'numeric', 'gte:0', 'max:100'],
            'start_date' => ['sometimes', 'date', 'before_or_equal:today'],
            'maturity_date' => ['sometimes', 'nullable', 'date'],
            'closed_on' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'compounding_basis' => ['sometimes', 'string', Rule::in(CompoundingBasis::values())],
            'payout' => ['sometimes', 'string', Rule::in(PayoutHandling::values())],
            'status' => ['sometimes', 'string', Rule::in(CashPlacementStatus::values())],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'principal.gt' => 'Principal must be greater than zero.',
        ];
    }
}
