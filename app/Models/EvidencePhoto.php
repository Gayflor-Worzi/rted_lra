<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Searchable, auditable photo evidence attached to a bill / task / visit /
 * valuation. Photo reference is system-generated (PHOTO-YYYY-#####); Property ID
 * is always the LITAS identifier and is never generated.
 */
class EvidencePhoto extends Model
{
    public const TYPES = [
        'PROPERTY_FULL_VIEW',
        'BILL_DELIVERY',
        'WARNING_NOTICE',
        'PREMISES',
        'SEIZURE',
        'CLOSURE',
        'OTHER',
    ];

    protected $fillable = [
        'photo_reference',
        'photo_type',
        'bill_id',
        'task_id',
        'visit_id',
        'valuation_id',
        'discovery_id',
        'property_id',
        'officer_id',
        'file_path',
        'original_name',
        'mime',
        'size_bytes',
        'gps_coordinate',
        'captured_at',
        'uploaded_at',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'uploaded_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EvidencePhoto $photo) {
            if (empty($photo->photo_reference)) {
                $photo->photo_reference = self::nextReference();
            }
            if (empty($photo->uploaded_at)) {
                $photo->uploaded_at = now();
            }
        });
    }

    public static function nextReference(): string
    {
        $year = date('Y');
        $last = self::where('photo_reference', 'like', "PHOTO-{$year}-%")->max('id');

        return sprintf('PHOTO-%s-%05d', $year, ($last ?: 0) + 1);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PropertyBill::class, 'bill_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(EnforcementVisit::class, 'visit_id');
    }

    public function valuation(): BelongsTo
    {
        return $this->belongsTo(Valuation::class, 'valuation_id');
    }

    public function discovery(): BelongsTo
    {
        return $this->belongsTo(PropertyDiscovery::class, 'discovery_id');
    }

    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'officer_id');
    }
}
