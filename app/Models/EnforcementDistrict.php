<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnforcementDistrict extends Model
{
    protected $fillable = ['name', 'is_active', 'created_by', 'region_id'];

    protected $casts = ['is_active' => 'boolean'];

    public function region(): BelongsTo
    {
        return $this->belongsTo(EnforcementTaxRegion::class, 'region_id');
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(EnforcementTeam::class, 'enforcement_team_district', 'district_id', 'team_id');
    }

    public function regionHistory(): HasMany
    {
        return $this->hasMany(EnforcementDistrictRegionHistory::class, 'district_id');
    }
}
