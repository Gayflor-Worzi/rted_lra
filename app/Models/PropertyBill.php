<?php

namespace App\Models;

use App\Services\PropertyIdentityService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * RETD Bill Register — a log of source-system-generated bills.
 * Document # / Property ID are source-system identifiers; never generated here.
 */
class PropertyBill extends Model
{
    use SoftDeletes;

    // Delivery states (bill delivery workflow) — begins when the bill is logged
    public const DELIVERY_STAGES = [
        'Logged',
        'Out for Delivery',
        'Delivered',
        'Returned',
        'Undelivered',
        'Not Mailable',
        'Filed',
    ];

    // Payment statuses — claims vs verified are distinct
    public const PAYMENT_STATUSES = [
        'Unpaid',
        'Payment Claimed',
        'Verification Pending',
        'Partially Paid',
        'Paid',
        'Payment Rejected',
        'Payment Mismatch',
    ];

    // Operational case status (broken out from payment status on purpose)
    public const CASE_STATUSES = [
        'Logged',
        'Awaiting Assignment',
        'Assigned',
        'Out for Delivery',
        'Delivered',
        'Payment Follow-up',
        '30-Day Warning',
        '72-Hour Warning',
        'Escalated',
        'Under Verification',
        'Resolved',
        'Closed',
    ];

    public const RECIPIENT_DIRECT = 'Enforcement Officer';

    public const RECIPIENT_WALK_IN = 'Walk-in Taxpayer';

    public const RECIPIENT_EMAIL = 'Email';

    public const RECIPIENT_OVERSEAS = 'Overseas';

    protected $fillable = [
        'document_number',
        'property_id',
        'taxpayer_name',
        'tin',
        'property_classification',
        'property_address',
        'gps_coordinate',
        'gps_accuracy',
        'gps_captured_at',
        'assessed_value',
        'tax_amount',
        'interest_charged',
        'penalty_charged',
        'total_tax_due',
        'outstanding_balance',
        'tax_period',
        'property_type',
        'account_staff_id',
        'recipient_type',
        'recipient_name',
        'recipient_contact',
        'date_logged',
        'delivery_status',
        'delivery_date',
        'thirty_day_notice_date',
        'final_notice_date',
        'escalation_stage',
        'escalation_override_reason',
        'payment_status',
        'case_status',
        'approval_status',
        'property_photo',
        'assigned_enforcement_officer_id',
        'primary_tax_district_id',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'date_logged' => 'date',
            'gps_captured_at' => 'datetime',
            'delivery_date' => 'date',
            'thirty_day_notice_date' => 'date',
            'final_notice_date' => 'date',
            'assessed_value' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'interest_charged' => 'decimal:2',
            'penalty_charged' => 'decimal:2',
            'total_tax_due' => 'decimal:2',
            'outstanding_balance' => 'decimal:2',
        ];
    }

    public function accountStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_staff_id');
    }

    public function enforcementOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_enforcement_officer_id');
    }

    public function primaryTaxDistrict(): BelongsTo
    {
        return $this->belongsTo(EnforcementDistrict::class, 'primary_tax_district_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'reference_id')
            ->where('reference_type', 'property_bill');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(EnforcementVisit::class, 'bill_id');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(PaymentVerification::class, 'bill_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'bill_id');
    }

    public function valuations(): HasMany
    {
        return $this->hasMany(Valuation::class, 'bill_id');
    }

    /** Permanent property identity shared across every section. */
    public function propertyRef(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_ref_id');
    }

    protected static function booted(): void
    {
        // Derived financial fields can never drift: whenever the assessed
        // amount changes (reassessment, adjustment) the outstanding balance
        // and payment status are recomputed from the verified payments.
        static::saving(function (PropertyBill $bill) {
            static::onRecalcFields($bill);
        });

        // A logged bill is a record of an underlying property: give it the
        // permanent identity for its source Property ID, or the identity of the
        // valuation that produced it, or its own when neither exists.
        static::created(function (PropertyBill $bill) {
            app(PropertyIdentityService::class)->forBill($bill);
        });
    }

    private static function onRecalcFields(PropertyBill $bill): void
    {
        if (! $bill->isDirty(['total_tax_due', 'tax_amount', 'interest_charged', 'penalty_charged', 'assessed_value'])) {
            return;
        }

        $paid = (float) $bill->payments()->sum('amount');
        $total = (float) $bill->total_tax_due;
        $outstanding = max(0, $total - $paid);

        $derivedStatus = $outstanding <= 0
            ? 'Paid'
            : ($paid > 0 ? 'Partially Paid' : 'Unpaid');

        $bill->outstanding_balance = $outstanding;

        // Preserve an explicit status when one was set this same moment.
        if (! $bill->isDirty('payment_status')) {
            $bill->payment_status = $derivedStatus;
        }
    }

    /**
     * The one-assessment rule: find the live assessment for a property and tax
     * period.
     *
     * Property ID + Tax Period identify one property's assessment for a period;
     * a property is logged once per period. The TIN is compared only when both
     * sides carry one, because a bill recorded from a property discovery can
     * reach the register without a TIN. Treating "no TIN" as "a different
     * property" is what allowed the same assessment to be logged twice — the
     * record without the TIN was invisible to the duplicate check. Two bills
     * with two different, non-empty TINs remain distinct.
     *
     * Matching is case/whitespace-insensitive and never counts soft-deleted
     * bills.
     */
    public static function findEquivalentAssessment(
        string $propertyId,
        ?string $tin,
        ?string $taxPeriod,
        ?int $excludeId = null,
    ): ?PropertyBill {
        $propertyId = trim($propertyId);
        $tin = trim((string) $tin);
        $period = trim((string) $taxPeriod);

        if ($propertyId === '') {
            return null;
        }

        return static::query()
            ->whereRaw('UPPER(TRIM(COALESCE(property_id, \'\'))) = ?', [mb_strtoupper($propertyId)])
            ->whereRaw('UPPER(TRIM(COALESCE(tax_period, \'\'))) = ?', [mb_strtoupper($period)])
            ->when($tin !== '', function ($q) use ($tin) {
                // Only a stored, different TIN makes this a different assessment.
                $q->where(function ($t) use ($tin) {
                    $t->whereRaw('TRIM(COALESCE(tin, \'\')) = \'\'')
                        ->orWhereRaw('UPPER(TRIM(COALESCE(tin, \'\'))) = ?', [mb_strtoupper($tin)]);
                });
            })
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->orderBy('id')
            ->first();
    }

    /** Recompute outstanding balance from verified payments. */
    public function recalculateOutstanding(): void
    {
        $paid = (float) $this->payments()->sum('amount');
        $outstanding = max(0, (float) $this->total_tax_due - $paid);

        $this->outstanding_balance = $outstanding;
        $this->payment_status = $outstanding <= 0
            ? 'Paid'
            : ($paid > 0 ? 'Partially Paid' : 'Unpaid');

        $this->save();
    }
}
