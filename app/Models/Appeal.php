<?php

namespace App\Models;

use App\Models\Concerns\GeneratesInternalReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appeal extends Model
{
    use GeneratesInternalReference;

    protected $fillable = [
        'appeal_reference',
        'bill_id',
        'document_number',
        'property_id',
        'taxpayer_name',
        'reason',
        'description',
        'status',
        'decision',
        'decision_notes',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Appeal $appeal) {
            if (empty($appeal->status)) {
                $appeal->status = 'Submitted';
            }
        });
    }

    public static function referencePrefix(): string
    {
        return 'APP';
    }

    public static function referenceField(): string
    {
        return 'appeal_reference';
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PropertyBill::class, 'bill_id');
    }
}
