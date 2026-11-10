<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CashPlacementKind;
use App\Enums\CashPlacementStatus;
use App\Enums\CompoundingBasis;
use App\Enums\PayoutHandling;
use App\Services\Portfolio\CashPlacementService;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bulk-create many cash placements in one request, validated as a whole set.
 *
 * Every row must be individually valid before the controller runs at all —
 * one bad row fails the entire request with a 422 naming which row and field
 * — and {@see CashPlacementService::createMany()}
 * wraps the inserts themselves in a DB transaction, so a failure at the
 * persistence layer (rather than validation) still cannot leave a partial
 * batch behind.
 */
class BulkStoreCashPlacementRequest extends FormRequest
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
        $max = (int) config('nepse.cash_placements.bulk_max', 200);

        return [
            'placements' => ['required', 'array', 'min:1', "max:{$max}"],
            'placements.*.kind' => ['nullable', 'string', Rule::in(CashPlacementKind::values())],
            'placements.*.institution' => ['nullable', 'string', 'max:120'],
            'placements.*.reference' => ['nullable', 'string', 'max:120'],
            'placements.*.principal' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'placements.*.interest_rate' => ['nullable', 'numeric', 'gte:0', 'max:100'],
            'placements.*.start_date' => ['required', 'date', 'before_or_equal:today'],
            'placements.*.maturity_date' => ['nullable', 'date'],
            'placements.*.closed_on' => ['nullable', 'date', 'before_or_equal:today'],
            'placements.*.compounding_basis' => ['nullable', 'string', Rule::in(CompoundingBasis::values())],
            'placements.*.payout' => ['nullable', 'string', Rule::in(PayoutHandling::values())],
            'placements.*.status' => ['nullable', 'string', Rule::in(CashPlacementStatus::values())],
            'placements.*.notes' => ['nullable', 'string', 'max:1000'],
            /*
             * Optional cash settlement, identical in shape to
             * StoreTransactionRequest's. Opening a placement without it leaves
             * the principal sitting in the cash account, so valuation counts
             * that money as idle cash and deliberately does not add the
             * placement on top — otherwise the same rupees appear twice.
             */
            'placements.*.settle_cash' => ['nullable', 'boolean'],
            'placements.*.cash_account' => ['nullable', 'string', Rule::in(['cash', 'bank'])],
        ];
    }

    /**
     * Cross-field checks per row (maturity/closure vs. start date). Done here
     * rather than as declarative `after:placements.*.start_date` rules, whose
     * wildcard-to-wildcard sibling resolution is easy to get subtly wrong —
     * an explicit loop is worth the extra lines for money-dated validation.
     */
    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            foreach ((array) $this->input('placements', []) as $index => $row) {
                $start = $row['start_date'] ?? null;
                $maturity = $row['maturity_date'] ?? null;
                $closed = $row['closed_on'] ?? null;

                if ($start === null) {
                    continue;
                }

                if ($maturity !== null && strtotime((string) $maturity) <= strtotime((string) $start)) {
                    $validator->errors()->add(
                        "placements.{$index}.maturity_date",
                        'Maturity date must be after the start date.',
                    );
                }

                if ($closed !== null && strtotime((string) $closed) < strtotime((string) $start)) {
                    $validator->errors()->add(
                        "placements.{$index}.closed_on",
                        'Closed date cannot be before the start date.',
                    );
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'placements.required' => 'Provide at least one placement.',
            'placements.*.principal.gt' => 'Principal must be greater than zero.',
            'placements.*.start_date.before_or_equal' => 'A placement cannot start in the future.',
        ];
    }
}
