<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * New Property Discovery — first-class workflow. Property ID / Document # are
 * LITAS identifiers; this system never generates them (blank until the source
 * system creates them).
 */
class PropertyDiscovery extends Model
{
    use SoftDeletes;
    /** Full lifecycle — classification selects Path A (account) or B (valuation). */
    public const STATUSES = [
        'DISCOVERED',
        'SUBMITTED',
        'UNDER_MANAGER_REVIEW',
        'CLASSIFIED',
        'SENT_TO_ACCOUNT',
        'VALUATION_REQUIRED',
        'VALUATION_ASSIGNED',
        'UNDER_VALUATION',
        'VALUATION_MANAGER_REVIEW',
        'PENDING_AC_APPROVAL',
        'AC_APPROVED',
        'AC_REJECTED',
        'RETURNED_FOR_CORRECTION',
        'RESUBMITTED',
        'SENT_TO_ACCOUNT_MANAGER',
        'PROCESSED_IN_LITAS',
        'COMPLETED',
    ];

    public const DECISION_PATHS = ['account', 'valuation'];

    public const STATUS_DISCOVERED = 'DISCOVERED';
    public const STATUS_SUBMITTED = 'SUBMITTED';
    public const STATUS_UNDER_REVIEW = 'UNDER_MANAGER_REVIEW';
    public const STATUS_CLASSIFIED = 'CLASSIFIED';
    public const STATUS_SENT_TO_ACCOUNT = 'SENT_TO_ACCOUNT';
    public const STATUS_VALUATION_REQUIRED = 'VALUATION_REQUIRED';
    public const STATUS_VALUATION_ASSIGNED = 'VALUATION_ASSIGNED';
    public const STATUS_UNDER_VALUATION = 'UNDER_VALUATION';
    public const STATUS_VALUATION_MANAGER_REVIEW = 'VALUATION_MANAGER_REVIEW';
    public const STATUS_PENDING_AC_APPROVAL = 'PENDING_AC_APPROVAL';
    public const STATUS_AC_APPROVED = 'AC_APPROVED';
    public const STATUS_AC_REJECTED = 'AC_REJECTED';
    public const STATUS_RETURNED_FOR_CORRECTION = 'RETURNED_FOR_CORRECTION';
    public const STATUS_RESUBMITTED = 'RESUBMITTED';
    public const STATUS_SENT_TO_ACCOUNT_MANAGER = 'SENT_TO_ACCOUNT_MANAGER';
    public const STATUS_PROCESSED_IN_LITAS = 'PROCESSED_IN_LITAS';
    public const STATUS_COMPLETED = 'COMPLETED';

    protected $fillable = [
        'discovery_reference',
        'status',
        'owner_name',
        'owner_contact',
        'tin',
        'property_address',
        'county',
        'district',
        'city_town',
        'community',
        'street',
        'house_number',
        'property_classification',
        'property_type',
        'occupancy_use',
        'description',
        'property_id',
        'document_number',
        'gps_coordinate',
        'gps_accuracy',
        'gps_captured_at',
        'discovery_date',
        'discovered_by',
        'decision_path',
        'classification_decision',
        'classified_by',
        'classified_at',
        'manager_remarks',
        'valuation_id',
        'ac_decided_by',
        'ac_decided_at',
        'ac_decision',
        'ac_remarks',
        'processed_by',
        'processed_at',
        'remarks',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'gps_accuracy' => 'decimal:2',
            'gps_captured_at' => 'datetime',
            'discovery_date' => 'date',
            'classified_at' => 'datetime',
            'ac_decided_at' => 'datetime',
            'processed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PropertyDiscovery $d) {
            if (empty($d->discovery_reference)) {
                $d->discovery_reference = self::nextReference();
            }
            if (empty($d->status)) {
                $d->status = 'DISCOVERED';
            }
        });
    }

    /** Internal reference ND-YYYY-##### — never confused with LITAS ids. */
    public static function nextReference(): string
    {
        $year = date('Y');
        $last = self::where('discovery_reference', 'like', "ND-{$year}-%")->max('id');

        return sprintf('ND-%s-%05d', $year, ($last ?: 0) + 1);
    }

    public function discoverer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discovered_by');
    }

    public function classifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'classified_by');
    }

    public function acDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ac_decided_by');
    }

    public function valuation(): BelongsTo
    {
        return $this->belongsTo(Valuation::class, 'valuation_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(EvidencePhoto::class, 'discovery_id')->orderBy('id');
    }
}