<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Discovery-attached evidence — ownership/deed documents (OWNERSHIP_DOCUMENT)
 * and the up-to-three property photographs (PROPERTY_PHOTO with sequence_no
 * 1-3). Each row is independently stored with its capture metadata so evidence
 * can be gated by record scope and workflow authorization.
 */
class PropertyDiscoveryEvidence extends Model
{
    public const TYPE_OWNERSHIP_DOCUMENT = 'OWNERSHIP_DOCUMENT';

    public const TYPE_PROPERTY_PHOTO = 'PROPERTY_PHOTO';

    public const TYPES = [
        self::TYPE_OWNERSHIP_DOCUMENT,
        self::TYPE_PROPERTY_PHOTO,
    ];

    public const SOURCE_CAMERA = 'CAMERA';

    public const SOURCE_UPLOAD = 'UPLOAD';

    public const SYNC_PENDING = 'PENDING';

    public const SYNC_SYNCED = 'SYNCED';

    public const SYNC_FAILED = 'FAILED';

    protected $fillable = [
        'discovery_id',
        'property_id',
        'evidence_type',
        'sequence_no',
        'file_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'capture_source',
        'captured_at',
        'captured_by',
        'gps_latitude',
        'gps_longitude',
        'sync_status',
    ];

    protected function casts(): array
    {
        return [
            'sequence_no' => 'integer',
            'size_bytes' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    public function discovery(): BelongsTo
    {
        return $this->belongsTo(PropertyDiscovery::class, 'discovery_id');
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }
}
