<?php

namespace App\Services;

use App\Models\Property;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\PropertyDiscoveryEvidence;
use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Canonical single-property aggregate.
 *
 * One property is identified by its source-system property_id, with
 * document_number and bill_id accepted as fallbacks. The aggregate spans the
 * whole lifecycle - discovery, valuation (new property and reassessment) and
 * the resulting bill - so the Property View never has to stitch several
 * unrelated screens together.
 *
 * Two behaviours are deliberately load-bearing:
 *
 *  - A property that has not been registered yet has no bill. The aggregate
 *    therefore resolves discoveries and valuations from the requested key
 *    directly, not from identifiers derived off the bill, otherwise the most
 *    common case (a discovery still in progress) returns an empty profile.
 *
 *  - Record scope is evaluated in the keyspace each record actually lives in.
 *    Bill identifiers are never compared against discovery identifiers.
 */
class PropertyProfileService
{
    public function __construct(private DiscoveryPresenter $presenter) {}

    /**
     * Build the aggregate, or throw 403 when the caller may not see the
     * property at all. Individual records inside the aggregate are then
     * filtered by the same scope rules, so a caller who can reach the property
     * still only receives the records they are entitled to.
     *
     * @throws HttpException
     */
    public function build(User $user, ?string $propertyId, ?string $documentNumber, ?int $billId, ?string $sourceReference = null): array
    {
        // keyed by lookup name, NOT reindexed: the column map is keyed by the
        // same names, so array_values() here would silently resolve nothing
        $keys = array_filter([
            'property_id' => $propertyId,
            'document_number' => $documentNumber,
            'bill_id' => $billId,
        ], fn ($v) => $v !== null && $v !== '');

        // The Source Reference ID is the permanent property identity, so it is
        // the most precise lookup there is. It resolves to the property_ref key,
        // which every linked record carries, rather than being treated as yet
        // another loose search term.
        if ($sourceReference !== null && trim($sourceReference) !== '') {
            $property = app(PropertyIdentityService::class)->findByReference($sourceReference);

            if (! $property) {
                throw new HttpException(404, 'No property matches that Source Reference ID.');
            }

            $keys = ['property_ref' => $property->id];
        }

        if ($keys === []) {
            throw new HttpException(422, 'Provide property_id, document_number, bill_id, or source_reference.');
        }

        $bills = $this->billsFor($keys);
        $discoveries = $this->discoveriesFor($keys, $bills);
        $valuations = $this->valuationsFor($keys, $bills, $discoveries);

        if (! $this->canReachProperty($user, $discoveries, $bills, $valuations)) {
            throw new HttpException(403, 'You do not have access to this property profile.');
        }

        // from here on, filter rather than abort: reaching the property is not
        // the same as being entitled to every record attached to it
        $discoveries = $discoveries
            ->filter(fn (PropertyDiscovery $d) => $this->canViewDiscovery($d, $user))
            ->values();
        $valuations = $valuations
            ->filter(fn (Valuation $v) => $this->canViewValuation($v, $user))
            ->values();
        $bills = $bills
            ->filter(fn (PropertyBill $b) => $this->canViewBill($b, $user, $discoveries))
            ->values();

        $primary = $this->primaryRecord($discoveries, $valuations, $bills);
        $identity = $this->identity($primary, $bills, $this->propertyIdentity($keys, $discoveries, $valuations, $bills));
        $evidence = $this->evidenceFor($discoveries);
        $tasks = $this->tasksFor($bills, $discoveries, $valuations);

        return [
            'identity' => $identity,
            'record' => [
                'record_type' => $primary['record_type'],
                'record_id' => $primary['id'],
                'reference' => $primary['reference'],
                'status' => $primary['status'],
                'discovery_id' => $discoveries->first()?->id,
                'valuation_id' => $valuations->first()?->id,
                'bill_id' => $bills->first()?->id,
            ],
            'current_task' => $tasks->first(),
            'value' => $this->value($primary, $discoveries, $valuations),
            'gps' => $this->gps($primary, $discoveries, $valuations, $bills, $identity['property_id']),
            'photos' => $this->photos($evidence),
            'evidence' => $this->documents($evidence),
            'bills' => $bills->map(fn (PropertyBill $b) => $this->bill($b))->all(),
            'discoveries' => $discoveries->map(fn (PropertyDiscovery $d) => $this->discovery($d))->all(),
            'valuations' => $valuations->map(fn (Valuation $v) => $this->valuation($v))->all(),
            'related' => [
                'discoveries' => $discoveries->count(),
                'valuations' => $valuations->count(),
                'reassessments' => $valuations->where('valuation_type', 'reassessment')->count(),
                'bills' => $bills->count(),
                'tasks' => $tasks->count(),
                'documents' => $evidence->count(),
            ],
            'as_of' => now()->toISOString(),
        ];
    }

