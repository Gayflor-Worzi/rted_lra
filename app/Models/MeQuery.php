<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeQuery extends Model
{
    protected $fillable = [
        'query_reference',
        'title',
        'description',
        'priority',
        'status',
        'raised_by',
        'assigned_to',
        'reference_type',
        'reference_id',
        'response',
        'responded_by',
        'responded_at',
        'closed_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MeQuery $q) {
            if (empty($q->query_reference)) {
                $year = date('Y');
                $last = self::where('query_reference', 'like', "MEQ-{$year}-%")->max('id');
                $q->query_reference = sprintf('MEQ-%s-%05d', $year, ($last ?: 0) + 1);
            }
            if (empty($q->status)) {
                $q->status = 'Open';
            }
        });
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}