<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A staged, reviewable import of a broker cash statement.
 *
 * Mirrors {@see PortfolioImport}'s lifecycle — parse, review and edit, commit
 * or cancel — for cash movements rather than positions.
 */
class CashStatementImport extends Model
{
    use HasFactory;

    /** Parsed and awaiting the user's review. */
    public const STATUS_READY = 'ready';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'portfolio_id',
        'user_id',
        'format',
        'status',
        'account',
        'filename',
        'parsed_rows',
        'merged_rows',
        'warnings',
        'overlap',
        'parsed_count',
        'ready_count',
        'excluded_count',
        'duplicate_count',
        'committed_count',
        'money_in',
        'money_out',
        'net_injected',
        'first_movement_on',
        'last_movement_on',
        'reconciliation',
        'committed_at',
    ];

    protected function casts(): array
    {
        return [
            'parsed_rows' => 'array',
            'merged_rows' => 'array',
            'warnings' => 'array',
            'overlap' => 'array',
            'reconciliation' => 'array',
            'money_in' => 'decimal:2',
            'money_out' => 'decimal:2',
            'net_injected' => 'decimal:2',
            'first_movement_on' => 'date',
            'last_movement_on' => 'date',
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

    public function entries(): HasMany
    {
        return $this->hasMany(CashStatementEntry::class);
    }

    public function isEditable(): bool
    {
        return ! in_array($this->status, [self::STATUS_COMMITTED, self::STATUS_CANCELLED], true);
    }
}
