<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Valuation workflow record. Produces an assessed value + recommended annual tax;
 * the DIVISION does not generate bills — after AC approval, Account Manager
 * records a confirmation that the result was processed in LITAS.
 */
class Valuation extends Model
{
    use SoftDeletes;

    public const STATUSES = [
        'Draft',
        'Submitted',
        'Manager Review',
        'Returned',
        'AC Approval',
        'Approved',
        'Rejected',
    ];

    public const LITAS_PROCESSED = 'Processed in source system';

    protected $fillable = [
        'valuation_reference',
        'valuation_type',
        'bill_id',
        'discovery_id',
        'property_id',
        'document_number',
        'owner_name',
        'owner_contact',
        'tin',
        'property_classification',
        'property_address',
        'land_dimensions',
        'building_specs',
        'construction_year',
        'condition',
        'assessed_value',
        'reassessed_value',
        'declared_value',
        'annual_tax',
        'applicable_tax_rate',
        'other_amounts',
        'total_property_value',
        'total_tax_payable',
        'assessment_date',
        'submitted_at',
        'prepared_by_designation',
        'photos',
        'gps_coordinate',
        'gps_accuracy',
        'valuation_officer_id',
        'status',
        'manager_decision',
        'manager_remarks',
        'manager_reviewed_by',
        'manager_reviewed_at',
        'ac_decision',
        'ac_remarks',
        'ac_reviewed_by',
        'ac_reviewed_at',
        'litas_processing_status',
        'litas_processed_by',
        'litas_processed_at',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'manager_reviewed_at' => 'datetime',
            'ac_reviewed_at' => 'datetime',
            'litas_processed_at' => 'datetime',
            'assessment_date' => 'date',
            'submitted_at' => 'datetime',
            'assessed_value' => 'decimal:2',
            'reassessed_value' => 'decimal:2',
            'declared_value' => 'decimal:2',
            'annual_tax' => 'decimal:2',
            'gps_accuracy' => 'decimal:2',
            'applicable_tax_rate' => 'decimal:4',
            'other_amounts' => 'decimal:2',
            'total_property_value' => 'decimal:2',
            'total_tax_payable' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Valuation $valuation) {
            if (empty($valuation->valuation_reference)) {
                $valuation->valuation_reference = self::nextReference();
            }
            if (empty($valuation->status)) {
                $valuation->status = 'Draft';
            }
        });
    }

    /** Internal-only reference VAL-YYYY-##### — never confused with LITAS ids. */
    public static function nextReference(): string
    {
        $year = date('Y');
        $last = self::withTrashed()->where('valuation_reference', 'like', "VAL-{$year}-%")->max('id');

        return sprintf('VAL-%s-%05d', $year, ($last ?: 0) + 1);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ValuationReview::class, 'valuation_id');
    }

    public function descriptions(): HasMany
    {
        return $this->hasMany(ValuationPropertyDescription::class, 'valuation_id')->orderBy('seq');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(EvidencePhoto::class, 'valuation_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PropertyBill::class, 'bill_id');
    }

    public function discovery(): BelongsTo
    {
        return $this->belongsTo(PropertyDiscovery::class, 'discovery_id');
    }

    public function valuationOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valuation_officer_id');
    }
}
