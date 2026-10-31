<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnforcementTeam extends Model
{
    protected $fillable = ['name', 'manager_id', 'supervisor_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function districts(): BelongsToMany
    {
        return $this->belongsToMany(EnforcementDistrict::class, 'enforcement_team_district', 'team_id', 'district_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(EnforcementTeamMembership::class, 'team_id');
    }

    public function currentMemberships(): HasMany
    {
        return $this->memberships()->whereNull('ended_at');
    }

    public function districtHistory(): HasMany
    {
        return $this->hasMany(EnforcementTeamDistrictHistory::class, 'team_id');
    }
}
