<?php

namespace App\Models;

use App\Models\Concerns\GeneratesInternalReference;
use App\Services\PropertyIdentityService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Valuation assessment — independent of Discovery (spec §8) and supporting
 * both Discovery-origin (Path B/C) and standalone existing-property assessments
 * (Path D).
 *
 * Status vocabulary (spec §19):
 *   Assigned → Assessment in Progress → Submitted to Manager →
 *     (Returned for Correction | Submitted to AC → Awaiting AC Approval →
 *      AC Approved / AC Returned / AC Rejected → Sent to Account & Record →
 *      Registered → Completed)
 *
 * Task ownership: the valuation task moves between desks; the underlying
 * record is never duplicated (spec Rule 11).
 *
 * System boundary: RETD is an independent internal workflow system. The
 * registration/assessment outcome happens in the source system; RETD only records the
 * event (registration status) and the source-system identifiers against its own record.
 */
class Valuation extends Model
{
    use GeneratesInternalReference;
    use SoftDeletes;

    // ── Controlled status vocabulary (spec §19) ─────────────────────────────

    public const STATUS_ASSIGNED = 'Assigned';

    public const STATUS_ASSESSMENT_IN_PROGRESS = 'Assessment in Progress';

    public const STATUS_SUBMITTED_TO_MANAGER = 'Submitted to Manager';

    public const STATUS_UNDER_MANAGER_REVIEW = 'Under Manager Review';

    public const STATUS_RETURNED = 'Returned for Correction';

    public const STATUS_SUBMITTED_TO_AC = 'Submitted to AC';

    public const STATUS_AWAITING_AC_APPROVAL = 'Awaiting AC Approval';

    public const STATUS_AC_APPROVED = 'AC Approved';

    public const STATUS_AC_RETURNED = 'AC Returned';

    public const STATUS_AC_REJECTED = 'AC Rejected';

    public const STATUS_SENT_TO_ACCT = 'Sent to Account & Record';

    public const STATUS_REGISTERED = 'Registered';

    public const STATUS_COMPLETED = 'Completed';

    // Legacy aliases kept for downstream consumers (DashboardController etc.)
    public const STATUS_DRAFT = 'Draft';

    public const STATUS_SUBMITTED = 'Submitted';

    public const STATUS_MANAGER_REVIEW = 'Manager Review';

    public const STATUS_AC_APPROVAL = 'AC Approval';

    public const STATUS_APPROVED = 'Approved';

    public const STATUS_REJECTED = 'Rejected';

    public const STATUSES = [
        self::STATUS_ASSIGNED,
        self::STATUS_ASSESSMENT_IN_PROGRESS,
        self::STATUS_SUBMITTED_TO_MANAGER,
        self::STATUS_UNDER_MANAGER_REVIEW,
        self::STATUS_RETURNED,
        self::STATUS_SUBMITTED_TO_AC,
        self::STATUS_AWAITING_AC_APPROVAL,
        self::STATUS_AC_APPROVED,
        self::STATUS_AC_RETURNED,
        self::STATUS_AC_REJECTED,
        self::STATUS_SENT_TO_ACCT,
        self::STATUS_REGISTERED,
        self::STATUS_COMPLETED,
    ];

    public const REGISTRATION_COMPLETED = 'Registration Completed';

    // ── Authoritative stage map (one interpretation of a valuation's status) ─

    /**
     * The workflow stages, each holding every status that belongs to it.
     *
     * This is the single place a valuation's current status is turned into a stage.
     * The register's tabs, the Valuation Officer's dashboard counters, the Valuation
     * Manager's counters and their drill-downs all read this map, so a counter, the tab
     * it opens and the records behind it cannot disagree.
     *
     * Every status appears in exactly one stage, and every status in STATUSES appears
     * somewhere: a stage is a partition, not a set of favourites. That is what stops a
     * record from being counted twice, or from belonging to no stage at all and so being
     * unreachable in the register.
     *
     * Two placements are load-bearing and follow the workflow engine:
     *
     *  - `Registered` belongs to Account & Record, not to Completed. Registration is
     *    still awaiting the account officer's `complete()` step, so a registered
     *    valuation is active work, not finished work.
     *  - `AC Returned` and `AC Rejected` belong with `Returned for Correction`. They are
     *    the same thing as far as the officer is concerned: the assessment came back
     *    and needs correcting.
     */
    public const STAGE_TABS = [
        'Assigned' => [self::STATUS_ASSIGNED],
        'Assessment in Progress' => [self::STATUS_ASSESSMENT_IN_PROGRESS],
        'Awaiting Review' => [self::STATUS_SUBMITTED_TO_MANAGER, self::STATUS_UNDER_MANAGER_REVIEW],
        'Awaiting AC' => [self::STATUS_SUBMITTED_TO_AC, self::STATUS_AWAITING_AC_APPROVAL],
        'Awaiting Account & Record' => [self::STATUS_AC_APPROVED, self::STATUS_SENT_TO_ACCT, self::STATUS_REGISTERED],
        'Returned for Correction' => [self::STATUS_RETURNED, self::STATUS_AC_RETURNED, self::STATUS_AC_REJECTED],
        'Completed' => [self::STATUS_COMPLETED],
    ];

