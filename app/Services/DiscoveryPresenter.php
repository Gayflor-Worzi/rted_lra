<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\PropertyDiscovery;
use App\Models\PropertyDiscoveryEvidence;

/**
 * The single presentation rule for a discovered property.
 *
 * This was previously a private method on DiscoveryController, which meant the
 * property profile had to grow its own, subtly different shape for the same
 * records. Two projections of one record is how clients end up rendering a
 * discovery differently depending on which screen they opened it from, so the
 * projection lives here and is shared by every endpoint that returns a
 * discovery.
 */
class DiscoveryPresenter
{
    public function present(PropertyDiscovery $d): array
    {
        return [
            'id' => $d->id,
            'discovery_reference' => $d->discovery_reference,
            // Permanent property identity (PRP-YYYY-#####). Present from the
            // first recording, so a property is traceable before the source
            // system issues a Property ID.
            'source_reference' => $d->propertyRef?->source_reference,
            'status' => $d->status,
            'queue' => $this->queueOf($d->status),
            'owner_name' => $d->owner_name,
            'owner_contact' => $d->owner_contact,
            'tin' => $d->tin,
            'property_address' => $d->property_address,
            'county' => $d->county,
            'district' => $d->district,
            'city_town' => $d->city_town,
            'community' => $d->community,
            'street' => $d->street,
            'house_number' => $d->house_number,
            'property_classification' => $d->property_classification,
            'property_type' => $d->property_type,
            'occupancy_use' => $d->occupancy_use,
            'description' => $d->description,
            'declared_value' => $d->declared_value !== null ? (float) $d->declared_value : null,
            'value_declaration_type' => $d->value_declaration_type,
            'value_declaration_label' => $this->declarationLabel($d->value_declaration_type),
            'declared_assessed_value' => $d->declared_assessed_value !== null ? (float) $d->declared_assessed_value : null,
            'documentary_value' => $d->documentary_value !== null ? (float) $d->documentary_value : null,
            'official_assessed_value' => $d->official_assessed_value !== null ? (float) $d->official_assessed_value : null,
            'property_id' => $d->property_id,
            'document_number' => $d->document_number,
            'gps_coordinate' => $d->gps_coordinate,
            'gps_accuracy' => $d->gps_accuracy !== null ? (float) $d->gps_accuracy : null,
            'gps_captured_at' => $d->gps_captured_at?->toISOString(),
            'gps_verified' => $d->gps_verified,
            'gps_certified_at' => $d->gps_certified_at?->toISOString(),
            'gps_cert_remarks' => $d->gps_cert_remarks,
            'discovery_date' => $d->discovery_date?->toDateString(),
            'discovered_by' => $d->relationLoaded('discoverer') ? ($d->discoverer?->full_name ?? "User #{$d->discovered_by}") : $d->discovered_by,
            // `discovered_by` is a display name whenever the relation is loaded and a
            // raw id otherwise, so it can never be compared against the signed-in
            // user. This is the stable recorder id, used to decide who may correct
            // a returned discovery.
            'discovered_by_user_id' => (int) $d->discovered_by,
            'decision_path' => $d->decision_path,
            'route' => $this->routeOf($d),
            'workflow' => $this->workflowStatuses($d),
            'classification_decision' => $d->classification_decision,
            'classification_source' => $d->classification_source,
            'classified_by' => $d->relationLoaded('classifiedBy') ? ($d->classifiedBy?->full_name ?? "User #{$d->classified_by}") : $d->classified_by,
            'classified_at' => $d->classified_at?->toISOString(),
            'submitted_at' => $d->submitted_at?->toISOString(),
            'manager_remarks' => $d->manager_remarks,
            'valuation_id' => $d->valuation_id,
            'current_owner_id' => $d->current_owner_id,
            'current_owner' => $d->relationLoaded('currentOwner') ? ($d->currentOwner?->full_name ?? null) : null,
            'aarm_decision' => $d->aarm_decision,
            'aarm_remarks' => $d->aarm_remarks,
            'aarm_reviewed_at' => $d->aarm_reviewed_at?->toISOString(),
            'referral' => [
                'referred_by' => $d->relationLoaded('referredByUser') ? ($d->referredByUser?->full_name ?? null) : null,
                'referred_at' => $d->referred_at?->toISOString(),
                'reason' => $d->referral_reason,
                'from_stage' => $d->referral_from_stage,
                'to_stage' => PropertyDiscovery::STATUS_REFERRED_VALUATION,
            ],
            'ac_decision' => $d->ac_decision,
            'ac_remarks' => $d->ac_remarks,
            'processed_at' => $d->processed_at?->toISOString(),
            'completed_at' => $d->completed_at?->toISOString(),
            'remarks' => $d->remarks,
            'created_at' => $d->created_at?->toISOString(),
            'legacy_photos_count' => $d->relationLoaded('photos') ? $d->photos->count() : null,
            // The legacy evidence_photos store. It is NOT the discovery's
            // property-photograph set — those are the PROPERTY_PHOTO rows in
            // `evidence` above — so it is named explicitly and only surfaced as
            // a fallback for older records. The storage path is never returned;
            // the gated route re-checks scope on the way out.
            'legacy_photos' => $d->relationLoaded('photos')
                ? $d->photos->map(fn ($ph) => [
                    'id' => $ph->id,
                    'photo_reference' => $ph->photo_reference,
                    'photo_type' => $ph->photo_type,
                    'mime' => $ph->mime,
                    'captured_at' => $ph->captured_at?->toISOString(),
                    'url' => '/api/v1/evidence/photos/'.$ph->id.'/download',
                ])->values()
                : null,
            'evidence_count' => $d->relationLoaded('evidence') ? $d->evidence->count() : null,
            // The count the Property View reports as "Property photos".
            'property_photo_count' => $d->relationLoaded('evidence')
                ? $d->evidence->where('evidence_type', PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO)->count()
                : null,
            'evidence' => $d->relationLoaded('evidence')
                ? $d->evidence->map(fn ($ev) => [
                    'id' => $ev->id,
                    'evidence_type' => $ev->evidence_type,
                    'sequence_no' => $ev->sequence_no,
                    'original_name' => $ev->original_name,
                    'mime_type' => $ev->mime_type,
                    'size_bytes' => $ev->size_bytes,
                    'capture_source' => $ev->capture_source,
                    'captured_at' => $ev->captured_at?->toISOString(),
                    'captured_by' => $ev->captured_by,
                    'captured_by_name' => $ev->relationLoaded('capturedBy')
                        ? ($ev->capturedBy?->full_name ?? null)
                        : null,
                    'gps_latitude' => $ev->gps_latitude !== null ? (float) $ev->gps_latitude : null,
                    'gps_longitude' => $ev->gps_longitude !== null ? (float) $ev->gps_longitude : null,
                    'sync_status' => $ev->sync_status,
                    // Gated route. The authenticated API group is prefixed `v1`,
                    // so the path must include it or the link 404s.
                    'url' => '/api/v1/discoveries/'.$d->id.'/evidence/'.$ev->id.'/file',
                ])->values()
                : null,
            'valuation' => $d->relationLoaded('valuation') && $d->valuation
                ? [
                    'id' => $d->valuation->id,
                    'valuation_reference' => $d->valuation->valuation_reference,
                    'status' => $d->valuation->status,
                    // The assessment's annual tax is the authoritative figure the
                    // registration prompt prefills; the officer may not overwrite it.
                    'annual_tax' => $d->valuation->annual_tax !== null ? (float) $d->valuation->annual_tax : null,
                    'valuation_officer' => $d->valuation->relationLoaded('valuationOfficer')
                        ? ($d->valuation->valuationOfficer?->full_name ?? null) : null,
                ] : null,
        ];
    }

