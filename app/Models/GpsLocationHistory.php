<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only GPS certification log. Every captured/proposed location event is
 * recorded here (proposed, approved, rejected, "not the property"), preserving
 * the previous official coordinate alongside the freshly proposed one.
 */
class GpsLocationHistory extends Model
{
    protected $table = 'gps_location_history';

    protected $fillable = [
        'property_id',
        'tin',
        'previous_latitude',
        'previous_longitude',
        'new_latitude',
        'new_longitude',
        'accuracy',
        'source',
        'verification_status',
        'certification_decision',
        'remarks',
        'review_remarks',
        'captured_by',
        'certified_by',
        'reviewed_by',
        'approved_by',
        'task_id',
        'visit_id',
        'valuation_id',
        'discovery_id',
        'device_metadata',
        'captured_at',
        'certified_at',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_latitude' => 'decimal:7',
            'previous_longitude' => 'decimal:7',
            'new_latitude' => 'decimal:7',
            'new_longitude' => 'decimal:7',
            'accuracy' => 'decimal:2',
            'captured_at' => 'datetime',
            'certified_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    public function certifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'certified_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