    /**
     * Statuses that represent finished work. A terminal record is not counted as open,
     * due today, overdue or active, whatever its task rows happen to say.
     */
    public const TERMINAL_STATUSES = [self::STATUS_COMPLETED];

    /**
     * The stage a status belongs to, or null for a status outside the vocabulary.
     */
    public static function stageFor(?string $status): ?string
    {
        foreach (self::STAGE_TABS as $stage => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * Every status in a stage, flattened.
     */
    public static function statusesForStage(string $stage): array
    {
        return self::STAGE_TABS[$stage] ?? [];
    }

    // ── Fillable ────────────────────────────────────────────────────────────

    // valuation_reference is deliberately absent: it is generated once from the row
    // id and is permanent, so it must never be mass-assignable. The trait blocks
    // changes to it and the unique index on the column keeps it unique.

    protected $fillable = [
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
        'gps_verified',
        'gps_certified_by',
        'gps_certified_at',
        'gps_cert_remarks',
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
        'account_reviewed_by',
        'account_reviewed_at',
        'account_remarks',
        'registration_status',
        'registration_completed_by',
        'registration_completed_at',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'manager_reviewed_at' => 'datetime',
            'ac_reviewed_at' => 'datetime',
            'account_reviewed_at' => 'datetime',
            'registration_completed_at' => 'datetime',
            'assessment_date' => 'date',
            'submitted_at' => 'datetime',
            'assessed_value' => 'decimal:2',
            'reassessed_value' => 'decimal:2',
            'declared_value' => 'decimal:2',
            'annual_tax' => 'decimal:2',
            'gps_accuracy' => 'decimal:2',
            'gps_certified_at' => 'datetime',
            'applicable_tax_rate' => 'decimal:4',
            'other_amounts' => 'decimal:2',
            'total_property_value' => 'decimal:2',
            'total_tax_payable' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Valuation $valuation) {
            if (empty($valuation->status)) {
                $valuation->status = self::STATUS_ASSIGNED;
            }
        });

        // Every assessment belongs to a property identity: the discovery's when
        // it came from one, the reassessed bill's when it is a reassessment, and
        // its own when it is a standalone assessment. Resolved on creation so no
        // entry point can record an assessment without one.
        static::created(function (Valuation $valuation) {
            app(PropertyIdentityService::class)->forValuation($valuation);
        });
    }

    // ── Reference (G0.4 — canonical number = row id) ─────────────────────────

    public static function referencePrefix(): string
    {
        return 'VAL';
    }

    public static function referenceField(): string
    {
        return 'valuation_reference';
    }

    // ── Relations ───────────────────────────────────────────────────────────

    public function reviews(): HasMany
    {
        return $this->hasMany(ValuationReview::class, 'valuation_id');
    }

    public function descriptions(): HasMany
    {
        return $this->hasMany(ValuationPropertyDescription::class, 'valuation_id')->orderBy('seq');
    }

    public function evidencePhotos(): HasMany
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

    /** Permanent property identity shared across every section. */
    public function propertyRef(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_ref_id');
    }

    /**
     * The discovery this assessment's photographs are inherited from.
     *
     * A valuation is raised *from* a discovery, and the discovery is where the property
     * was actually photographed. This is the one place that decides which discovery that
     * is, so the submission check and the printed record sheet cannot end up disagreeing
     * about whether a property has any photographs on file.
     *
     * The explicitly linked discovery is authoritative: it is the record this assessment
     * was raised from, and it says which visit the valuation describes. A valuation with
     * no link falls back to the most recent discovery of the same property, which is the
     * only remaining evidence of what was inspected.
     */
    public function sourceDiscovery(): ?PropertyDiscovery
    {
        if ($this->discovery_id) {
            $linked = PropertyDiscovery::find($this->discovery_id);
            if ($linked) {
                return $linked;
            }
        }

        if ($this->property_id) {
            return PropertyDiscovery::where('property_id', $this->property_id)
                ->orderByDesc('id')
                ->first();
        }

        return null;
    }

    /**
     * The source discovery's property photographs, in the order they were taken.
     *
     * These are real property photographs of this property; they simply happen to have
     * been captured on the discovery visit rather than during the assessment.
     */
    public function discoveryPropertyPhotographs()
    {
        $discovery = $this->sourceDiscovery();

        if (! $discovery) {
            return collect();
        }

        return $discovery->evidence()
            ->where('evidence_type', PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO)
            ->orderBy('sequence_no')
            ->orderBy('id')
            ->get();
    }

    public function valuationOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valuation_officer_id');
    }

    public function managerReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_reviewed_by');
    }

    public function acReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ac_reviewed_by');
    }

    public function accountReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_reviewed_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'reference_id')
            ->where('reference_type', 'valuation');
    }
}
