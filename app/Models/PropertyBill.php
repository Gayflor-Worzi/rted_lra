<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * RETD Bill Register — a log of LITAS-generated bills.
 * Document # / Property ID are LITAS identifiers; never generated here.
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
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'date_logged' => 'date',
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

    protected static function booted(): void
    {
        // Derived financial fields can never drift: whenever the assessed
        // amount changes (reassessment, adjustment) the outstanding balance
        // and payment status are recomputed from the verified payments.
        static::saving(function (PropertyBill $bill) {
            static::onRecalcFields($bill);
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