    public function declarationLabel(?string $declaration): string
    {
        return match ($declaration) {
            PropertyDiscovery::VALUE_DECLARATION_SELF_DECLARED => 'Self Declared',
            PropertyDiscovery::VALUE_DECLARATION_DOCUMENTARY => 'Value from Documentation',
            PropertyDiscovery::VALUE_DECLARATION_VALUATION => 'Valuation',
            default => 'Not declared',
        };
    }

    /** The classification route actually taken by this record. */
    public function routeOf(PropertyDiscovery $d): ?string
    {
        if ($d->decision_path) {
            return $d->decision_path;
        }

        return $d->valuation_id ? 'valuation' : null;
    }

    public function queueOf(?string $status): ?string
    {
        foreach ($this->queues() as $key => $statuses) {
            if ($status && in_array($status, $statuses, true)) {
                return $key;
            }
        }

        return null;
    }

    public function queues(): array
    {
        return [
            'acct_manager' => [PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW],
            'valuation_manager' => [PropertyDiscovery::STATUS_AWAITING_VALUATION, PropertyDiscovery::STATUS_REFERRED_VALUATION],
            'valuation_officers' => [PropertyDiscovery::STATUS_UNDER_VALUATION],
            'ac' => [PropertyDiscovery::STATUS_AWAITING_AC_APPROVAL],
            'account_officers' => [PropertyDiscovery::STATUS_ASSIGNED_ACCT],
            'registered' => [PropertyDiscovery::STATUS_REGISTERED],
            'corrections' => [PropertyDiscovery::STATUS_RETURNED, PropertyDiscovery::STATUS_REJECTED],
            'completed' => [PropertyDiscovery::STATUS_COMPLETED],
        ];
    }

