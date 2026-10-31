<?php

namespace App\Models;

use App\Models\Concerns\GeneratesInternalReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Permanent property identity (Source Reference ID).
 *
 * One row per underlying property. The `source_reference` (PRP-YYYY-#####) is
 * assigned once, at first recording, and never changes; the section-specific
 * identifiers (ND-…, VAL-…, source Property ID, Document #) stay on their own
 * records and are linked to this identity rather than replaced by it.
 *
 * This registry deliberately holds identity only. Owner, address,
 * classification and value remain on the discovery / valuation / bill rows so
 * there is exactly one copy of each business fact.
 */
class Property extends Model
{
    use GeneratesInternalReference;

    protected $fillable = [
        'property_id',
    ];

    public static function referencePrefix(): string
    {
        return 'PRP';
    }

    public static function referenceField(): string
    {
        return 'source_reference';
    }

    public function discoveries(): HasMany
    {
        return $this->hasMany(PropertyDiscovery::class, 'property_ref_id')->orderBy('id');
    }

    public function valuations(): HasMany
    {
        return $this->hasMany(Valuation::class, 'property_ref_id')->orderBy('id');
    }

    public function bills(): HasMany
    {
        return $this->hasMany(PropertyBill::class, 'property_ref_id')->orderBy('id');
    }

    /**
     * Normalized form used to compare source-system property IDs. The source
     * system is not case-stable, and legacy rows were entered by hand, so the
     * same property can appear as 'p-001' and 'P-001 '.
     */
    public static function normalizeSourceId(?string $propertyId): string
    {
        return mb_strtoupper(trim((string) $propertyId));
    }
}
