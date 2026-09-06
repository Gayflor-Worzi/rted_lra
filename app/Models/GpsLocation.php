<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Current official (verified) property location — one row per LITAS Property ID.
 * Never auto-populated from a visit capture; only written through explicit GPS
 * certification (gps.update) or an approved gps.approve decision.
 */
class GpsLocation extends Model
{
    protected $table = 'gps_locations';

    protected $fillable = [
        'property_id',
        'tin',
        'latitude',
        'longitude',
        'accuracy',
        'source',
        'verification_status',
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
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy' => 'decimal:2',
            'captured_at' => 'datetime',
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

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}