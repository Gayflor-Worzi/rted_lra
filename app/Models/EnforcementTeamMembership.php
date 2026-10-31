<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnforcementTeamMembership extends Model
{
    protected $fillable = [
        'team_id',
        'officer_id',
        'supervisor_id',
        'primary_district_id',
        'prior_supervisor_id',
        'recommendation_id',
        'is_team_lead',
        'assigned_by',
        'ended_by',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'is_team_lead' => 'boolean',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(EnforcementTeam::class, 'team_id');
    }

    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'officer_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function primaryDistrict(): BelongsTo
    {
        return $this->belongsTo(EnforcementDistrict::class, 'primary_district_id');
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(EnforcementOrgRecommendation::class, 'recommendation_id');
    }
}
