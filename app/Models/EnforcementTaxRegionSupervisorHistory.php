<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnforcementTaxRegionSupervisorHistory extends Model
{
    protected $table = 'enforcement_tax_region_supervisor_history';

    protected $fillable = [
        'region_id',
        'supervisor_id',
        'assigned_by',
        'ended_by',
        'reason',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(EnforcementTaxRegion::class, 'region_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }
}
