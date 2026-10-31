<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnforcementOrgRecommendation extends Model
{
    protected $fillable = [
        'change_type',
        'team_id',
        'payload',
        'previous_values',
        'submitted_by',
        'status',
        'decided_by',
        'decision_notes',
        'submitted_at',
        'decided_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'previous_values' => 'array',
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(EnforcementTeam::class, 'team_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
