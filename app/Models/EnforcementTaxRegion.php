<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnforcementTaxRegion extends Model
{
    protected $fillable = [
        'name',
        'code',
        'classification',
        'description',
        'is_active',
        'supervisor_id',
        'created_by',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function districts(): HasMany
    {
        return $this->hasMany(EnforcementDistrict::class, 'region_id');
    }

    public function supervisorHistory(): HasMany
    {
        return $this->hasMany(EnforcementTaxRegionSupervisorHistory::class, 'region_id');
    }
}
