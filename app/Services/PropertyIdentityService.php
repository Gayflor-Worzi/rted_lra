<?php

namespace App\Services;

use App\Models\Property;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\Valuation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The single authority on property identity.
 *
 * Every discovery, valuation and bill is linked to one `properties` row. The
 * rules are deliberately narrow:
 *
 *  - the source system's property_id is authoritative: two records carrying the
 *    same normalized property_id are the same property;
 *  - an existing relation (valuation -> discovery, valuation -> bill) is
 *    authoritative;
 *  - nothing else is. Similar owner names, addresses, classifications or TINs
 *    never merge two identities — an unlinked record simply gets its own.
 *
 * Linking is structural, not a business transition, so it is written quietly:
 * the workflow audit trail records decisions, not identity bookkeeping.
 */
class PropertyIdentityService
{
    public function forDiscovery(PropertyDiscovery $discovery): Property
    {
        return $this->attach(
            $discovery,
            fn () => $this->existing($discovery->property_ref_id)
                ?? $this->findOrCreateForSourceId($discovery->property_id)
        );
    }

    public function forValuation(Valuation $valuation): Property
    {
        return $this->attach($valuation, function () use ($valuation) {
            if ($existing = $this->existing($valuation->property_ref_id)) {
                return $existing;
            }

            if ($valuation->discovery_id
                && ($discovery = PropertyDiscovery::find($valuation->discovery_id))) {
                return $this->forDiscovery($discovery);
            }

            if ($valuation->bill_id && ($bill = PropertyBill::find($valuation->bill_id))) {
                return $this->forBill($bill);
            }

            return $this->findOrCreateForSourceId($valuation->property_id);
        });
    }

    public function forBill(PropertyBill $bill): Property
    {
        return $this->attach($bill, function () use ($bill) {
            if ($existing = $this->existing($bill->property_ref_id)) {
                return $existing;
            }

            if (trim((string) $bill->property_id) !== '') {
                return $this->findOrCreateForSourceId($bill->property_id);
            }

            // A bill produced by a valuation belongs to that valuation's
            // property even before the source system issues a Property ID.
            $linked = Valuation::query()
                ->where('bill_id', $bill->id)
                ->whereNotNull('property_ref_id')
                ->orderBy('id')
                ->first();

            if ($linked && ($property = $this->existing($linked->property_ref_id))) {
                return $property;
            }

            return $this->createIdentity(null);
        });
    }

    /**
     * Record the source-system Property ID on the property and every record
     * already linked to it. Called at the registration step, which is the first
     * moment the identifier exists.
     */
    public function recordSourcePropertyId(Model $record, string $propertyId): Property
    {
        $propertyId = trim($propertyId);
        $property = $record instanceof PropertyDiscovery ? $this->forDiscovery($record)
            : ($record instanceof Valuation ? $this->forValuation($record)
                : $this->forBill($record));

        if ($propertyId === '') {
            return $property;
        }

        $current = Property::normalizeSourceId($property->property_id);
        $incoming = Property::normalizeSourceId($propertyId);

        if ($current === $incoming) {
            return $property;
        }

        // The property already carries a *different* source identifier. That is
        // a conflict, not a rename: do not silently repoint a whole lifecycle.
        if ($current !== '') {
            return $property;
        }

        $other = $this->findBySourceId($propertyId);

        if ($other && $other->id !== $property->id) {
            // The same authoritative source property already has an identity
            // (e.g. a bill was logged before its discovery was registered).
            // Repoint the records; the emptied identity row is kept, never
            // deleted, so no reference is lost.
            $this->repoint($property->id, $other->id);
            $property = $other;
        } else {
            $property->forceFill([
                'property_id' => $propertyId,
            ])->saveQuietly();
        }

        $this->propagateSourceId($property, $propertyId);

        return $property;
    }

    public function findByReference(string $reference): ?Property
    {
        return Property::query()
            ->whereRaw('UPPER(TRIM(source_reference)) = ?', [mb_strtoupper(trim($reference))])
            ->first();
    }

    /** @param  callable(): Property  $resolve */
    private function attach(Model $record, callable $resolve): Property
    {
        $property = $resolve();

        if ((int) $record->property_ref_id !== $property->id) {
            $record->forceFill(['property_ref_id' => $property->id])->saveQuietly();
        }

        return $property;
    }

    private function existing(?int $id): ?Property
    {
        return $id ? Property::find($id) : null;
    }

    private function findOrCreateForSourceId(?string $propertyId): Property
    {
        $normalized = Property::normalizeSourceId($propertyId);

        if ($normalized === '') {
            return $this->createIdentity(null);
        }

        if ($existing = $this->findBySourceId($propertyId)) {
            return $existing;
        }

        try {
            return $this->createIdentity(trim((string) $propertyId));
        } catch (QueryException $e) {
            // Concurrent create of the same source property: the unique index
            // is the arbiter, the loser adopts the winner's identity.
            if ($existing = $this->findBySourceId($propertyId)) {
                return $existing;
            }

            throw $e;
        }
    }

    private function findBySourceId(?string $propertyId): ?Property
    {
        $normalized = Property::normalizeSourceId($propertyId);

        if ($normalized === '') {
            return null;
        }

        return Property::query()
            ->whereRaw('UPPER(TRIM(property_id)) = ?', [$normalized])
            ->first();
    }

    private function createIdentity(?string $propertyId): Property
    {
        return Property::create([
            'property_id' => $propertyId !== null && trim($propertyId) !== '' ? trim($propertyId) : null,
        ]);
    }

    private function repoint(int $fromPropertyId, int $toPropertyId): void
    {
        foreach (['property_discoveries', 'valuations', 'property_bills'] as $table) {
            DB::table($table)
                ->where('property_ref_id', $fromPropertyId)
                ->update(['property_ref_id' => $toPropertyId]);
        }
    }

    /** Keep the source identifier consistent on the linked records. */
    private function propagateSourceId(Property $property, string $propertyId): void
    {
        foreach (['property_discoveries', 'valuations', 'property_bills'] as $table) {
            DB::table($table)
                ->where('property_ref_id', $property->id)
                ->where(fn ($q) => $q->whereNull('property_id')->orWhere('property_id', ''))
                ->update(['property_id' => $propertyId]);
        }
    }
}
