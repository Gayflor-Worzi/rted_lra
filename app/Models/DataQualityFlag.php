<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * M&E data-quality flag raised against a bill. Flags feed the M&E monitoring
 * dashboard and account for data-quality work in performance targets.
 */
class DataQualityFlag extends Model
{
    public const SEVERITIES = ['Low', 'Moderate', 'High'];

    public const STATUSES = ['Open', 'In Progress', 'Resolved'];

    protected $fillable = [
        'bill_id',
        'issue',
        'severity',
        'status',
        'flagged_by',
        'flagged_at',
        'resolved_by',
        'resolved_at',
        'resolution_remarks',
    ];

    protected function casts(): array
    {
        return [
            'flagged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PropertyBill::class, 'bill_id');
    }

    public function flaggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'flagged_by');
    }
}