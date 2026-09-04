<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EnforcementVisit extends Model
{
    use SoftDeletes;

    protected $table = 'enforcement_visits';

    // Delivery outcome labels — single source of truth. Analytics, dashboards,
    // staff targets and reports all read these exact strings (title-case).
    public const DELIVERY_DELIVERED = 'Delivered';
    public const DELIVERY_RETURNED = 'Returned';
    public const DELIVERY_OUT_FOR_DELIVERY = 'Out for Delivery';

    protected $fillable = [
        'visit_reference',
        'task_id',
        'bill_id',
        'document_number',
        'property_id',
        'officer_id',
        'visit_date',
        'visit_status',
        'delivery_status',
        'recipient_name',
        'recipient_contact',
        'gps_coordinate',
        'gps_accuracy',
        'gps_captured_at',
        'visit_photo',
        'remarks',
        'next_action',
        'next_followup_date',
        // snapshot — written once from the live bill at creation
        'snapshot_outstanding',
        'snapshot_payment_status',
        'snapshot_case_status',
    ];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
            'next_followup_date' => 'date',
            'gps_captured_at' => 'datetime',
            'gps_accuracy' => 'decimal:2',
            'snapshot_outstanding' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EnforcementVisit $visit) {
            if (empty($visit->visit_reference)) {
                $visit->visit_reference = self::nextReference();
            }
        });
    }

    /** Internal-only reference VIS-YYYY-##### — never confused with LITAS ids. */
    public static function nextReference(): string
    {
        $year = date('Y');
        $last = self::where('visit_reference', 'like', "VIS-{$year}-%")->max('id');

        return sprintf('VIS-%s-%05d', $year, ($last ?: 0) + 1);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'officer_id');
    }

    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'officer_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PropertyBill::class, 'bill_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(EvidencePhoto::class, 'visit_id');
    }
}
