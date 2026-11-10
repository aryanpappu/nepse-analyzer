<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A committed statement movement, and the cash ledger row it produced.
 *
 * The `fingerprint` is the idempotency key — unique per portfolio — so the
 * same statement uploaded twice adds nothing the second time.
 */
class CashStatementEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'portfolio_id',
        'cash_statement_import_id',
        'cash_transaction_id',
        'fingerprint',
        'external_id',
        'direction',
        'amount',
        'occurred_on',
        'reference',
        'channel',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'occurred_on' => 'date',
        ];
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(CashStatementImport::class, 'cash_statement_import_id');
    }

    public function cashTransaction(): BelongsTo
    {
        return $this->belongsTo(CashTransaction::class);
    }
}
