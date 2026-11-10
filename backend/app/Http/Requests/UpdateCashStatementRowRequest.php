<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correct one staged row before committing.
 *
 * A row the parser could not read confidently is staged as `needs_review`
 * rather than guessed — direction in particular, because guessing it would
 * flip the sign on a real movement.
 */
class UpdateCashStatementRowRequest extends FormRequest
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
            'key' => ['required', 'string', 'max:80'],
            'direction' => ['sometimes', 'string', Rule::in(['in', 'out'])],
            'amount' => ['sometimes', 'numeric', 'gt:0', 'max:999999999'],
            'occurred_on' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'include' => ['sometimes', 'boolean'],
        ];
    }
}
