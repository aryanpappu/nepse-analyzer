<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackfillProgress extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_EXHAUSTED = 'exhausted';

    protected $table = 'backfill_progress';

    protected $fillable = [
        'stock_id',
        'target_start_date',
        'earliest_bar_date',
        'status',
        'attempts',
        'bars_fetched',
        'last_attempted_at',
        'last_result',
    ];

    protected function casts(): array
    {
        return [
            'target_start_date' => 'date',
            'earliest_bar_date' => 'date',
            'last_attempted_at' => 'datetime',
            'attempts' => 'integer',
            'bars_fetched' => 'integer',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
