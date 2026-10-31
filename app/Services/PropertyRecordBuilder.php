<?php

namespace App\Services;

use App\Models\EvidencePhoto;
use App\Models\GpsLocation;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\PropertyDiscoveryEvidence;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The one authorized Property Record payload, shared by the PDF download and the
 * browser print layout.
 *
 * A Property Record sheet is an official-looking document: it carries the
 * property's photographs, TIN, GPS fix and value figures in a form that travels
 * easily. Two consequences shape this class.
 *
 * First, it must contain the record and nothing else. No navigation, no task
 * inbox, no neighbouring taxpayers, no other properties. The document is built
 * here from one record so there is no way for unrelated material to drift in.
 *
 * Second, it is gated twice and independently. The caller must already be allowed
 * to *see* the record (the same rule the Property View applies, via
 * PropertyRecordAccess, so the two can never disagree) and must additionally hold
 * property.export, because being able to open a record is not the same authority
 * as being able to carry a copy of it out of the system. Changing an id in the URL
 * gets you a different record's own authorization decision, never this one's.
 *
 * Photographs are embedded as real images (§8): read from the same private disk
 * the gated photo route serves, downscaled to print resolution, and inlined as
 * data URIs so the renderer never has to reach the application over HTTP.
 */
class PropertyRecordBuilder
{
    /** Longest edge, in pixels, of a photograph embedded in the document. */
    private const PHOTO_MAX_EDGE = 1400;

    /** JPEG quality for an embedded photograph. */
    private const PHOTO_QUALITY = 82;

    /** Refuse to inline anything larger than this; it is a document, not an archive. */
    private const PHOTO_MAX_BYTES = 12 * 1024 * 1024;

    /**
     * Directories under the private disk that hold attached evidence.
     *
     * `discovery-evidence/` is where DiscoveryController stores every uploaded
     * photograph, keyed by the discovery reference; `evidence/` is retained for the
     * legacy layout. Both are read here, because a record sheet that cannot see the
     * photographs the system actually holds is worse than one with no photographs at
     * all - it states a falsehood about the property.
     */
    private const EVIDENCE_ROOTS = ['discovery-evidence/', 'evidence/'];

    public function __construct(
        private PropertyRecordAccess $access,
        private DiscoveryPresenter $presenter,
    ) {}

    /**
     * Build the record document payload together with the authorized model the
     * audit chain should point at.
     *
     * The model is returned rather than re-queried by the caller so that the
     * audit entry and the rendered document provably describe the same record
     * that passed the scope check, with no second load in between that could
     * observe different data.
     *
     * @return array{record: array, auditable: Model}
     */
    public function buildWithRecord(string $type, int $id, User $user): array
    {
        abort_unless($this->access->canExport($user), 403, 'Missing permission: property.export');

        $auditable = match ($type) {
            'discovery' => $this->authorizedDiscovery($id, $user),
            'valuation' => $this->authorizedValuation($id, $user),
            default => throw new NotFoundHttpException('Unknown property record type.'),
        };

        $record = $type === 'discovery'
            ? $this->discovery($auditable, $user)
            : $this->valuation($auditable, $user);

        return ['record' => $record, 'auditable' => $auditable];
    }

    /**
     * Build the record document payload.
     *
     * @param  string  $type  discovery | valuation
     *
     * @throws AccessDeniedHttpException
     * @throws NotFoundHttpException
     */
    public function build(string $type, int $id, User $user): array
    {
        return $this->buildWithRecord($type, $id, $user)['record'];
    }

    /* ------------------------------------------------------------------ */
    /*  Authorization */
    /* ------------------------------------------------------------------ */

    private function authorizedDiscovery(int $id, User $user): PropertyDiscovery
    {
        $discovery = PropertyDiscovery::with([
            'discoverer:id,full_name',
            'classifiedBy:id,full_name',
            'currentOwner:id,full_name',
            'aarmReviewedBy:id,full_name',
            'referredByUser:id,full_name',
            'acDecidedBy:id,full_name',
            'evidence.capturedBy:id,full_name',
            'valuation:id,valuation_reference,status,owner_name,valuation_officer_id',
            'valuation.valuationOfficer:id,full_name',
        ])->find($id);

        abort_unless($discovery, 404, 'Property record not found.');
        abort_unless(
            $this->access->canViewDiscovery($discovery, $user),
            403,
            'This property record is outside your scope.'
        );

        return $discovery;
    }

