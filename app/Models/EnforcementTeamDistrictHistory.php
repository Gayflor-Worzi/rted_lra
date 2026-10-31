<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnforcementTeamDistrictHistory extends Model
{
    protected $table = 'enforcement_team_district_history';

    protected $fillable = [
        'team_id',
        'district_id',
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

    public function team(): BelongsTo
    {
        return $this->belongsTo(EnforcementTeam::class, 'team_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(EnforcementDistrict::class, 'district_id');
    }
}
