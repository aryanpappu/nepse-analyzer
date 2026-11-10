<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioImport extends Model
{
    use HasFactory;

    public const STATUS_AWAITING_HOLDINGS = 'awaiting_holdings';

    public const STATUS_AWAITING_WACC = 'awaiting_wacc';

    public const STATUS_READY = 'ready';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'portfolio_id',
        'user_id',
        'source',
        'status',
        'demat_masked',
        'holdings_filename',
        'wacc_filename',
        'holdings_rows',
        'wacc_rows',
        'merged_rows',
        'warnings',
        'holdings_count',
        'wacc_count',
        'committed_count',
        'total_cost',
        'total_value',
        'committed_at',
    ];

    protected function casts(): array
    {
        return [
            'holdings_rows' => 'array',
            'wacc_rows' => 'array',
            'merged_rows' => 'array',
            'warnings' => 'array',
            'total_cost' => 'decimal:2',
            'total_value' => 'decimal:2',
            'committed_at' => 'datetime',
        ];
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isEditable(): bool
    {
        return ! in_array($this->status, [self::STATUS_COMMITTED, self::STATUS_CANCELLED], true);
    }
}
