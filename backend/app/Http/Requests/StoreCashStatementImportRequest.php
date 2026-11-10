<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Upload a broker cash statement for review.
 *
 * Nothing is written to the ledger here — the file is parsed and staged so the
 * rows can be corrected first. FAILED rows, duplicates and suspected overlaps
 * are all excluded by default and shown with their reason.
 */
class StoreCashStatementImportRequest extends FormRequest
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
            // xlsx is the broker's own download and the common case; the text
            // formats are pasted or exported. 8 MB covers a decade of rows.
            'file' => ['required', 'file', 'max:8192', 'mimes:csv,txt,xlsx'],
            'format' => ['nullable', 'string', Rule::in(['tms_deposit_log', 'broker_ledger'])],
            'account' => ['nullable', 'string', Rule::in(['cash', 'bank'])],
        ];
    }
}