    /**
     * Route-driven workflow — only the branch actually taken, with correction /
     * referral stages inserted only when that event actually occurred.
     */
    public function workflowStatuses(PropertyDiscovery $d): array
    {
        $common = [
            PropertyDiscovery::STATUS_DRAFT,
            PropertyDiscovery::STATUS_SUBMITTED,
            PropertyDiscovery::STATUS_CLASSIFIED,
        ];

        if (! $this->routeOf($d)) {
            return $common;
        }

        if ($this->routeOf($d) === 'account') {
            return array_merge($common, [
                PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW,
                PropertyDiscovery::STATUS_ASSIGNED_ACCT,
                PropertyDiscovery::STATUS_REGISTERED,
                PropertyDiscovery::STATUS_COMPLETED,
            ]);
        }

        $valuation = array_merge($common, [
            PropertyDiscovery::STATUS_AWAITING_VALUATION,
            PropertyDiscovery::STATUS_UNDER_VALUATION,
            PropertyDiscovery::STATUS_AWAITING_AC_APPROVAL,
        ]);

        $correction = in_array($d->status, [PropertyDiscovery::STATUS_RETURNED, PropertyDiscovery::STATUS_REJECTED], true)
            || in_array($d->ac_decision, ['rejected', 'returned'], true);

        if (! $correction) {
            $correction = AuditLog::where('auditable_type', PropertyDiscovery::class)
                ->where('auditable_id', $d->id)
                ->whereIn('action', ['discovery.returned', 'discovery.rejected', 'discovery.referred_to_valuation'])
                ->exists();
        }

        if ($d->referral_from_stage) {
            $valuation[] = PropertyDiscovery::STATUS_REFERRED_VALUATION;
        }

        if ($correction) {
            $valuation[] = $d->ac_decision === 'rejected'
                ? PropertyDiscovery::STATUS_REJECTED
                : PropertyDiscovery::STATUS_RETURNED;
            $valuation[] = PropertyDiscovery::STATUS_APPROVED;
        }

        $valuation = array_merge($valuation, [
            PropertyDiscovery::STATUS_APPROVED,
            PropertyDiscovery::STATUS_ASSIGNED_ACCT,
            PropertyDiscovery::STATUS_REGISTERED,
            PropertyDiscovery::STATUS_COMPLETED,
        ]);

        if (! in_array($d->status, $valuation, true)) {
            $valuation[] = $d->status;
        }

        return $valuation;
    }
}