    private function authorizedValuation(int $id, User $user): Valuation
    {
        $valuation = Valuation::with([
            'descriptions',
            'valuationOfficer:id,full_name',
            'managerReviewedBy:id,full_name',
            'acReviewedBy:id,full_name',
            'accountReviewedBy:id,full_name',
        ])->find($id);

        abort_unless($valuation, 404, 'Property record not found.');
        abort_unless(
            $this->access->canViewValuation($valuation, $user),
            403,
            'This property record is outside your scope.'
        );

        return $valuation;
    }

    /* ------------------------------------------------------------------ */
    /*  Property discovery record */
    /* ------------------------------------------------------------------ */

    private function discovery(PropertyDiscovery $d, User $user): array
    {
        $p = $this->presenter->present($d);
        $isReassessment = false;

        $value = $this->rows(array_filter([
            'Value declaration type' => $p['value_declaration_label'],
            'Declared value' => $p['declared_value'],
            'Declared / assessed value' => $p['declared_assessed_value'],
            'Documentary value' => $p['documentary_value'],
            'Official assessed value' => $p['official_assessed_value'],
        ], fn ($v) => $v !== null && $v !== ''));

        $extra = $this->rows(array_filter([
            'Classification decision' => $p['classification_decision'],
            'Classification source' => $p['classification_source'],
            'Decision path' => $this->humanise($p['route']),
            'Classified by' => $p['classified_by'],
            'Classified at' => $p['classified_at'],
            'Manager remarks' => $p['manager_remarks'],
            'Account & Records review decision' => $p['aarm_decision'],
            'Account & Records remarks' => $p['aarm_remarks'],
            'AC decision' => $p['ac_decision'],
            'AC remarks' => $p['ac_remarks'],
            'Remarks' => $p['remarks'],
        ], fn ($v) => $v !== null && $v !== ''));

        $linked = null;
        if ($d->relationLoaded('valuation') && $d->valuation) {
            $linked = [
                'reference' => $d->valuation->valuation_reference,
                'status' => $d->valuation->status,
                'officer' => $d->valuation->relationLoaded('valuationOfficer')
                    ? ($d->valuation->valuationOfficer?->full_name ?? null)
                    : null,
            ];
        }

        return $this->document(
            recordType: 'discovery',
            title: 'PROPERTY DISCOVERY RECORD',
            reference: $p['discovery_reference'],
            status: $p['status'],
            tin: $p['tin'],
            classification: $p['property_classification'],
            propertyType: $p['property_type'],
            propertyId: $p['property_id'],
            documentNumber: $p['document_number'],
            owner: $p['owner_name'],
            ownerContact: $p['owner_contact'],
            address: $this->address($p),
            value: $value,
            facts: $this->rows(array_filter([
                'Property type' => $p['property_type'],
                'Occupancy / use' => $p['occupancy_use'],
                'Property description' => $p['description'],
                'Discovery date' => $p['discovery_date'],
                'Discovered by' => $p['discovered_by'],
                'Submitted at' => $p['submitted_at'],
                'Completed at' => $p['completed_at'],
            ], fn ($v) => $v !== null && $v !== '')),
            photos: $this->discoveryPhotos($d),
            evidence: $this->discoveryEvidence($d),
            gps: $this->discoveryGps($d),
            workflow: $this->discoveryWorkflow($d, $p),
            recordSections: array_filter([
                'Linked valuation' => $linked ? $this->rows([
                    'Valuation reference' => $linked['reference'],
                    'Status' => $linked['status'],
                    'Valuation officer' => $linked['officer'],
                ]) : null,
                'Classification and review' => $extra,
            ]),
            isReassessment: $isReassessment,
            user: $user,
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Valuation assessment / reassessment record */
    /* ------------------------------------------------------------------ */

    private function valuation(Valuation $v, User $user): array
    {
        $isReassessment = $v->valuation_type === 'reassessment';

        $value = $this->rows(array_filter([
            'Declared value' => $this->money($v->declared_value),
            'Reassessed value' => $this->money($v->reassessed_value),
            'Assessed value' => $this->money($v->assessed_value),
            'Applicable tax rate' => $v->applicable_tax_rate !== null
                ? rtrim(rtrim(number_format((float) $v->applicable_tax_rate, 4, '.', ''), '0'), '.').'%'
                : null,
            'Annual tax' => $this->money($v->annual_tax),
            'Other amounts' => $this->money($v->other_amounts),
            'Total property value' => $this->money($v->total_property_value),
            'Total tax payable' => $this->money($v->total_tax_payable),
        ], fn ($v) => $v !== null && $v !== ''));

        // A reassessment is a comparison; without a previous assessment on the
        // register there is nothing to state a change against, so the change line is
        // only emitted when there is genuinely something to compare.
        if ($isReassessment) {
            // Kept numeric. Formatting first and casting back would silently read
            // "US$ 200,000.00" as zero and report the whole current value as the change.
            $previousRaw = $this->previousAssessment($v);

            $value = array_merge($this->rows(array_filter([
                'Previous assessment' => $this->money($previousRaw),
                'Current assessment' => $this->money($v->assessed_value),
            ])), $value);

            if ($previousRaw !== null && $v->assessed_value !== null) {
                $change = (float) $v->assessed_value - (float) $previousRaw;
                $value[] = [
                    'label' => 'Change in value',
                    'value' => ($change >= 0 ? '+' : '−').$this->money(abs($change)),
                ];
            }
        }

        $officer = $v->relationLoaded('valuationOfficer') ? $v->valuationOfficer : null;

        $facts = $this->rows(array_filter([
            'Valuation type' => $this->humanise($v->valuation_type),
            'Classification' => $v->property_classification,
            'Land dimensions' => $v->land_dimensions,
            'Building specifications' => $v->building_specs,
            'Year of construction' => $v->construction_year,
            'Condition' => $v->condition,
            'Assessment date' => $this->date($v->assessment_date),
            'Submitted at' => $v->submitted_at?->toDateTimeString(),
            'Valuation officer' => $officer?->full_name,
            'Prepared by designation' => $v->prepared_by_designation,
        ], fn ($val) => $val !== null && $val !== ''));

        $review = $this->rows(array_filter([
            'Manager decision' => $v->manager_decision,
            'Manager remarks' => $v->manager_remarks,
            'Manager reviewed at' => $v->manager_reviewed_at?->toDateTimeString(),
            'AC decision' => $v->ac_decision,
            'AC remarks' => $v->ac_remarks,
            'AC reviewed at' => $v->ac_reviewed_at?->toDateTimeString(),
            'Account & Records reviewed at' => $v->account_reviewed_at?->toDateTimeString(),
            'Account & Records remarks' => $v->account_remarks,
            'Registration status' => $v->registration_status,
            'Registration completed at' => $v->registration_completed_at?->toDateTimeString(),
            'Remarks' => $v->remarks,
        ], fn ($val) => $val !== null && $val !== ''));

        $sourceDiscovery = null;
        if ($v->discovery_id) {
            $sourceDiscovery = PropertyDiscovery::where('id', $v->discovery_id)->value('discovery_reference');
        }

        return $this->document(
            recordType: $isReassessment ? 'reassessment' : 'valuation_assessment',
            title: $isReassessment ? 'PROPERTY REASSESSMENT RECORD' : 'PROPERTY VALUATION ASSESSMENT RECORD',
            reference: $v->valuation_reference,
            status: $v->status,
            tin: $v->tin,
            classification: $v->property_classification,
            propertyType: null,
            propertyId: $v->property_id,
            documentNumber: $v->document_number,
            owner: $v->owner_name,
            ownerContact: $v->owner_contact,
            address: $this->address($v),
            value: $value,
            facts: $facts,
            photos: $this->valuationPhotos($v),
            evidence: [],
            gps: $this->valuationGps($v),
            workflow: $this->valuationWorkflow($v),
            recordSections: array_filter([
                'Source discovery' => $sourceDiscovery
                    ? [['label' => 'Source discovery', 'value' => $sourceDiscovery]]
                    : null,
                'Description of property' => $this->descriptionRows($v),
                'Manager and AC review' => $review,
            ]),
            isReassessment: $isReassessment,
            user: $user,
        );
    }

    /**
     * The previous assessment figure for a reassessment, taken from the bill register
     * and returned unformatted so callers can both display and subtract it. Null
     * when there is no register entry, in which case no comparison is claimed.
     */
    private function previousAssessment(Valuation $v): ?float
    {
        if (! $v->bill_id) {
            return null;
        }

        $previous = PropertyBill::where('id', $v->bill_id)->value('assessed_value');

        return $previous === null ? null : (float) $previous;
    }

    /* ------------------------------------------------------------------ */
    /*  Document assembly */
    /* ------------------------------------------------------------------ */

    private function document(
        string $recordType,
        string $title,
        ?string $reference,
        ?string $status,
        ?string $tin,
        ?string $classification,
        ?string $propertyType,
        ?string $propertyId,
        ?string $documentNumber,
        ?string $owner,
        ?string $ownerContact,
        array $address,
        array $value,
        array $facts,
        array $photos,
        array $evidence,
        array $gps,
        array $workflow,
        array $recordSections,
        bool $isReassessment,
        User $user,
    ): array {
        return [
            'record_type' => $recordType,
            'title' => $title,
            'identity' => [
                'reference' => $reference,
                'tin' => $tin,
                'classification' => $classification,
                'property_type' => $propertyType,
                'status' => $status,
                'property_id' => $propertyId,
                'document_number' => $documentNumber,
            ],
            'owner' => array_filter([
                'name' => $owner,
                'contact' => $ownerContact,
            ]),
            'address' => $address,
            'value' => $value,
            'facts' => $facts,
            'photos' => $photos,
            'evidence' => $evidence,
            'gps' => $gps,
            'workflow' => $workflow,
            'record_sections' => $recordSections,
            'is_reassessment' => $isReassessment,
            'generated' => [
                'by' => $user->full_name ?: ('User #'.$user->id),
                'at' => now()->format('j F Y, g:i A'),
                'timezone' => 'Africa/Monrovia',
                'record_version' => $reference,
            ],
            'filename' => $this->filename($recordType, $reference, $tin, $propertyId, $isReassessment),
        ];
    }

    /**
     * Filename per spec: a recognisable prefix, the record's own identifier, and
     * nothing that a filesystem or a browser might object to.
     */
    private function filename(string $recordType, ?string $reference, ?string $tin, ?string $propertyId, bool $isReassessment): string
    {
        $prefix = match ($recordType) {
            'discovery' => 'RETD_Property_Record',
            'valuation_assessment' => 'RETD_Valuation_Assessment',
            default => 'RETD_Reassessment',
        };

        $parts = array_filter([
            $prefix,
            $this->safe($reference),
            $this->safe($tin),
            $isReassessment ? $this->safe(now()->format('Y-m-d')) : $this->safe($propertyId),
        ]);

        $name = implode('_', $parts);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'Property_Record';

        return Str::limit($name, 120, '').'.pdf';
    }

    private function safe(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return preg_replace('/[^A-Za-z0-9-]+/', '', $value) ?: null;
    }

    /* ------------------------------------------------------------------ */
    /*  Photographs — real images, not icons */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array{reference:?string, caption:?string, data_uri:?string, width:?int, height:?int, note:?string}>
     */
    private function discoveryPhotos(PropertyDiscovery $d): array
    {
        if (! $d->relationLoaded('evidence')) {
            return [];
        }

        return $d->evidence
            ->where('evidence_type', PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO)
            ->sortBy('sequence_no')
            ->values()
            ->map(fn ($ev, $i) => $this->photoPayload(
                path: $ev->file_path,
                mime: $ev->mime_type,
                reference: null,
                caption: $ev->original_name ?: ('Property photograph '.($i + 1)),
                capturedAt: $ev->captured_at,
            ))
            ->all();
    }

    /**
     * Photographs belonging to a valuation.
     *
     * A valuation is raised *from* a discovery, and the discovery is where the
     * property was actually photographed - the valuation officer carries no camera
     * and the application never writes a property photograph against the valuation.
     * Reading only the valuation's own photo rows therefore produced a record sheet
     * that announced "no property photograph is attached" on a property with three
     * photographs on file, which is about the worst sentence an official copy can
     * print: it is indistinguishable from having no evidence at all.
     *
     * So the valuation's own rows are read first, because if a flow ever does attach
     * one it is the most specific statement about this assessment, and the source
     * discovery's photographs follow. Each photograph names the record it came from,
     * so the sheet is not read as though the assessment itself took the pictures.
     *
     * @return array<int, array<string, mixed>>
     */
    private function valuationPhotos(Valuation $v): array
    {
        $photos = [];
        $seen = [];

        $add = function (string $path, ?string $mime, ?string $reference, string $caption, $capturedAt) use (&$photos, &$seen): void {
            // The same file can be reached from both sources when a valuation is
            // raised from a discovery that already links to it. Printed twice it
            // would read as two photographs of the same thing.
            if (isset($seen[$path])) {
                return;
            }
            $seen[$path] = true;

            $photos[] = $this->photoPayload(
                path: $path,
                mime: $mime,
                reference: $reference,
                caption: $caption,
                capturedAt: $capturedAt,
            );
        };

        $own = EvidencePhoto::where('valuation_id', $v->id)
            ->whereIn('photo_type', ['PROPERTY_FULL_VIEW', 'PREMISES'])
            ->orderBy('id')
            ->get();

        foreach ($own as $i => $ph) {
            $add(
                $ph->file_path,
                $ph->mime,
                $ph->photo_reference,
                $ph->original_name ?: ('Property photograph '.($i + 1)),
                $ph->captured_at ?? $ph->uploaded_at,
            );
        }

        $discovery = $this->sourceDiscoveryFor($v);

        if ($discovery) {
            foreach ($v->discoveryPropertyPhotographs() as $ev) {
                $add(
                    $ev->file_path,
                    $ev->mime_type,
                    null,
                    ($ev->original_name ?: 'Property photograph')
                        .' · from discovery '.$discovery->discovery_reference,
                    $ev->captured_at,
                );
            }
        }

        return $photos;
    }

    /**
     * The discovery a valuation's photographs come from.
     *
     * @see Valuation::sourceDiscovery() — the rule lives on the model so that this
     *      sheet and the submission check answer the same question identically.
     */
    private function sourceDiscoveryFor(Valuation $v): ?PropertyDiscovery
    {
        return $v->sourceDiscovery();
    }

    /**
    /**
     * Whether a stored path may be read and embedded in an official document.
     *
     * The prefix list is the first gate, and it exists because the evidence store is
     * written in more than one place: discovery uploads land under
     * `discovery-evidence/<reference>/`, while other flows use `evidence/`. An
     * earlier version of this check allowed only the second, and it silently rejected
     * every photograph the application had actually stored - the record sheet then
     * announced "no property photograph is attached" on properties that had three on
     * file. A guard that is wrong in the safe direction still costs the truth.
     *
     * The prefix list is not the security boundary, though, because a stored path can
     * be influenced in principle. The resolved file is therefore also required to sit
     * inside the disk root, and that is what actually makes traversal impossible
     * however the string is spelled.
     */
    private function isInsideEvidenceStore(string $path): bool
    {
        // Traversal anywhere in the path, a drive letter, a UNC prefix or a rooted
        // path are all refused before the filesystem is consulted.
        if ($path === ''
            || str_contains($path, '..')
            || str_contains($path, "\0")
            || str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('#^[A-Za-z]:#', $path)) {
            return false;
        }

        $normalised = str_replace('\\', '/', $path);
        $withinRoot = false;
        foreach (self::EVIDENCE_ROOTS as $evidenceRoot) {
            if (str_starts_with($normalised, $evidenceRoot)) {
                $withinRoot = true;
                break;
            }
        }
        if (! $withinRoot) {
            return false;
        }

        $root = realpath(Storage::disk('local')->path(''));
        $resolved = realpath(Storage::disk('local')->path($normalised));

        // An unresolvable path is not a failure here. A file that is simply absent is
        // reported as unavailable by the caller, which is a truer statement than
        // claiming it lies outside the store.
        if ($root === false || $resolved === false) {
            return true;
        }

        return $resolved === $root || str_starts_with($resolved, $root.DIRECTORY_SEPARATOR);
    }

    /**
     * Read one photograph off the private disk and inline it as a data URI.
     *
     * A modern phone photograph is far larger than a printed page needs, and
     * embedding three of them untouched would produce a PDF of many megabytes that
     * is slow to render and awkward to print, so the image is scaled to a print
     * resolution first. Aspect ratio is preserved and the result is re-encoded as
     * JPEG, which also means a PNG screenshot of a document does not silently
     * bloat the file.
     *
     * A photograph that cannot be read is reported as a note rather than silently
     * dropped: an official record must not quietly lose evidence, and it must not
     * show a broken-image placeholder either.
     */
    private function photoPayload(string $path, ?string $mime, ?string $reference, ?string $caption, $capturedAt = null): array
    {
        $base = array_filter([
            'reference' => $reference,
            'caption' => $caption,
            'captured_at' => $capturedAt instanceof \DateTimeInterface
                ? $capturedAt->format('j M Y, g:i A')
                : null,
        ]);

        if (! $this->isInsideEvidenceStore($path)) {
            return $base + ['data_uri' => null, 'width' => null, 'height' => null, 'note' => 'File is outside the evidence store.'];
        }

        if (! Storage::disk('local')->exists($path)) {
            return $base + ['data_uri' => null, 'width' => null, 'height' => null, 'note' => 'File is no longer available.'];
        }

        $size = Storage::disk('local')->size($path);
        if ($size === false || $size > self::PHOTO_MAX_BYTES) {
            return $base + ['data_uri' => null, 'width' => null, 'height' => null, 'note' => 'File is too large to embed.'];
        }

        $raw = Storage::disk('local')->get($path);
        if ($raw === null || $raw === '') {
            return $base + ['data_uri' => null, 'width' => null, 'height' => null, 'note' => 'File could not be read.'];
        }

        $image = @imagecreatefromstring($raw);
        if ($image === false) {
            return $base + ['data_uri' => null, 'width' => null, 'height' => null, 'note' => 'Not a readable image.'];
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest > self::PHOTO_MAX_EDGE) {
            $scale = self::PHOTO_MAX_EDGE / $longest;
            $targetW = max(1, (int) round($width * $scale));
            $targetH = max(1, (int) round($height * $scale));
            $resized = imagecreatetruecolor($targetW, $targetH);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetW, $targetH, $width, $height);
            imagedestroy($image);
            $image = $resized;
            $width = $targetW;
            $height = $targetH;
        }

        ob_start();
        imagejpeg($image, null, self::PHOTO_QUALITY);
        $encoded = ob_get_clean();
        imagedestroy($image);

        if ($encoded === false || $encoded === '') {
            return $base + ['data_uri' => null, 'width' => null, 'height' => null, 'note' => 'Image could not be encoded.'];
        }

        return $base + [
            'data_uri' => 'data:image/jpeg;base64,'.base64_encode($encoded),
            'width' => $width,
            'height' => $height,
            'note' => null,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Evidence register */
    /* ------------------------------------------------------------------ */

    /**
     * Documents are listed, not printed inline. A deed or a rate-fix notice is
     * several pages; inlining every one would turn a property record into a file
     * nobody can email, and the sheet is meant to identify the evidence, not to
     * reproduce it.
     */
    private function discoveryEvidence(PropertyDiscovery $d): array
    {
        if (! $d->relationLoaded('evidence')) {
            return [];
        }

        return $d->evidence
            ->where('evidence_type', PropertyDiscoveryEvidence::TYPE_OWNERSHIP_DOCUMENT)
            ->sortBy('sequence_no')
            ->values()
            ->map(fn ($ev) => [
                'type' => $this->humanise($ev->evidence_type),
                'name' => $ev->original_name ?: 'Ownership document',
                'captured_at' => $ev->captured_at?->format('j M Y'),
                'uploaded_by' => $ev->relationLoaded('capturedBy') ? ($ev->capturedBy?->full_name ?? null) : null,
                'size' => $ev->size_bytes ? $this->bytes((int) $ev->size_bytes) : null,
                'source' => $this->humanise($ev->capture_source),
            ])
            ->all();
    }

    private function bytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024).' KB';
        }

        return $bytes.' B';
    }

