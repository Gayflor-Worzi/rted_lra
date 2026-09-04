<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Repeatable Property Description sub-table on a valuation form.
 * Value is computed by RETD: value = amount * quantity * (1 - depreciation%).
 */
class ValuationPropertyDescription extends Model
{
    protected $fillable = [
        'valuation_id',
        'seq',
        'description',
        'level',
        'area_sqft',
        'tar',
        'quantity',
        'amount',
        'building_age',
        'depreciation_pct',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'area_sqft' => 'decimal:2',
            'tar' => 'decimal:2',
            'quantity' => 'integer',
            'amount' => 'decimal:2',
            'building_age' => 'integer',
            'depreciation_pct' => 'decimal:2',
            'value' => 'decimal:2',
        ];
    }

    /** RETD value formula. */
    public static function computeValue(float $amount, float $quantity, float $depreciationPct): float
    {
        return round($amount * $quantity * (1 - ($depreciationPct / 100)), 2);
    }

    public function valuation(): BelongsTo
    {
        return $this->belongsTo(Valuation::class, 'valuation_id');
    }
}
