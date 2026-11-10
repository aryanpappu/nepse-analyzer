<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketCalendarDay extends Model
{
    use HasFactory;

    public const SYNC_NEVER_ATTEMPTED = 'never_attempted';

    public const SYNC_ATTEMPTED = 'attempted';

    public const SYNC_DATA_FOUND = 'data_found';

    public const SYNC_NO_DATA = 'no_data';

    public const TRADING_DAY = 'trading_day';

    public const HOLIDAY = 'holiday';

    public const UNKNOWN = 'unknown';

    public const CLASSIFIED_BY_OBSERVED = 'observed';

    public const CLASSIFIED_BY_MANUAL = 'manual';

    protected $table = 'market_calendar_days';

    protected $fillable = [
        'trade_date',
        'sync_status',
        'attempts',
        'last_attempted_at',
        'observed_symbol_count',
        'classification',
        'classified_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'trade_date' => 'date',
            'attempts' => 'integer',
            'last_attempted_at' => 'datetime',
            'observed_symbol_count' => 'integer',
        ];
    }
}