    /* ------------------------------------------------------------------ */
    /*  GPS */
    /* ------------------------------------------------------------------ */

    private function discoveryGps(PropertyDiscovery $d): array
    {
        $official = GpsLocation::where('discovery_id', $d->id)->latest('id')->first();

        $latitude = $d->gps_coordinate ? (float) explode(',', (string) $d->gps_coordinate)[0] : null;
        $longitude = $d->gps_coordinate ? (float) explode(',', (string) $d->gps_coordinate)[1] : null;

        if ($official) {
            $latitude = (float) $official->latitude;
            $longitude = (float) $official->longitude;
        }

        return $this->gpsPayload(
            latitude: $latitude,
            longitude: $longitude,
            accuracy: $official?->accuracy !== null ? (float) $official->accuracy : ($d->gps_accuracy !== null ? (float) $d->gps_accuracy : null),
            source: $official?->source ?? 'Discovery GPS',
            capturedAt: $official?->captured_at ?? $d->gps_captured_at,
            capturedBy: $official?->capturedBy?->full_name ?? $d->discoverer?->full_name,
            certification: $this->certification($official, $d->gps_certified_at),
        );
    }

    private function valuationGps(Valuation $v): array
    {
        $official = GpsLocation::where('valuation_id', $v->id)->latest('id')->first();

        $latitude = $v->gps_coordinate ? (float) explode(',', (string) $v->gps_coordinate)[0] : null;
        $longitude = $v->gps_coordinate ? (float) explode(',', (string) $v->gps_coordinate)[1] : null;

        if ($official) {
            $latitude = (float) $official->latitude;
            $longitude = (float) $official->longitude;
        }

        return $this->gpsPayload(
            latitude: $latitude,
            longitude: $longitude,
            accuracy: $official?->accuracy !== null ? (float) $official->accuracy : ($v->gps_accuracy !== null ? (float) $v->gps_accuracy : null),
            source: $official?->source ?? 'Valuation GPS',
            capturedAt: $official?->captured_at,
            capturedBy: $official?->capturedBy?->full_name,
            certification: $this->certification($official, $v->gps_certified_at),
        );
    }

