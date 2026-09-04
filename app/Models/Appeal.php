<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appeal extends Model
{
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
            if (empty($appeal->appeal_reference)) {
                $year = date('Y');
                $last = self::where('appeal_reference', 'like', "APP-{$year}-%")->max('id');
                $appeal->appeal_reference = sprintf('APP-%s-%05d', $year, ($last ?: 0) + 1);
            }
            if (empty($appeal->status)) {
                $appeal->status = 'Submitted';
            }
        });
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PropertyBill::class, 'bill_id');
    }
}