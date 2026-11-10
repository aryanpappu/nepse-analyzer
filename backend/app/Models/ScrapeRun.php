<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScrapeRun extends Model
{
    use HasFactory;

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'type',
        'source',
        'trade_date',
        'status',
        'rows_found',
        'rows_created',
        'rows_updated',
        'rows_rejected',
        'rejections',
        'error',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'trade_date' => 'date',
            'rejections' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
