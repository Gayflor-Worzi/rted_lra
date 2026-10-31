<?php

namespace App\Models;

use App\Models\Concerns\GeneratesInternalReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeQuery extends Model
{
    use GeneratesInternalReference;

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
            if (empty($q->status)) {
                $q->status = 'Open';
            }
        });
    }

    public static function referencePrefix(): string
    {
        return 'MEQ';
    }

    public static function referenceField(): string
    {
        return 'query_reference';
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
