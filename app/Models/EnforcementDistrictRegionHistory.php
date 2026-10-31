<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnforcementDistrictRegionHistory extends Model
{
    protected $table = 'enforcement_district_region_history';

    protected $fillable = [
        'district_id',
        'region_id',
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

    public function district(): BelongsTo
    {
        return $this->belongsTo(EnforcementDistrict::class, 'district_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(EnforcementTaxRegion::class, 'region_id');
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