    private function certification(?GpsLocation $official, $certifiedAt): string
    {
        if ($official?->verification_status) {
            return $this->humanise($official->verification_status);
        }

        return $certifiedAt ? 'Certified' : 'Official GPS not yet certified';
    }

    private function gpsPayload(
        ?float $latitude,
        ?float $longitude,
        ?float $accuracy,
        ?string $source,
        $capturedAt,
        ?string $capturedBy,
        string $certification,
    ): array {
        $hasFix = $latitude !== null && $longitude !== null
            && $latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180;

        return [
            'has_fix' => $hasFix,
            'latitude' => $hasFix ? $latitude : null,
            'longitude' => $hasFix ? $longitude : null,
            'accuracy' => $accuracy,
            'source' => $source,
            'captured_at' => $capturedAt instanceof \DateTimeInterface
                ? $capturedAt->format('j M Y, g:i A')
                : null,
            'captured_by' => $capturedBy,
            'certification' => $certification,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Workflow */
    /* ------------------------------------------------------------------ */

    private function discoveryWorkflow(PropertyDiscovery $d, array $presented): array
    {
        return [
            'stages' => $this->presenter->workflowStatuses($d),
            'current' => $d->status,
            'history' => $this->rows(array_filter([
                'Submitted' => $presented['submitted_at'],
                'Classified' => $presented['classified_at'],
                'Account & Records reviewed' => $presented['aarm_reviewed_at'],
                'AC decided' => $presented['ac_decision'] ? $this->acDecidedAt($d) : null,
                'Processed' => $presented['processed_at'],
                'Completed' => $presented['completed_at'],
            ], fn ($v) => $v !== null && $v !== '')),
        ];
    }

    private function acDecidedAt(PropertyDiscovery $d): ?string
    {
        return $d->ac_decided_at?->format('j M Y');
    }

    private function valuationWorkflow(Valuation $v): array
    {
        return [
            'stages' => [
                Valuation::STATUS_ASSIGNED,
                Valuation::STATUS_ASSESSMENT_IN_PROGRESS,
                Valuation::STATUS_SUBMITTED_TO_MANAGER,
                Valuation::STATUS_UNDER_MANAGER_REVIEW,
                Valuation::STATUS_SUBMITTED_TO_AC,
                Valuation::STATUS_AWAITING_AC_APPROVAL,
                Valuation::STATUS_AC_APPROVED,
                Valuation::STATUS_SENT_TO_ACCT,
                Valuation::STATUS_REGISTERED,
                Valuation::STATUS_COMPLETED,
            ],
            'current' => $v->status,
            'history' => $this->rows(array_filter([
                'Assigned' => $v->created_at?->format('j M Y'),
                'Submitted to manager' => $v->submitted_at?->format('j M Y'),
                'Manager reviewed' => $v->manager_reviewed_at?->format('j M Y'),
                'AC reviewed' => $v->ac_reviewed_at?->format('j M Y'),
                'Account & Records reviewed' => $v->account_reviewed_at?->format('j M Y'),
                'Registration completed' => $v->registration_completed_at?->format('j M Y'),
            ], fn ($val) => $val !== null && $val !== '')),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Small helpers */
    /* ------------------------------------------------------------------ */

    /**
     * The property description sub-table, as rows and columns.
     *
     * This was one string per valuation: every item's attributes joined with a middle
     * dot, and all of them joined again by line breaks into a single cell. It was
     * compact, and it was unreadable. Nothing lined up, so the eye could not travel
     * down a column to compare the areas, the quantities or the rates, and a reader
     * checking a total had to do the arithmetic from prose. The run-on form also
     * scaled badly: a third item pushed the value cells out of alignment and the
     * whole thing degraded to a paragraph.
     *
     * It is a repeatability sub-table on the valuation form, so it belongs on the
     * record sheet as a repeatability sub-table. Each attribute is now its own
     * column, and the computed value - the figure RETD derives as
     * amount * quantity * (1 - depreciation) - is printed alongside the inputs it was
     * derived from, with a total, so the record can be checked rather than trusted.
     *
     * The currency is carried in the column header instead of every cell: repeating
     * "US$" in each figure spent roughly a fifth of the page width to say nothing the
     * header does not already say.
     *
     * @return array<int, array<string, mixed>>
     */
    private function descriptionRows(Valuation $v): array
    {
        if (! $v->relationLoaded('descriptions') || $v->descriptions->isEmpty()) {
            return [];
        }

        $rows = [];
        $total = 0.0;
        $anyValued = false;

        foreach ($v->descriptions->sortBy('seq') as $r) {
            $value = $r->value === null ? null : (float) $r->value;

            if ($value !== null) {
                $total += $value;
                $anyValued = true;
            }

            $rows[] = [
                'seq' => (int) $r->seq,
                'description' => $r->description,
                'level' => $r->level,
                'area' => $r->area_sqft === null ? null : $this->num($r->area_sqft).' sq ft',
                'tar' => $r->tar === null ? null : $this->num($r->tar).'%',
                'quantity' => $r->quantity === null ? null : (string) (int) $r->quantity,
                'amount' => $this->tableAmount($r->amount),
                'depreciation' => $r->depreciation_pct === null ? null : $this->num($r->depreciation_pct).'%',
                'value' => $this->tableAmount($value),
            ];
        }

        return [[
            'table' => [
                'columns' => [
                    '#', 'Description', 'Level', 'Area', 'TAR', 'Qty', 'Amount (US$)', 'Dep.', 'Value (US$)',
                ],
                'rows' => $rows,
                'total' => $anyValued
                    ? ['label' => 'Total', 'value' => $this->tableAmount($total)]
                    : null,
            ],
        ]];
    }

    /**
     * A money figure for a table cell: grouped, two decimals, and no currency symbol,
     * because the column header carries the unit and the symbol in every cell would
     * cost more width than it is worth.
     */
    private function tableAmount($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2);
    }

    private function address(array|object $record): array
    {
        $get = fn (string $key) => is_array($record) ? ($record[$key] ?? null) : ($record->{$key} ?? null);

        $parts = array_filter([
            $get('house_number'),
            $get('street'),
            $get('community'),
            $get('city_town'),
            $get('district'),
            $get('county'),
        ]);

        $full = $get('property_address');

        return array_filter([
            'full' => $full,
            'parts' => $parts ? implode(', ', array_unique($parts)) : null,
        ]);
    }

    /** @return array<int, array{label:string, value:string}> */
    private function rows(array $values): array
    {
        $rows = [];

        foreach ($values as $label => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $rows[] = ['label' => $label, 'value' => (string) $value];
        }

        return $rows;
    }

    private function money($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return 'US$ '.number_format((float) $value, 2);
    }

    private function num($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    private function date($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('j M Y')
            : substr((string) $value, 0, 10);
    }

    private function humanise(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ucwords(strtolower(str_replace('_', ' ', $value)));
    }
}
