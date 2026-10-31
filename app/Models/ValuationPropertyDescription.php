<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Repeatable Property Description sub-table on a valuation form.
 *
 * Land is always the first row and is never depreciated. Every other row is a
 * building level or other component. RETD owns the arithmetic:
 *
 *   Amount       Land: market value per lot × number of lots
 *                Other: area (sq ft) × TAR rate × quantity
 *   Depreciation Land: 0%
 *                Other: building age (years) × 2%, straight-line on the Amount
 *   Value        Amount × (1 − Depreciation% ÷ 100)
 *
 * Quantity is already inside the Amount for both kinds of row, so it is never
 * applied a second time when the value is derived.
 */
class ValuationPropertyDescription extends Model
{
    /** Straight-line annual depreciation applied to the original row amount. */
    public const DEPRECIATION_RATE_PCT = 2.0;

    /** The mandatory first row of every property description. */
    public const LAND_LEVEL = 'Land';

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

    public static function isLand(?string $level): bool
    {
        return strcasecmp(trim((string) $level), self::LAND_LEVEL) === 0;
    }

    /**
     * The row's Amount in US$.
     *
     * Land's rate is a market value per lot, so quantity (lots) is applied here
     * and nowhere else. A building row's rate is a TAR rate per square foot.
     * Returns null — never 0 — when a required input is missing, so an
     * incomplete row cannot masquerade as a zero-dollar assessment.
     */
    public static function computeAmount(?string $level, ?float $area, ?float $tar, int $quantity): ?float
    {
        if ($tar === null || $quantity < 1) {
            return null;
        }

        if (self::isLand($level)) {
            return round($tar * $quantity, 2);
        }

        if ($area === null) {
            return null;
        }

        return round($area * $tar * $quantity, 2);
    }

    /** Age × 2% per year. Land is exempt and always 0%. */
    public static function computeDepreciationPct(?string $level, ?int $buildingAge): float
    {
        if (self::isLand($level)) {
            return 0.0;
        }

        $pct = max(0, (int) $buildingAge) * self::DEPRECIATION_RATE_PCT;

        // Straight-line depreciation cannot take a row below zero.
        return round(min(100.0, $pct), 2);
    }

    /** RETD value formula: Amount less straight-line depreciation. */
    public static function computeValue(?float $amount, float $depreciationPct): ?float
    {
        if ($amount === null) {
            return null;
        }

        return round($amount * (1 - ($depreciationPct / 100)), 2);
    }

    public function valuation(): BelongsTo
    {
        return $this->belongsTo(Valuation::class, 'valuation_id');
    }
}