    /* ---------- resolution ---------- */

    /**
     * A lookup key is a *concept*, not a column: the same key lives under a
     * different column name (or does not exist at all) on each record type.
     * property_bills keys the bill by `id`, property_discoveries has no bill
     * reference at all, and only valuations carry `bill_id`. Each resolver is
     * therefore given an explicit column map so a key can never be splatted
     * into a column that does not exist.
     */
    private const KEY_COLUMNS = [
        'bill' => ['property_id' => 'property_id', 'document_number' => 'document_number', 'bill_id' => 'id', 'property_ref' => 'property_ref_id'],
        'discovery' => ['property_id' => 'property_id', 'document_number' => 'document_number', 'property_ref' => 'property_ref_id'],
        'valuation' => ['property_id' => 'property_id', 'document_number' => 'document_number', 'bill_id' => 'bill_id', 'property_ref' => 'property_ref_id'],
    ];

    /**
     * @param  array<string, mixed>  $keys
     * @return array<string, mixed> key name => value, limited to columns this table has
     */
    private function columnsFor(string $recordType, array $keys): array
    {
        $map = self::KEY_COLUMNS[$recordType];

        return array_filter(
            array_intersect_key($keys, $map),
            fn ($v) => $v !== null && $v !== '',
        );
    }

    /** @param array<string, mixed> $keys */
    private function billsFor(array $keys)
    {
        $columns = $this->columnsFor('bill', $keys);

        if ($columns === []) {
            return collect();
        }

        return PropertyBill::query()
            ->where(function (Builder $q) use ($columns) {
                foreach ($columns as $key => $value) {
                    $q->orWhere(self::KEY_COLUMNS['bill'][$key], $value);
                }
            })
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Discoveries for the requested keys. Deliberately not derived from the
     * bill rows: an unregistered property has no bill and would otherwise
     * resolve to nothing.
     *
     * @param  array<string, mixed>  $keys
     * @return Collection<int, PropertyDiscovery>
     */
    private function discoveriesFor(array $keys, $bills)
    {
        $columns = $this->columnsFor('discovery', $keys);

        $query = PropertyDiscovery::query()->with([
            'discoverer:id,full_name',
            'currentOwner:id,full_name',
            'valuation:id,valuation_reference,status,valuation_officer_id',
            'valuation.valuationOfficer:id,full_name',
        ]);

        if ($columns !== []) {
            $query->where(function (Builder $q) use ($columns) {
                foreach ($columns as $key => $value) {
                    $q->orWhere(self::KEY_COLUMNS['discovery'][$key], $value);
                }
            });
        }

        // A bill whose TIN is known may have been registered from a discovery
        // that never carried the property_id, so fall back on taxpayer identity.
        $tin = $bills->pluck('tin')->filter()->unique();
        if ($tin->isNotEmpty()) {
            $query->orWhereIn('tin', $tin->all());
        }

        if ($columns === [] && $tin->isEmpty()) {
            return collect();
        }

        return $query->orderByDesc('id')->get();
    }

    /**
     * @param  array<string, mixed>  $keys
     * @return Collection<int, Valuation>
     */
    private function valuationsFor(array $keys, $bills, $discoveries)
    {
        $columns = $this->columnsFor('valuation', $keys);
        $linkedIds = $discoveries->pluck('valuation_id')->filter()->values();
        $billIds = $bills->pluck('id');

        if ($columns === [] && $linkedIds->isEmpty() && $billIds->isEmpty()) {
            return collect();
        }

        return Valuation::query()
            ->with('valuationOfficer:id,full_name')
            ->where(function (Builder $q) use ($columns, $linkedIds, $billIds) {
                foreach ($columns as $key => $value) {
                    $q->orWhere(self::KEY_COLUMNS['valuation'][$key], $value);
                }
                $q->orWhereIn('id', $linkedIds->all());
                $q->orWhereIn('bill_id', $billIds->all());
            })
            ->orderByDesc('id')
            ->get();
    }

    /* ---------- scope ---------- */

    private function isWide(User $user): bool
    {
        return in_array($user->scopeLevel(), ['section', 'division', 'system'], true)
            || $user->hasAnyPermission([
                'discovery.review', 'discovery.aarm_review', 'valuation.review',
                'valuation.approve', 'reports.view', 'dashboard.view_division',
            ]);
    }

    public function canViewDiscovery(PropertyDiscovery $d, User $user): bool
    {
        if ($this->isWide($user)) {
            return true;
        }
        if ($d->discovered_by === $user->id || $d->current_owner_id === $user->id) {
            return true;
        }
        if ($user->scopeLevel() === 'team' && $d->discovered_by) {
            if (User::where('id', $d->discovered_by)->where('supervisor_id', $user->id)->exists()) {
                return true;
            }
        }
        if ($d->valuation_id && Valuation::where('id', $d->valuation_id)
            ->where('valuation_officer_id', $user->id)->exists()) {
            return true;
        }

        return Task::where('assigned_to', $user->id)
            ->where('reference_type', 'property_discovery')
            ->where('reference_id', $d->id)
            ->exists();
    }

    public function canViewValuation(Valuation $v, User $user): bool
    {
        if ($this->isWide($user)) {
            return true;
        }
        if ($v->valuation_officer_id === $user->id) {
            return true;
        }
        if ($v->discovery_id && PropertyDiscovery::where('id', $v->discovery_id)
            ->where(fn (Builder $q) => $q->where('discovered_by', $user->id)
                ->orWhere('current_owner_id', $user->id))
            ->exists()) {
            return true;
        }

        return Task::where('assigned_to', $user->id)
            ->where('reference_type', 'valuation')
            ->where('reference_id', $v->id)
            ->exists();
    }

    public function canViewBill(PropertyBill $b, User $user, $discoveryCollection): bool
    {
        if ($this->isWide($user)) {
            return true;
        }
        if ($b->account_staff_id === $user->id || $b->assigned_enforcement_officer_id === $user->id) {
            return true;
        }
        if (Task::where('assigned_to', $user->id)
            ->where('reference_type', 'property_bill')
            ->where('reference_id', $b->id)
            ->exists()) {
            return true;
        }

        // a bill inherits reachability from any discovery the caller can see,
        // so a discovery officer can follow their record through to its bill
        return $discoveryCollection->contains(
            fn (PropertyDiscovery $d) => $d->property_id === $b->property_id
                || ($d->document_number !== null && $d->document_number === $b->document_number)
        );
    }

    /** 403 gate: does the caller reach this property through any linked record? */
    private function canReachProperty(User $user, $discoveries, $bills, $valuations): bool
    {
        if ($this->isWide($user)) {
            return true;
        }
        if ($discoveries->contains(fn (PropertyDiscovery $d) => $this->canViewDiscovery($d, $user))) {
            return true;
        }
        if ($valuations->contains(fn (Valuation $v) => $this->canViewValuation($v, $user))) {
            return true;
        }
        if ($bills->contains(fn (PropertyBill $b) => $this->canViewBill($b, $user, $discoveries))) {
            return true;
        }

        return false;
    }

    /* ---------- projection ---------- */

    /**
     * The record the Property View should present as "this property right
     * now": the most advanced in-flight stage wins, and terminal stages yield
     * to a live one.
     *
     * @return array{record_type: string, id: ?int, reference: ?string, status: ?string, row: mixed}
     */
    private function primaryRecord($discoveries, $valuations, $bills): array
    {
        $live = fn (?string $status) => ! in_array($status, [
            PropertyDiscovery::STATUS_COMPLETED,
            PropertyDiscovery::STATUS_CLOSED,
            PropertyDiscovery::STATUS_REJECTED,
            'Registered', 'Completed', 'Closed', 'Paid',
        ], true);

        $openDiscovery = $discoveries->first(fn (PropertyDiscovery $d) => $live($d->status));
        $openValuation = $valuations->first(fn (Valuation $v) => $live($v->status));
        $bill = $bills->first();

        if ($openValuation) {
            return [
                'record_type' => $openValuation->valuation_type === 'reassessment' ? 'reassessment' : 'valuation',
                'id' => $openValuation->id,
                'reference' => $openValuation->valuation_reference,
                'status' => $openValuation->status,
                'row' => $openValuation,
            ];
        }

        if ($openDiscovery) {
            return [
                'record_type' => 'discovery',
                'id' => $openDiscovery->id,
                'reference' => $openDiscovery->discovery_reference,
                'status' => $openDiscovery->status,
                'row' => $openDiscovery,
            ];
        }

        if ($openValuation ??= $valuations->first()) {
            return [
                'record_type' => $openValuation->valuation_type === 'reassessment' ? 'reassessment' : 'valuation',
                'id' => $openValuation->id,
                'reference' => $openValuation->valuation_reference,
                'status' => $openValuation->status,
                'row' => $openValuation,
            ];
        }

        if ($openDiscovery ??= $discoveries->first()) {
            return [
                'record_type' => 'discovery',
                'id' => $openDiscovery->id,
                'reference' => $openDiscovery->discovery_reference,
                'status' => $openDiscovery->status,
                'row' => $openDiscovery,
            ];
        }

        if ($bill) {
            return [
                'record_type' => 'bill',
                'id' => $bill->id,
                'reference' => $bill->document_number,
                'status' => $bill->case_status,
                'row' => $bill,
            ];
        }

        return ['record_type' => 'unknown', 'id' => null, 'reference' => null, 'status' => null, 'row' => null];
    }

    /**
     * The permanent property identity behind this aggregate.
     *
     * Resolved from the records themselves rather than created here: the
     * profile is a read model and must never mint an identity as a side effect
     * of somebody opening a page. A record that predates the registry (or one
     * whose created hook has not stamped it) still resolves by its
     * source-system property_id.
     */
    private function propertyIdentity(array $keys, $discoveries, $valuations, $bills): ?Property
    {
        $ids = collect([$bills, $discoveries, $valuations])
            ->flatMap(fn ($rows) => $rows->pluck('property_ref_id'))
            ->filter()
            ->unique();

        if ($ids->isNotEmpty()) {
            return Property::find($ids->first());
        }

        if (isset($keys['property_id'])) {
            return Property::query()
                ->whereRaw('UPPER(TRIM(property_id)) = ?', [Property::normalizeSourceId($keys['property_id'])])
                ->first();
        }

        return null;
    }

    private function identity(array $primary, $bills, ?Property $property = null): array
    {
        $row = $primary['row'];
        $bill = $bills->first();

        $pick = function (...$candidates) {
            foreach ($candidates as $c) {
                if ($c !== null && $c !== '') {
                    return $c;
                }
            }

            return null;
        };

        $isDiscovery = $row instanceof PropertyDiscovery;
        $isValuation = $row instanceof Valuation;

        return [
            'source_reference' => $property?->source_reference,
            'property_id' => $pick(
                $isDiscovery ? $row->property_id : null,
                $isValuation ? $row->property_id : null,
                $bill?->property_id,
                $row instanceof PropertyBill ? $row->property_id : null
            ),
            'document_number' => $pick(
                $isDiscovery ? $row->document_number : null,
                $isValuation ? $row->document_number : null,
                $bill?->document_number
            ),
            'tin' => $pick($isDiscovery ? $row->tin : null, $isValuation ? $row->tin : null, $bill?->tin),
            'owner_name' => $pick($isDiscovery ? $row->owner_name : null, $isValuation ? $row->owner_name : null, $bill?->taxpayer_name),
            'owner_contact' => $isDiscovery ? $row->owner_contact : null,
            'property_address' => $pick(
                $isDiscovery ? $row->property_address : null,
                $isValuation ? $row->property_address : null,
                $bill?->property_address
            ),
            'county' => $isDiscovery ? $row->county : null,
            'district' => $isDiscovery ? $row->district : null,
            'city_town' => $isDiscovery ? $row->city_town : null,
            'community' => $isDiscovery ? $row->community : null,
            'street' => $isDiscovery ? $row->street : null,
            'house_number' => $isDiscovery ? $row->house_number : null,
            'property_classification' => $pick(
                $isDiscovery ? $row->property_classification : null,
                $isValuation ? $row->property_classification : null,
                $bill?->property_classification
            ),
            'property_type' => $pick($isDiscovery ? $row->property_type : null, $bill?->property_type),
            'occupancy_use' => $isDiscovery ? $row->occupancy_use : null,
            'description' => $isDiscovery ? $row->description : null,
        ];
    }

    /**
     * Values are reported side by side with their source and never collapsed
     * into one ambiguous "property value" field.
     */
    private function value(array $primary, $discoveries, $valuations): array
    {
        $discovery = $discoveries->first();
        $valuation = $valuations->first();
        $bill = null;

        $history = [];
        if ($discovery) {
            if ($discovery->declared_value !== null) {
                $history[] = $this->valueRow('Self Declared', $discovery->declared_value, 'Discovery', $discovery->discovery_date, 'Original');
            }
            if ($discovery->documentary_value !== null) {
                $history[] = $this->valueRow('Documentary', $discovery->documentary_value, 'Documentation', $discovery->discovery_date, 'Supporting');
            }
        }
        if ($valuation) {
            if ($valuation->assessed_value !== null) {
                $history[] = $this->valueRow(
                    $valuation->valuation_type === 'reassessment' ? 'Reassessment' : 'Valuation',
                    $valuation->assessed_value,
                    'Valuation',
                    $valuation->assessment_date,
                    $valuation->ac_decision === 'Approved' ? 'Approved' : 'Assessed'
                );
            }
            if ($valuation->reassessed_value !== null) {
                $history[] = $this->valueRow('Reassessed', $valuation->reassessed_value, 'Valuation', $valuation->assessment_date, 'Reassessed');
            }
        }

        return [
            'declaration_type' => $discovery?->value_declaration_type,
            'declaration_label' => $this->declarationLabel($discovery?->value_declaration_type),
            'declared_value' => $this->money($discovery?->declared_value),
            'documentary_value' => $this->money($discovery?->documentary_value),
            'declared_assessed_value' => $this->money($discovery?->declared_assessed_value),
            'official_assessed_value' => $this->money($discovery?->official_assessed_value),
            'assessed_value' => $this->money($valuation?->assessed_value),
            'reassessed_value' => $this->money($valuation?->reassessed_value),
            'annual_tax' => $this->money($valuation?->annual_tax),
            'history' => $history,
        ];
    }

    private function valueRow(string $type, $amount, string $source, $date, string $status): array
    {
        return [
            'type' => $type,
            'amount' => (float) $amount,
            'source' => $source,
            'date' => $date?->toDateString(),
            'status' => $status,
        ];
    }

    private function money($value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function declarationLabel(?string $declaration): string
    {
        return $this->presenter->declarationLabel($declaration);
    }

    /**
     * Candidate GPS and official GPS are reported separately. A coordinate
     * captured in the field is never presented as the official property
     * coordinate unless it has actually been certified.
     *
     * gps_verified is a nullable string column holding 'yes', 'no' or NULL
     * (pending) - it is NOT a boolean, so a rejected coordinate ('no') must
     * never be cast through (bool) and reported as certified.
     */
    private function gps(array $primary, $discoveries, $valuations, $bills, ?string $propertyId): array
    {
        $coordinate = null;
        $accuracy = null;
        $capturedAt = null;
        $certified = false;
        $certifiedBy = null;
        $certifiedAt = null;
        $certRemarks = null;
        $source = null;

        $candidates = [
            ['row' => $primary['row'], 'source' => 'Current record'],
            ['row' => $discoveries->firstWhere(fn ($d) => $d->gps_coordinate), 'source' => 'Discovery'],
            ['row' => $valuations->firstWhere(fn ($v) => $v->gps_coordinate), 'source' => 'Valuation'],
            ['row' => $bills->firstWhere(fn ($b) => $b->gps_coordinate), 'source' => 'Bill'],
        ];

        foreach ($candidates as $candidate) {
            $r = $candidate['row'];
            if ($r === null || ! $r->gps_coordinate) {
                continue;
            }
            $coordinate = $r->gps_coordinate;
            $accuracy = $r->gps_accuracy !== null ? (float) $r->gps_accuracy : null;
            $capturedAt = $r->gps_captured_at ?? null;
            $certified = $this->isGpsCertified($r->gps_verified);
            $certifiedBy = $r->gps_certified_by ?? null;
            $certifiedAt = $r->gps_certified_at ?? null;
            $certRemarks = $r->gps_cert_remarks ?? null;
            $source = $candidate['source'];

            break;
        }

        // The certified property location lives in gps_locations, which is
        // keyed uniquely by property_id. It wins over any field-collected
        // coordinate, which only ever remains a candidate.
        $official = $this->officialGps($propertyId);

        if ($official !== null) {
            $coordinate = $official['coordinate'];
            $accuracy = $official['accuracy'];
            $source = 'Certified property location';
            $certified = true;
            $certifiedAt = $official['certified_at'];
            $certifiedBy = $official['certified_by'];
        }

        return [
            'coordinate' => $coordinate,
            'accuracy' => $accuracy,
            'source' => $source,
            'captured_at' => $capturedAt?->toISOString(),
            'certified' => $certified,
            'certified_by' => $certifiedBy,
            'certified_at' => $certifiedAt?->toISOString(),
            'cert_remarks' => $certRemarks,
            'is_official' => $certified,
            'official_label' => $certified
                ? 'Official Property GPS'
                : 'Official GPS Not Yet Certified',
        ];
    }

    /** gps_verified is 'yes' | 'no' | NULL; only an explicit 'yes' certifies. */
    private function isGpsCertified($value): bool
    {
        return is_string($value) && strtolower(trim($value)) === 'yes';
    }

    /**
     * The certified location of record, from gps_locations. Only rows that have
     * actually been verified count as official.
     *
     * @return array{coordinate: string, accuracy: ?float, certified_by: ?int, certified_at: mixed}|null
     */
    private function officialGps(?string $propertyId): ?array
    {
        if ($propertyId === null || $propertyId === '') {
            return null;
        }

        $location = DB::table('gps_locations')
            ->where('property_id', $propertyId)
            ->whereIn('verification_status', ['verified', 'approved'])
            ->orderByDesc('id')
            ->first();

        if ($location === null) {
            return null;
        }

        return [
            'coordinate' => $location->latitude.', '.$location->longitude,
            'accuracy' => $location->accuracy !== null ? (float) $location->accuracy : null,
            'certified_by' => $location->certified_by !== null ? (int) $location->certified_by : null,
            'certified_at' => $location->updated_at !== null
                ? Carbon::parse($location->updated_at)
                : null,
        ];
    }

    /* ---------- evidence ---------- */

    private function evidenceFor($discoveries)
    {
        if ($discoveries->isEmpty()) {
            return collect();
        }

        return PropertyDiscoveryEvidence::query()
            ->whereIn('discovery_id', $discoveries->pluck('id'))
            ->with('capturedBy:id,full_name')
            ->orderBy('evidence_type')
            ->orderBy('sequence_no')
            ->get();
    }

    /** Up to three property photographs, metadata plus a gated URL. */
    private function photos($evidence)
    {
        return $evidence
            ->where('evidence_type', PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO)
            ->sortBy('sequence_no')
            ->take(3)
            ->map(fn (PropertyDiscoveryEvidence $e) => [
                'id' => $e->id,
                'sequence_no' => (int) $e->sequence_no,
                'url' => "/api/v1/discoveries/{$e->discovery_id}/evidence/{$e->id}/file",
                'original_name' => $e->original_name,
                'mime_type' => $e->mime_type,
                'capture_source' => $e->capture_source,
                'captured_by' => $e->relationLoaded('capturedBy') ? $e->capturedBy?->full_name : null,
                'captured_at' => $e->captured_at?->toISOString(),
                'gps_latitude' => $e->gps_latitude !== null ? (float) $e->gps_latitude : null,
                'gps_longitude' => $e->gps_longitude !== null ? (float) $e->gps_longitude : null,
                // The evidence row stores no accuracy of its own; the property-level
                // accuracy is reported once under `gps` rather than invented here.
                'is_primary' => (int) $e->sequence_no === 1,
            ])
            ->values()
            ->all();
    }

    /** Ownership/deed and other documents. Raw storage paths are never exposed. */
    private function documents($evidence)
    {
        return $evidence
            ->where('evidence_type', '!=', PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO)
            ->map(fn (PropertyDiscoveryEvidence $e) => [
                'id' => $e->id,
                'evidence_type' => $e->evidence_type,
                'original_name' => $e->original_name,
                'url' => "/api/v1/discoveries/{$e->discovery_id}/evidence/{$e->id}/file",
                'mime_type' => $e->mime_type,
                'size_bytes' => (int) $e->size_bytes,
                'capture_source' => $e->capture_source,
                'captured_by' => $e->relationLoaded('capturedBy') ? $e->capturedBy?->full_name : null,
                'captured_at' => $e->captured_at?->toISOString(),
            ])
            ->values()
            ->all();
    }

    /* ---------- tasks ---------- */

    private function tasksFor($bills, $discoveries, $valuations)
    {
        $refs = [];
        foreach ($bills as $b) {
            $refs[] = ['property_bill', $b->id];
        }
        foreach ($discoveries as $d) {
            $refs[] = ['property_discovery', $d->id];
        }
        foreach ($valuations as $v) {
            $refs[] = ['valuation', $v->id];
        }

        if ($refs === []) {
            return collect();
        }

        $query = Task::query()->with('assignedTo:id,full_name')
            ->where(function (Builder $q) use ($refs) {
                foreach ($refs as [$type, $id]) {
                    $q->orWhere(fn (Builder $inner) => $inner
                        ->where('reference_type', $type)
                        ->where('reference_id', $id));
                }
            })
            ->active();

        return $query
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->get()
            ->map(fn (Task $t) => [
                'id' => $t->id,
                'task_type' => $t->task_type,
                'status' => $t->status,
                'reference_type' => $t->reference_type,
                'reference_id' => (int) $t->reference_id,
                'assigned_to' => $t->relationLoaded('assignedTo') ? $t->assignedTo?->full_name : null,
                'due_date' => $t->due_date?->toDateString(),
            ])
            ->values();
    }

    /* ---------- row projections ---------- */

    private function discovery(PropertyDiscovery $d): array
    {
        // the same projection every discovery endpoint returns, so a record
        // never renders one way in a list and another way in the Property View
        return $this->presenter->present($d);
    }

    private function valuation(Valuation $v): array
    {
        return [
            'id' => $v->id,
            'valuation_reference' => $v->valuation_reference,
            'valuation_type' => $v->valuation_type,
            'record_type' => $v->valuation_type === 'reassessment' ? 'reassessment' : 'valuation',
            'status' => $v->status,
            'property_id' => $v->property_id,
            'document_number' => $v->document_number,
            'bill_id' => $v->bill_id,
            'discovery_id' => $v->discovery_id,
            'owner_name' => $v->owner_name,
            'tin' => $v->tin,
            'property_classification' => $v->property_classification,
            'land_dimensions' => $v->land_dimensions,
            'building_specs' => $v->building_specs,
            'construction_year' => $v->construction_year,
            'condition' => $v->condition,
            'declared_value' => $this->money($v->declared_value),
            'assessed_value' => $this->money($v->assessed_value),
            'reassessed_value' => $this->money($v->reassessed_value),
            'annual_tax' => $this->money($v->annual_tax),
            'total_tax_payable' => $this->money($v->total_tax_payable),
            'assessment_date' => $v->assessment_date?->toDateString(),
            'manager_decision' => $v->manager_decision,
            'ac_decision' => $v->ac_decision,
            'registration_status' => $v->registration_status,
            'valuation_officer' => $v->relationLoaded('valuationOfficer') ? $v->valuationOfficer?->full_name : null,
            'created_at' => $v->created_at?->toISOString(),
        ];
    }

    private function bill(PropertyBill $b): array
    {
        return [
            'id' => $b->id,
            'document_number' => $b->document_number,
            'property_id' => $b->property_id,
            'tin' => $b->tin,
            'taxpayer_name' => $b->taxpayer_name,
            'tax_period' => $b->tax_period,
            'property_classification' => $b->property_classification,
            'property_address' => $b->property_address,
            'assessed_value' => $this->money($b->assessed_value),
            'tax_amount' => $this->money($b->tax_amount),
            'interest_charged' => $this->money($b->interest_charged),
            'penalty_charged' => $this->money($b->penalty_charged),
            'total_tax_due' => $this->money($b->total_tax_due),
            'outstanding_balance' => $this->money($b->outstanding_balance),
            'payment_status' => $b->payment_status,
            'case_status' => $b->case_status,
            'delivery_status' => $b->delivery_status,
            'date_logged' => $b->date_logged?->toDateString(),
        ];
    }
}
