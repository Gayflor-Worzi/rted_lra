<?php

namespace App\Models;

use App\Models\Concerns\GeneratesInternalReference;
use App\Services\PropertyIdentityService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Property Discovery — first-class controlled-routing workflow (spec v4).
 *
 * Property ID / Document # are source-system identifiers; RETD never generates them.
 *
 * Core flow:
 *   DISCOVERED → SUBMITTED → CLASSIFIED → Account & Record Manager queue
 *                                              or Valuation Manager queue
 *
 * The Account & Record desk is split by rank: the Manager reviews and assigns,
 * the Officer only processes. Once a record reaches
 * STATUS_ASSIGNED_ACCT the officer has an assigned task to execute — the
 * property is registered in the source system and the property bill record is
 * updated in RETD. Officers never review.
 *
 * Task ownership: the task/queue moves between management desks as the
 * property record progresses; the underlying record is never duplicated.
 */
class PropertyDiscovery extends Model
{
    use GeneratesInternalReference;
    use SoftDeletes;

    // ── Controlled status vocabulary (spec §19) ─────────────────────────────

    public const STATUS_DRAFT = 'Draft';

    public const STATUS_SUBMITTED = 'Submitted';

    public const STATUS_CLASSIFIED = 'Classified';

    public const STATUS_AWAITING_ACCT_REVIEW = 'Awaiting Account Manager Review';

    public const STATUS_AWAITING_VALUATION = 'Awaiting Valuation';

    public const STATUS_UNDER_VALUATION = 'Under Valuation';

    public const STATUS_AWAITING_AC_APPROVAL = 'Awaiting AC Approval';

    public const STATUS_APPROVED = 'Approved';

    public const STATUS_RETURNED = 'Returned for Correction';

    /**
     * The Account & Record Manager has approved the record and assigned an
     * Account & Record Officer. The officer does not review: they process the
     * property registration in the source system and then update the property
     * bill record in RETD.
     */
    public const STATUS_ASSIGNED_ACCT = 'Assigned to Account & Record';

    public const STATUS_REFERRED_VALUATION = 'Referred to Valuation';

    public const STATUS_REGISTERED = 'Registered';

    public const STATUS_COMPLETED = 'Completed';

    public const STATUS_REJECTED = 'Rejected';

    public const STATUS_CLOSED = 'Closed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_CLASSIFIED,
        self::STATUS_AWAITING_ACCT_REVIEW,
        self::STATUS_AWAITING_VALUATION,
        self::STATUS_UNDER_VALUATION,
        self::STATUS_AWAITING_AC_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_RETURNED,
        self::STATUS_ASSIGNED_ACCT,
        self::STATUS_REFERRED_VALUATION,
        self::STATUS_REGISTERED,
        self::STATUS_COMPLETED,
        self::STATUS_REJECTED,
        self::STATUS_CLOSED,
    ];

    public const DECISION_PATHS = ['account', 'valuation'];

    // ── Controlled value-declaration vocabulary (system-wide §2/§5) ──────────

    public const VALUE_DECLARATION_SELF_DECLARED = 'SELF_DECLARED';

    public const VALUE_DECLARATION_DOCUMENTARY = 'DOCUMENTARY_VALUE';

    public const VALUE_DECLARATION_VALUATION = 'VALUATION_VALUE';

    public const VALUE_DECLARATION_TYPES = [
        self::VALUE_DECLARATION_SELF_DECLARED,
        self::VALUE_DECLARATION_DOCUMENTARY,
        self::VALUE_DECLARATION_VALUATION,
    ];

    // ── Fillable ────────────────────────────────────────────────────────────

    // discovery_reference is deliberately absent: it is generated once from the row
    // id and is permanent, so it must never be mass-assignable. The trait blocks
    // changes to it and the unique index on the column keeps it unique.

    protected $fillable = [
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
        'declared_value',
        'value_declaration_type',
        'declared_assessed_value',
        'documentary_value',
        'official_assessed_value',
        'property_id',
        'document_number',
        'gps_coordinate',
        'gps_accuracy',
        'gps_captured_at',
        'gps_verified',
        'gps_certified_by',
        'gps_certified_at',
        'gps_cert_remarks',
        'discovery_date',
        'discovered_by',
        'decision_path',
        'classification_decision',
        'classification_source',
        'classified_by',
        'classified_at',
        'submitted_at',
        'manager_remarks',
        'valuation_id',
        'current_owner_id',
        'aarm_reviewed_by',
        'aarm_reviewed_at',
        'aarm_decision',
        'aarm_remarks',
        'referred_by',
        'referred_at',
        'referral_reason',
        'referral_from_stage',
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
            'declared_value' => 'decimal:2',
            'declared_assessed_value' => 'decimal:2',
            'documentary_value' => 'decimal:2',
            'official_assessed_value' => 'decimal:2',
            'gps_accuracy' => 'decimal:2',
            'gps_captured_at' => 'datetime',
            'gps_certified_at' => 'datetime',
            'discovery_date' => 'date',
            'submitted_at' => 'datetime',
            'classified_at' => 'datetime',
            'aarm_reviewed_at' => 'datetime',
            'referred_at' => 'datetime',
            'ac_decided_at' => 'datetime',
            'processed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // ── Booted ──────────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (PropertyDiscovery $d) {
            if (empty($d->status)) {
                $d->status = self::STATUS_DRAFT;
            }
        });

        // One permanent property identity from the first recording onward,
        // whatever entry point created the discovery (officer capture, sync, or
        // an enforcement conversion).
        static::created(function (PropertyDiscovery $d) {
            app(PropertyIdentityService::class)->forDiscovery($d);
        });
    }

    // The declared/documentary value captured at discovery is the single
    // "declared_assessed_value" — the legacy declared_value column mirrors it so
    // existing valuation/bill integration keeps reading the same figure.
    public function setDeclaredAssessedValueAttribute($value): void
    {
        $this->attributes['declared_assessed_value'] = $value;
        $this->attributes['declared_value'] = $value;
    }

    public function setDeclaredValueAttribute($value): void
    {
        $this->attributes['declared_value'] = $value;
        $this->attributes['declared_assessed_value'] = $value;
    }

    // ── Internal reference (G0.4 — canonical number = row id) ────────────────

    public static function referencePrefix(): string
    {
        return 'ND';
    }

    public static function referenceField(): string
    {
        return 'discovery_reference';
    }

    // ── Relations ───────────────────────────────────────────────────────────

    public function discoverer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discovered_by');
    }

    public function classifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'classified_by');
    }

    public function currentOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_owner_id');
    }

    public function aarmReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aarm_reviewed_by');
    }

    public function referredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    public function acDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ac_decided_by');
    }

    public function valuation(): BelongsTo
    {
        return $this->belongsTo(Valuation::class, 'valuation_id');
    }

    /** Permanent property identity shared across every section. */
    public function propertyRef(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_ref_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(EvidencePhoto::class, 'discovery_id')->orderBy('id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(PropertyDiscoveryEvidence::class, 'discovery_id')->orderBy('sequence_no')->orderBy('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'reference_id')
            ->where('reference_type', 'property_discovery');
    }
}
