<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockFundamental extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_id',
        'fiscal_period',
        'reported_on',
        'reported_on_precision',
        'eps',
        'pe_ratio',
        'book_value',
        'pb_ratio',
        'dividend_percent',
        'bonus_percent',
        'cash_dividend_percent',
        'dividend_yield',
        'roe',
        'roa',
        'net_profit',
        'net_worth',
        'paid_up_capital',
        'share_capital',
        'retained_earnings',
        'total_equity',
        'total_assets',
        'outstanding_shares',
        'distributable_eps',
        'distributable_eps_yield',
        'reserve',
        'npl_percent',
        'capital_adequacy_ratio',
        'base_rate',
        'source',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'reported_on' => 'date',
            'eps' => 'float',
            'pe_ratio' => 'float',
            'book_value' => 'float',
            'pb_ratio' => 'float',
            'dividend_percent' => 'float',
            'bonus_percent' => 'float',
            'cash_dividend_percent' => 'float',
            'dividend_yield' => 'float',
            'roe' => 'float',
            'roa' => 'float',
            'net_profit' => 'float',
            'net_worth' => 'float',
            'paid_up_capital' => 'float',
            'share_capital' => 'float',
            'retained_earnings' => 'float',
            'total_equity' => 'float',
            'total_assets' => 'float',
            'outstanding_shares' => 'float',
            'distributable_eps' => 'float',
            'distributable_eps_yield' => 'float',
            'reserve' => 'float',
            'npl_percent' => 'float',
            'capital_adequacy_ratio' => 'float',
            'base_rate' => 'float',
            'raw' => 'array',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
