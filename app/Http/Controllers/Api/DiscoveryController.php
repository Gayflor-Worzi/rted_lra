<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\PropertyDiscoveryEvidence;
use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;
use App\Services\AssignmentEligibilityService;
use App\Services\AuditService;
use App\Services\DiscoveryPresenter;
use App\Services\PropertyIdentityService;
use App\Services\PropertyProfileService;
use App\Services\PropertyRecordAccess;
use App\Services\SyncService;
use App\Services\TaskService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Property Discovery — spec v4 controlled-routing workflow.
 *
 *   DISCOVERED → SUBMITTED → CLASSIFIED → Account & Record Manager queue (Path A)
 *                                      → Valuation Manager queue      (Path B/C)
 *
 * The system auto-classifies the property at submission (config/discovery.php)
 * and routes it to the correct management desk. Management desks then drive the
 * task onward; the underlying record is never duplicated (Task Ownership §15/§16).
 *
 * Account & Record is split by rank: the Manager reviews and assigns, the
 * Officer only processes. Once the record reaches "Assigned to Account &
 * Record" the officer holds an assigned processing task — register the property
 * in the source system, then update the property bill record in RETD.
 *
 * Property ID / Document # are never generated here — source-system identifiers are
 * recorded only after Account & Record processing.
 */
class DiscoveryController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly AuditService $audit,
        private readonly SyncService $sync,
        private readonly PropertyIdentityService $identity,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['discovery.view', 'discovery.create']), 403, 'Missing permission: discovery.view');

        $query = PropertyDiscovery::query()->with([
            'discoverer:id,full_name',
            'classifiedBy:id,full_name',
            'currentOwner:id,full_name',
            'aarmReviewedBy:id,full_name',
            'valuation:id,valuation_reference,status,owner_name,valuation_officer_id,annual_tax',
            'valuation.valuationOfficer:id,full_name',
            'propertyRef:id,source_reference',
        ]);

        if (($status = $request->query('status')) && $this->validStatus($status)) {
            $query->where('status', $status);
        }
        if ($officer = $request->query('officer_id')) {
            $query->where('discovered_by', $officer);
        }
        if ($owner = $request->query('current_owner_id')) {
            $query->where('current_owner_id', $owner);
        }
        if ($classification = $request->query('classification')) {
            $query->where('property_classification', $classification);
        }
        if ($declaration = $request->query('value_declaration_type')) {
            $query->where('value_declaration_type', $declaration);
        }
        if ($valueMin = $request->query('value_min')) {
            $query->where('declared_assessed_value', '>=', $valueMin);
        }
        if ($valueMax = $request->query('value_max')) {
            $query->where('declared_assessed_value', '<=', $valueMax);
        }
        if ($decision = $request->query('path')) {
            $query->where('decision_path', $decision);
        }
        if (($queue = $request->query('queue')) && array_key_exists($queue, $this->queues())) {
            $query->whereIn('status', $this->queues()[$queue]);
        }
        if ($q = $request->query('q')) {
            $query->where(function (Builder $b) use ($q) {
                $b->where('discovery_reference', 'like', like_term($q))
                    ->orWhere('owner_name', 'like', like_term($q))
                    ->orWhere('property_address', 'like', like_term($q))
                    ->orWhere('property_id', 'like', like_term($q))
                    // The permanent property identity is searchable from every
                    // section, so the same reference finds the same property.
                    ->orWhereHas('propertyRef', fn (Builder $p) => $p->where('source_reference', 'like', like_term($q)));
            });
        }
        if ($from = $request->query('date_from')) {
            $query->whereDate('discovery_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('discovery_date', '<=', $to);
        }

        $this->restrictDataScope($query, $user, $decision = $request->query('path'));

        $rows = $query->orderByDesc('id')->paginate($request->query('per_page', 20))->withQueryString();
        $rows->getCollection()->transform(fn ($d) => $this->present($d));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canPermission('discovery.create'), 403, 'Missing permission: discovery.create');

        $syncId = $this->sync->localId($request);
        if ($syncId && ($replay = $this->sync->findForReplay($user->id, $syncId))) {
            return $this->sync->replayResponse($replay);
        }

        $data = $request->validate($this->rules());
        abort_unless($this->hasLocationOrAddress($data), 422, 'Provide a property address or GPS coordinate for the discovery.');

        $discovery = DB::transaction(function () use ($data, $user, $syncId) {
            $discovery = PropertyDiscovery::create([
                'status' => PropertyDiscovery::STATUS_DRAFT,
                'owner_name' => $data['owner_name'] ?? null,
                'owner_contact' => $data['owner_contact'] ?? null,
                'tin' => $data['tin'] ?? null,
                'property_address' => $data['property_address'] ?? null,
                'county' => $data['county'] ?? null,
                'district' => $data['district'] ?? null,
                'city_town' => $data['city_town'] ?? null,
                'community' => $data['community'] ?? null,
                'street' => $data['street'] ?? null,
                'house_number' => $data['house_number'] ?? null,
                'property_classification' => $data['property_classification'] ?? null,
                'property_type' => $data['property_type'] ?? null,
                'occupancy_use' => $data['occupancy_use'] ?? null,
                'description' => $data['description'] ?? null,
                'declared_value' => $data['declared_assessed_value'] ?? $data['declared_value'] ?? null,
                'value_declaration_type' => $data['value_declaration_type'] ?? null,
                'declared_assessed_value' => $data['declared_assessed_value'] ?? null,
                'documentary_value' => $data['documentary_value'] ?? null,
                'gps_coordinate' => $data['gps_coordinate'] ?? $this->coordinateFrom($data),
                'gps_accuracy' => $data['gps_accuracy'] ?? null,
                'gps_captured_at' => $data['gps_captured_at'] ?? now(),
                'discovery_date' => $data['discovery_date'] ?? now()->toDateString(),
                'discovered_by' => $user->id,
                'remarks' => $data['remarks'] ?? null,
            ]);

            $this->audit->record($discovery, 'discovery.created', $user->id, [], [
                'discovery_reference' => $discovery->discovery_reference,
            ]);

            if ($syncId) {
                $this->sync->record($user->id, $syncId, 'discovery', $discovery->id, $this->present($discovery->fresh(['discoverer:id,full_name'])));
            }

            return $discovery;
        });

        $data = $this->present($discovery->fresh(['discoverer:id,full_name']));

        return response()->json([
            'data' => $data,
            'message' => 'Property discovery recorded (draft).',
        ], 201);
    }

    public function show(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['discovery.view', 'discovery.create', 'discovery.review']), 403, 'Missing permission.');
        abort_unless($this->canViewDiscovery($discovery, $user), 403, 'Discovery is outside your scope.');
        $discovery->load([
            'discoverer:id,full_name',
            'classifiedBy:id,full_name',
            'currentOwner:id,full_name',
            'aarmReviewedBy:id,full_name',
            'referredByUser:id,full_name',
            'acDecidedBy:id,full_name',
            'photos.officer:id,full_name',
            'evidence:id,discovery_id,property_id,evidence_type,sequence_no,file_path,original_name,mime_type,size_bytes,capture_source,captured_at,captured_by,gps_latitude,gps_longitude,sync_status',
            'evidence.capturedBy:id,full_name',
            'valuation:id,valuation_reference,status,owner_name,valuation_officer_id,annual_tax',
            'valuation.valuationOfficer:id,full_name',
            'propertyRef:id,source_reference',
        ]);

        return response()->json(['data' => $this->present($discovery)]);
    }

    /* ---------- evidence ---------- */

    /** Attach evidence (ownership/deed document or 1..3 property photos) to a draft/returned discovery. */
    public function storeEvidence(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->canPermission('discovery.create'), 403, 'Missing permission: discovery.create');
        abort_unless($this->canMutateDiscovery($discovery, $user), 403, 'You can only add evidence to discoveries you recorded.');
        abort_unless(in_array($discovery->status, [PropertyDiscovery::STATUS_DRAFT, PropertyDiscovery::STATUS_RETURNED], true), 422, 'Evidence can only be added while the discovery is a draft or returned for correction.');

        $data = $request->validate([
            'evidence_type' => ['required', 'string', Rule::in(PropertyDiscoveryEvidence::TYPES)],
            'sequence_no' => 'nullable|integer|min:1',
            'file' => 'required|file|max:10240',
            'original_name' => 'nullable|string|max:255',
            'capture_source' => ['nullable', 'string', Rule::in([PropertyDiscoveryEvidence::SOURCE_CAMERA, PropertyDiscoveryEvidence::SOURCE_UPLOAD])],
            'captured_at' => 'nullable|date',
            'gps_latitude' => 'nullable|numeric|between:-90,90',
            'gps_longitude' => 'nullable|numeric|between:-180,180',
            'sync_status' => ['nullable', 'string', Rule::in([PropertyDiscoveryEvidence::SYNC_PENDING, PropertyDiscoveryEvidence::SYNC_SYNCED, PropertyDiscoveryEvidence::SYNC_FAILED])],
        ]);

        $type = $data['evidence_type'];
        $maxPhotos = (int) config('discovery.evidence.max_property_photos', 3);

        if ($type === PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO
            && $discovery->evidence()->where('evidence_type', PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO)->count() >= $maxPhotos) {
            abort(422, "Only up to {$maxPhotos} property photographs may be attached to a discovery.");
        }

        if ($type === PropertyDiscoveryEvidence::TYPE_OWNERSHIP_DOCUMENT
            && $discovery->evidence()->where('evidence_type', PropertyDiscoveryEvidence::TYPE_OWNERSHIP_DOCUMENT)->exists()) {
            abort(422, 'An ownership/deed document is already attached to this discovery.');
        }

        $sequence = $type === PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO
            ? ((int) ($data['sequence_no'] ?? 0) ?: ($discovery->evidence()->where('evidence_type', PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO)->max('sequence_no') ?? 0) + 1)
            : null;

        $evidence = DB::transaction(function () use ($request, $discovery, $user, $data, $type, $sequence) {
            $file = $request->file('file');
            $directory = 'discovery-evidence/'.$discovery->discovery_reference;
            $path = $file->store($directory, 'local');

            return PropertyDiscoveryEvidence::create([
                'discovery_id' => $discovery->id,
                'property_id' => $discovery->property_id,
                'evidence_type' => $type,
                'sequence_no' => $sequence,
                'file_path' => $path,
                'original_name' => $data['original_name'] ?? $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
                'capture_source' => $data['capture_source'] ?? PropertyDiscoveryEvidence::SOURCE_UPLOAD,
                'captured_at' => $data['captured_at'] ?? now(),
                'captured_by' => $user->id,
                'gps_latitude' => $data['gps_latitude'] ?? null,
                'gps_longitude' => $data['gps_longitude'] ?? null,
                'sync_status' => $data['sync_status'] ?? PropertyDiscoveryEvidence::SYNC_SYNCED,
            ]);
        });

        $this->audit->record($discovery, 'discovery.evidence_attached', $user->id, [], [
            'evidence_id' => $evidence->id,
            'evidence_type' => $type,
            'sequence_no' => $evidence->sequence_no,
            'original_name' => $evidence->original_name,
        ]);

        return response()->json([
            'data' => $this->present($discovery->fresh(['evidence'])),
            'message' => $type === PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO
                ? 'Property photo '.$sequence.' of up to '.$maxPhotos.' attached.'
                : 'Ownership/deed document attached.',
        ]);
    }

    /** Show the raw evidence file (scope-guarded mirror of Retention/Evidence controller). */
    public function evidenceFile(Request $request, PropertyDiscovery $discovery, PropertyDiscoveryEvidence $evidence)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['discovery.view', 'discovery.create', 'discovery.review']), 403, 'Missing permission.');
        abort_unless($this->canViewDiscovery($discovery, $user), 403, 'Discovery is outside your scope.');
        abort_unless($evidence->discovery_id === $discovery->id, 404, 'Evidence not found on this discovery.');
        abort_unless(Storage::disk('local')->exists($evidence->file_path), 404, 'Evidence file no longer available.');

        $name = $evidence->original_name ?: Str::afterLast($evidence->file_path, '/');

        return response()->file(
            Storage::disk('local')->path($evidence->file_path),
            [
                'Content-Type' => $evidence->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="'.$name.'"',
            ]
        );
    }

    /** Remove evidence the recording officer attached (only while draft/returned). */
    public function deleteEvidence(Request $request, PropertyDiscovery $discovery, PropertyDiscoveryEvidence $evidence)
    {
        $user = $request->user();
        abort_unless($user->canPermission('discovery.create'), 403, 'Missing permission: discovery.create');
        abort_unless($this->canMutateDiscovery($discovery, $user), 403, 'You can only remove evidence from discoveries you recorded.');
        abort_unless(in_array($discovery->status, [PropertyDiscovery::STATUS_DRAFT, PropertyDiscovery::STATUS_RETURNED], true), 422, 'Evidence can only be removed while the discovery is a draft or returned for correction.');
        abort_unless($evidence->discovery_id === $discovery->id, 404, 'Evidence not found on this discovery.');

        $meta = ['evidence_id' => $evidence->id, 'evidence_type' => $evidence->evidence_type, 'original_name' => $evidence->original_name];

        Storage::disk('local')->delete($evidence->file_path);
        $evidence->delete();
        $this->audit->record($discovery, 'discovery.evidence_removed', $user->id, [], $meta);

        return response()->json([
            'data' => $this->present($discovery->fresh(['evidence'])),
            'message' => 'Evidence removed from the discovery.',
        ]);
    }

    public function update(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->canPermission('discovery.create'), 403, 'Missing permission: discovery.create');
        abort_unless($this->canMutateDiscovery($discovery, $user), 403, 'You can only edit discoveries you recorded.');
        abort_unless(in_array($discovery->status, [PropertyDiscovery::STATUS_DRAFT, PropertyDiscovery::STATUS_RETURNED], true), 422, 'Discovery cannot be edited in its current status.');

        $data = $request->validate($this->rules());

        $before = $discovery->only([
            'value_declaration_type', 'declared_assessed_value', 'documentary_value', 'official_assessed_value',
        ]);

        $discovery->update([
            'owner_name' => $data['owner_name'] ?? $discovery->owner_name,
            'owner_contact' => $data['owner_contact'] ?? $discovery->owner_contact,
            'tin' => $data['tin'] ?? $discovery->tin,
            'property_address' => $data['property_address'] ?? $discovery->property_address,
            'county' => $data['county'] ?? $discovery->county,
            'district' => $data['district'] ?? $discovery->district,
            'city_town' => $data['city_town'] ?? $discovery->city_town,
            'community' => $data['community'] ?? $discovery->community,
            'street' => $data['street'] ?? $discovery->street,
            'house_number' => $data['house_number'] ?? $discovery->house_number,
            'property_classification' => $data['property_classification'] ?? $discovery->property_classification,
            'property_type' => $data['property_type'] ?? $discovery->property_type,
            'occupancy_use' => $data['occupancy_use'] ?? $discovery->occupancy_use,
            'description' => $data['description'] ?? $discovery->description,
            'declared_value' => $data['declared_assessed_value'] ?? $data['declared_value'] ?? $discovery->declared_value,
            'value_declaration_type' => $data['value_declaration_type'] ?? $discovery->value_declaration_type,
            'declared_assessed_value' => $data['declared_assessed_value'] ?? $discovery->declared_assessed_value,
            'documentary_value' => $data['documentary_value'] ?? $discovery->documentary_value,
            'gps_coordinate' => $data['gps_coordinate'] ?? ($data['gps_lat'] ?? null ? $this->coordinateFrom($data) : $discovery->gps_coordinate),
            'gps_accuracy' => $data['gps_accuracy'] ?? $discovery->gps_accuracy,
            'gps_captured_at' => $data['gps_captured_at'] ?? $discovery->gps_captured_at,
            'discovery_date' => $data['discovery_date'] ?? $discovery->discovery_date,
            'remarks' => $data['remarks'] ?? $discovery->remarks,
        ]);

        $this->audit->record($discovery, 'discovery.updated', $user->id, $before, $discovery->only([
            'value_declaration_type', 'declared_assessed_value', 'documentary_value', 'official_assessed_value',
        ]));

        return response()->json(['data' => $this->present($discovery->fresh()), 'message' => 'Discovery updated.']);
    }

    /**
     * Discovery Officer submits the captured record. The system classifies the
     * property (config/discovery.php) and routes it to the correct desk —
     * the officer does not approve the property for processing (§2/§3).
     */
    public function submit(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->canPermission('discovery.create'), 403, 'Missing permission: discovery.create');
        abort_unless($this->canMutateDiscovery($discovery, $user), 403, 'You can only submit discoveries you recorded.');
        $this->guardStatus($discovery, [PropertyDiscovery::STATUS_DRAFT], 'Only a draft discovery can be submitted.');

        $this->assertRoutable($discovery, $user);

        $data = $request->validate([
            'value_declaration_type' => 'nullable|string|in:'.implode(',', PropertyDiscovery::VALUE_DECLARATION_TYPES),
            'declared_assessed_value' => 'nullable|numeric|min:0',
            'declared_value' => 'nullable|numeric|min:0',
            'documentary_value' => 'nullable|numeric|min:0',
        ]);

        $declaration = $this->resolveDeclaration($discovery, $data);
        $value = $data['declared_assessed_value'] ?? $data['declared_value'] ?? $discovery->declared_assessed_value ?? $discovery->declared_value;

        // A Discovery Officer may select 'Valuation' to route for formal valuation,
        // but can never fabricate the valuation amount — it comes from the authorized
        // valuation workflow only.
        if ($declaration === PropertyDiscovery::VALUE_DECLARATION_VALUATION
            && ($data['declared_assessed_value'] ?? $data['declared_value'] ?? $discovery->declared_assessed_value ?? $discovery->declared_value) !== null) {
            abort(422, 'You cannot assign a value to a formal valuation — select a declaration value type, or submit with no declared value so the Valuation Manager establishes it.');
        }

        // Value from Documentation requires supporting deed/ownership evidence.
        if ($declaration === PropertyDiscovery::VALUE_DECLARATION_DOCUMENTARY
            && config('discovery.evidence.require_ownership_document_when_documentary', true)) {
            abort_unless(
                $discovery->evidence()->where('evidence_type', PropertyDiscoveryEvidence::TYPE_OWNERSHIP_DOCUMENT)->exists(),
                422,
                'Value from Documentation requires supporting ownership/deed evidence before submission.'
            );
        }

        $path = $this->classificationPath($discovery->property_classification, $declaration);
        $classified = [
            'decision_path' => $path,
            'classification_decision' => $this->classificationDecision($discovery->property_classification, $path, $declaration),
            'classification_source' => config('discovery.decision_source_automatic', 'Automatic (RETD classification)'),
            'classified_by' => $user->id,
            'classified_at' => now(),
            'submitted_at' => now(),
            'value_declaration_type' => $declaration,
            'declared_assessed_value' => $value,
            'declared_value' => $value,
        ];

        $discovery->update($classified + [
            'status' => $path === 'account' ? PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW : PropertyDiscovery::STATUS_AWAITING_VALUATION,
            'current_owner_id' => null,
        ]);
        $this->audit->record($discovery, 'discovery.submitted', $user->id, [
            'status' => PropertyDiscovery::STATUS_DRAFT,
        ], [
            // The submission moves the record straight to the desk that has to act on
            // it. `Submitted` is not a state this workflow ever stores, so recording it
            // left the audit trail describing a transition that never happened.
            'status' => $discovery->status,
            'workflow_stage' => $path === 'account' ? 'Account & Record Manager review' : 'Valuation Manager review',
            'decision_path' => $path,
        ]);
        $this->audit->record($discovery, 'discovery.classified', $user->id, ['value_declaration_type' => null], [
            'decision_path' => $path,
            'value_declaration_type' => $declaration,
            'declared_assessed_value' => $value,
            'decision_source' => $classified['classification_source'],
        ]);

        $declarationLabel = $this->declarationLabel($declaration);

        if ($path === 'account') {
            $this->tasks->createDiscoveryReviewTask($discovery, $this->tasks->deskUser('Account Manager'), $user);
            $this->tasks->notifySection('ACCT', 'Discovery awaiting review', "Discovery {$discovery->discovery_reference} ({$declarationLabel}, ".$this->money($value).') awaits Account & Record Manager review.', 'discovery');
        } else {
            $this->tasks->createValuationManagerReviewTask($discovery, $this->tasks->deskUser('Valuation Manager'), $user);
            $this->tasks->notifySection('VAL', 'Discovery awaiting valuation decision', "Discovery {$discovery->discovery_reference} ({$declarationLabel}) awaits the Valuation Manager.", 'discovery');
        }

        return response()->json([
            'data' => $this->present($discovery->fresh(['discoverer:id,full_name'])),
            'message' => $path === 'account'
                ? 'Discovery submitted ('.$declarationLabel.') and routed to the Account & Record Manager queue.'
                : 'Discovery submitted ('.$declarationLabel.') and routed to the Valuation Manager queue.',
        ]);
    }

    /** Corrected discovery (A&R manager / valuation manager return) re-enters the routing pipeline. */
    public function resubmit(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->canPermission('discovery.create'), 403, 'Missing permission: discovery.create');
        abort_unless($this->canMutateDiscovery($discovery, $user), 403, 'You can only resubmit discoveries you recorded.');
        $this->guardStatus($discovery, [PropertyDiscovery::STATUS_RETURNED], 'Only discoveries returned for correction can be resubmitted.');

        $this->assertRoutable($discovery, $user);

        $data = $request->validate([
            'value_declaration_type' => 'nullable|string|in:'.implode(',', PropertyDiscovery::VALUE_DECLARATION_TYPES),
            'declared_assessed_value' => 'nullable|numeric|min:0',
            'declared_value' => 'nullable|numeric|min:0',
            'documentary_value' => 'nullable|numeric|min:0',
        ]);

        $declaration = $this->resolveDeclaration($discovery, $data);
        $value = $data['declared_assessed_value'] ?? $data['declared_value'] ?? $discovery->declared_assessed_value ?? $discovery->declared_value;

        abort_if(
            $declaration === PropertyDiscovery::VALUE_DECLARATION_VALUATION
            && ($data['declared_assessed_value'] ?? $data['declared_value'] ?? $discovery->declared_assessed_value ?? $discovery->declared_value) !== null,
            422,
            'You cannot assign a value to a formal valuation — submit with no declared value so the Valuation Manager establishes it.'
        );

        if ($declaration === PropertyDiscovery::VALUE_DECLARATION_DOCUMENTARY
            && config('discovery.evidence.require_ownership_document_when_documentary', true)) {
            abort_unless(
                $discovery->evidence()->where('evidence_type', PropertyDiscoveryEvidence::TYPE_OWNERSHIP_DOCUMENT)->exists(),
                422,
                'Value from Documentation requires supporting ownership/deed evidence before submission.'
            );
        }

        $path = $this->classificationPath($discovery->property_classification, $declaration);
        $discovery->update([
            'decision_path' => $path,
            'classification_decision' => $this->classificationDecision($discovery->property_classification, $path, $declaration),
            'classification_source' => config('discovery.decision_source_automatic', 'Automatic (RETD classification)'),
            'classified_by' => $user->id,
            'classified_at' => now(),
            'submitted_at' => now(),
            'status' => $path === 'account' ? PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW : PropertyDiscovery::STATUS_AWAITING_VALUATION,
            'value_declaration_type' => $declaration,
            'declared_assessed_value' => $value,
            'declared_value' => $value,
            'current_owner_id' => null,
        ]);

        $this->audit->record($discovery, 'discovery.resubmitted', $user->id, [], [
            'decision_path' => $path,
            'value_declaration_type' => $declaration,
            'declared_assessed_value' => $value,
        ]);

        // The correction task is finished by the resubmission, so it retires here
        // and the review desk task raised below becomes the only open work.
        $this->tasks->closeDiscoveryCorrectionTask($discovery, $user, 'Corrections submitted for review.');

        if ($path === 'account') {
            $this->tasks->createDiscoveryReviewTask($discovery, $this->tasks->deskUser('Account Manager'), $user);
            $this->tasks->notifySection('ACCT', 'Discovery resubmitted', "Discovery {$discovery->discovery_reference} (".$this->declarationLabel($declaration).') resubmitted after correction — awaiting Account & Record Manager review.', 'discovery');
        } else {
            $this->tasks->createValuationManagerReviewTask($discovery, $this->tasks->deskUser('Valuation Manager'), $user);
            $this->tasks->notifySection('VAL', 'Discovery resubmitted', "Discovery {$discovery->discovery_reference} resubmitted after correction — awaiting the Valuation Manager.", 'discovery');
        }

        return response()->json([
            'data' => $this->present($discovery->fresh(['discoverer:id,full_name'])),
            'message' => 'Discovery resubmitted and routed after correction.',
        ]);
    }

    /**
     * Account & Record Manager desk — review of a self-declared property
     * (Path A) and final review of an AC-approved valuation (Path B/D).
     * Decisions: approve (assign officer) / return / refer_to_valuation / reject.
     */
    public function acctReview(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->canPermission('discovery.aarm_review'), 403, 'Missing permission: discovery.aarm_review');
        abort_if($discovery->discovered_by === $user->id, 422, 'Separation of duties: you may not review a discovery you recorded.');
        $this->guardStatus($discovery, [PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW, PropertyDiscovery::STATUS_APPROVED], 'Discovery is not awaiting review at the Account & Record Manager desk.');

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'return', 'refer_to_valuation', 'reject'])],
            'officer_id' => 'nullable|integer|exists:users,id',
            'remarks' => 'nullable|string|max:2000',
        ]);

        if ($data['decision'] === 'approve') {
            abort_unless($data['officer_id'], 422, 'Assign an eligible Account & Record Officer to process the property registration.');

            return $this->aarmApprove($discovery, $user, $data);
        }

        if ($data['decision'] === 'refer_to_valuation') {
            return $this->aarmReferValuation($discovery, $user, $data);
        }

        $discovery->update([
            'status' => $data['decision'] === 'reject' ? PropertyDiscovery::STATUS_REJECTED : PropertyDiscovery::STATUS_RETURNED,
            'aarm_reviewed_by' => $user->id,
            'aarm_reviewed_at' => now(),
            'aarm_decision' => $data['decision'],
            'aarm_remarks' => $data['remarks'] ?? null,
        ]);
        $this->audit->record($discovery, $data['decision'] === 'reject' ? 'discovery.rejected' : 'discovery.returned', $user->id, [], ['aarm_decision' => $data['decision']]);

        // The manager has finished reviewing, whichever way it went, so the Discovery
        // Review task retires here too. A returned or rejected record is not work this
        // desk still owes, and the task row stays for the audit trail.
        $this->tasks->closeStageTasks(
            Task::TYPE_DISCOVERY_REVIEW,
            'property_discovery',
            $discovery->id,
            $data['decision'] === 'reject' ? 'rejected' : 'returned_for_correction',
            $user,
            $data['remarks'] ?? null,
        );

        if ($data['decision'] === 'return') {
            // A return is actionable work for the officer who recorded the record:
            // raise (or continue) their correction task so it reaches My Tasks and
            // can age, instead of only a notification.
            $this->tasks->createDiscoveryCorrectionTask(
                $discovery,
                $discovery->discoverer,
                $user,
                'Returned by the Account & Record Manager: '.($data['remarks'] ?? 'Correction required.'),
            );
        }

        $this->tasks->notify($discovery->discoverer, 'Discovery returned', "Discovery {$discovery->discovery_reference} requires correction: ".($data['remarks'] ?? 'Review remarks omitted.'), 'discovery');

        return response()->json([
            'data' => $this->present($discovery->fresh()),
            'message' => $data['decision'] === 'reject' ? 'Discovery rejected.' : 'Discovery returned for correction.',
        ]);
    }

    /**
     * A&R Manager approves the record and assigns an Account & Record Officer.
     * From here the officer does not review — they receive the assigned
     * processing task, register the property in the source system, and then
     * update the property bill record in RETD.
     */
    private function aarmApprove(PropertyDiscovery $discovery, User $user, array $data)
    {
        abort_unless($user->canPermission('discovery.aarm_approve'), 403, 'Missing permission: discovery.aarm_approve');

        // Path B/C: once the linked valuation has reached Account & Record it owns
        // the officer assignment. That includes AC approval, where the Account &
        // Record Manager's final review on the valuation is the single approval
        // that assigns processing — approving the discovery again here would create
        // a second, conflicting processing task and skip that review.
        if ($discovery->valuation_id) {
            $valuation = Valuation::find($discovery->valuation_id);
            abort_if(
                $valuation && in_array($valuation->status, [
                    Valuation::STATUS_AC_APPROVED,
                    Valuation::STATUS_SENT_TO_ACCT,
                    Valuation::STATUS_REGISTERED,
                    Valuation::STATUS_COMPLETED,
                ], true),
                422,
                'This discovery is already assigned for processing through valuation '.$valuation?->valuation_reference.' — assignment is managed on the valuation record.'
            );
        }

        $officer = User::find($data['officer_id']);
        abort_unless(
            $officer && AssignmentEligibilityService::canExecuteTaskType($officer, Task::TYPE_ACCOUNT_PROCESSING),
            422,
            'Assignment rejected. '.($officer?->full_name ?? 'User').' does not have permission to process property registration.'
        );

        $discovery->update([
            'status' => PropertyDiscovery::STATUS_ASSIGNED_ACCT,
            'current_owner_id' => $officer->id,
            'aarm_reviewed_by' => $user->id,
            'aarm_reviewed_at' => now(),
            'aarm_decision' => 'approved',
            'aarm_remarks' => $data['remarks'] ?? null,
        ]);

        $this->tasks->createAccountProcessingTask(
            'property_discovery',
            $discovery->id,
            "Discovery {$discovery->discovery_reference}",
            $officer,
            $user,
        );

        // This desk decision is now made, so the Discovery Review task retires. Left
        // open it competed with the officer's processing task raised here — two open
        // tasks on one record, so a property that was fully completed still sat under
        // the manager's Active tab and their counters never reached Completed.
        $this->tasks->closeStageTasks(
            Task::TYPE_DISCOVERY_REVIEW,
            'property_discovery',
            $discovery->id,
            'approved_for_processing',
            $user,
            "Discovery {$discovery->discovery_reference} approved and assigned to {$officer->full_name} for processing."
        );

        $this->tasks->notify($officer, 'Property approved for processing', "Discovery {$discovery->discovery_reference} assigned to you — process the property registration in the source system, then update the property bill record in RETD.", 'discovery');

        $this->audit->record($discovery, 'discovery.approved', $user->id, [], ['aarm_decision' => 'approved', 'officer_id' => $officer->id]);

        return response()->json([
            'data' => $this->present($discovery->fresh(['currentOwner:id,full_name'])),
            'message' => "Discovery approved and assigned to {$officer->full_name} for property registration processing.",
        ]);
    }

    /**
     * Path C — the A&R Manager determines that a valuation is required after all.
     * The original discovery remains intact; the referral is audited and the
     * record moves to the Valuation Manager queue (§5 / Rule 7).
     */
    private function aarmReferValuation(PropertyDiscovery $discovery, User $user, array $data)
    {
        abort_unless($user->canPermission('discovery.refer_to_valuation'), 403, 'Missing permission: discovery.refer_to_valuation');

        $fromStage = $discovery->status;
        $discovery->update([
            'decision_path' => 'valuation',
            'classification_decision' => config('discovery.decision_source_manager', 'Management decision').': referred to valuation',
            'classification_source' => config('discovery.decision_source_manager', 'Management decision'),
            'classified_by' => $user->id,
            'classified_at' => now(),
            'status' => PropertyDiscovery::STATUS_REFERRED_VALUATION,
            'aarm_reviewed_by' => $user->id,
            'aarm_reviewed_at' => now(),
            'aarm_decision' => 'referred',
            'aarm_remarks' => $data['remarks'] ?? null,
            'referred_by' => $user->id,
            'referred_at' => now(),
            'referral_reason' => $data['remarks'] ?? 'Determined to require formal valuation.',
            'referral_from_stage' => $fromStage,
        ]);

        $this->tasks->createValuationManagerReviewTask($discovery, $this->tasks->deskUser('Valuation Manager'), $user);

        // The A&R review is finished — the record has left this desk for valuation, so
        // its Discovery Review task retires rather than sitting in the manager's Active
        // list beside the Valuation Manager task this referral just raised.
        $this->tasks->closeStageTasks(
            Task::TYPE_DISCOVERY_REVIEW,
            'property_discovery',
            $discovery->id,
            'referred_to_valuation',
            $user,
            $data['remarks'] ?? 'Referred to valuation.',
        );

        $this->tasks->notifySection('VAL', 'Discovery referred to valuation', "Discovery {$discovery->discovery_reference} referred by {$user->full_name} (A&R desk) for formal valuation.", 'discovery');

        $this->audit->record($discovery, 'discovery.referred_to_valuation', $user->id, [], [
            'referred_by' => $user->full_name,
            'referring_section' => 'Account & Records (ACCT)',
            'reason' => $data['remarks'] ?? 'Determined to require formal valuation.',
            'destination' => 'Valuation Manager (VAL)',
            'from_stage' => $fromStage,
            'to_stage' => PropertyDiscovery::STATUS_REFERRED_VALUATION,
        ]);

        return response()->json([
            'data' => $this->present($discovery->fresh(['referredByUser:id,full_name'])),
            'message' => 'Discovery referred to the Valuation Manager queue. Original record preserved.',
        ]);
    }

    /**
     * Valuation Manager desk — decide how the valuation assessment is handled
     * (§7). Options: assign to an eligible Valuation Officer, conduct it
     * personally, or return the discovery for correction/additional info.
     */
    public function valReview(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->canPermission('discovery.route_to_valuation'), 403, 'Missing permission: discovery.route_to_valuation');
        abort_if($discovery->discovered_by === $user->id, 422, 'Separation of duties: you may not route a discovery you recorded.');
        $this->guardStatus($discovery, [PropertyDiscovery::STATUS_AWAITING_VALUATION, PropertyDiscovery::STATUS_REFERRED_VALUATION], 'Discovery is not awaiting action at the Valuation Manager desk.');

        $data = $request->validate([
            'decision' => ['required', Rule::in(['assign', 'self', 'return'])],
            'officer_id' => 'nullable|integer|exists:users,id',
            'remarks' => 'nullable|string|max:2000',
        ]);

        if ($data['decision'] === 'return') {
            $discovery->update([
                'status' => PropertyDiscovery::STATUS_RETURNED,
                'manager_remarks' => $data['remarks'] ?? $discovery->manager_remarks,
            ]);
            $this->audit->record($discovery, 'discovery.returned', $user->id, [], ['desk' => 'valuation']);

            // Same rule at this desk: the recorder's correction is real work and
            // must be traceable, not only announced.
            $this->tasks->createDiscoveryCorrectionTask(
                $discovery,
                $discovery->discoverer,
                $user,
                'Returned by the Valuation Manager: '.($data['remarks'] ?? 'Additional information required.'),
            );

            $this->tasks->notify($discovery->discoverer, 'Discovery returned', "Discovery {$discovery->discovery_reference} returned by the Valuation Manager: ".($data['remarks'] ?? 'Additional information required.'), 'discovery');

            return response()->json(['data' => $this->present($discovery->fresh()), 'message' => 'Discovery returned to the discovering officer for correction.']);
        }

        if ($data['decision'] === 'assign') {
            abort_unless($data['officer_id'], 422, 'Select a Valuation Officer to assign the assessment.');
        }

        // "Conduct personally" makes the caller the valuation officer. Holding the
        // routing permission is not enough: begin() and submit() both require
        // valuation.submit, so a manager without it would be handed work they
        // cannot start or finish. Assignment eligibility alone does not catch this
        // — the Valuation Manager is eligible to hold the task, not to submit it.
        if ($data['decision'] === 'self') {
            abort_unless(
                $user->canPermission('valuation.submit'),
                422,
                'You cannot conduct this valuation personally: valuation.submit is required to begin and submit an assessment. Assign it to a Valuation Officer instead.'
            );
        }

        $officer = $data['decision'] === 'assign' ? User::find($data['officer_id']) : $user;
        abort_unless(
            $officer && AssignmentEligibilityService::canExecuteTaskType($officer, Task::TYPE_VALUATION),
            422,
            'Assignment rejected. '.$officer->full_name.' does not have permission to perform Valuation.'
        );

        // A discovery can return to this desk with an assessment already attached:
        // the Assistant Commissioner returns the assessment, the recording officer
        // corrects the discovery and resubmits it, and the record is routed again.
        // That is the same property and the same assessment, so it is reopened —
        // same Valuation ID and reference — instead of a second valuation being
        // recorded for one discovery.
        $valuation = $discovery->valuation_id ? Valuation::find($discovery->valuation_id) : null;
        $reused = (bool) $valuation;

        if ($valuation) {
            abort_if(
                in_array($valuation->status, [Valuation::STATUS_SENT_TO_ACCT, Valuation::STATUS_REGISTERED, Valuation::STATUS_COMPLETED], true),
                422,
                'Assessment '.$valuation->valuation_reference.' is already being processed for registration and cannot be routed again.'
            );

            $valuation->update([
                'valuation_officer_id' => $officer->id,
                'status' => Valuation::STATUS_ASSIGNED,
                'assessment_date' => $valuation->assessment_date ?? now()->toDateString(),
            ]);
        } else {
            $valuation = Valuation::create([
                'valuation_type' => 'new_property',
                'discovery_id' => $discovery->id,
                'owner_name' => $discovery->owner_name ?? 'Discovered Property',
                'owner_contact' => $discovery->owner_contact,
                'tin' => $discovery->tin,
                'property_classification' => $discovery->property_classification,
                'property_address' => $discovery->property_address,
                'declared_value' => $discovery->declared_value,
                'gps_coordinate' => $discovery->gps_coordinate,
                'assessment_date' => now()->toDateString(),
                'valuation_officer_id' => $officer->id,
                'status' => Valuation::STATUS_ASSIGNED,
                'remarks' => "Created from discovery {$discovery->discovery_reference}.",
            ]);
        }

        $this->tasks->createValuationTask($valuation, $officer, $user);

        // This desk decision is now made, so the discovery's Valuation Manager Review
        // task retires. Left open it competed with the Valuation Review task raised
        // later on the same property — two open tasks, anchored on two different rows
        // of one lifecycle, and the property looked like two separate records in the
        // manager's list. The assessment itself is now the record under review.
        $this->tasks->closeStageTasks(
            Task::TYPE_VALUATION_MANAGER_REVIEW,
            'property_discovery',
            $discovery->id,
            'routed_to_valuation',
            $user,
            "Discovery {$discovery->discovery_reference} routed to valuation assessment {$valuation->valuation_reference}."
        );

        $discovery->update([
            'status' => PropertyDiscovery::STATUS_UNDER_VALUATION,
            'valuation_id' => $valuation->id,
            'current_owner_id' => $officer->id,
            'manager_remarks' => $data['remarks'] ?? $discovery->manager_remarks,
        ]);

        $this->tasks->notify($officer, 'Valuation assigned', "Valuation {$valuation->valuation_reference} assigned from discovery {$discovery->discovery_reference}.", 'valuation');

        $this->audit->record($discovery, 'discovery.routed_to_valuation', $user->id, [], [
            'valuation_id' => $valuation->id,
            'valuation_officer_id' => $officer->id,
            'mode' => $data['decision'],
            'reopened_existing_valuation' => $reused,
        ]);

        return response()->json([
            'data' => $this->present($discovery->fresh(['valuation:id,valuation_reference,status,valuation_officer_id', 'currentOwner:id,full_name'])),
            'message' => 'Valuation assessment '.($data['decision'] === 'self' ? 'assigned to the Valuation Manager (self).' : "assigned to {$officer->full_name}."),
        ]);
    }

    /**
     * Account & Record Officer records the completed property processing (§14).
     *
     * The officer is not reviewing — the Account & Record Manager already did.
     * The officer has an assigned task: register the property in the source
     * system, then update the property bill record in RETD. Recording the
     * result (a) completes the officer's task and (b) creates the property
     * bill record, or updates it when the assessment for this property and tax
     * period is already on the register — the one-assessment rule still holds,
     * the same row is amended and never duplicated.
     */
    public function accountProcessing(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['discovery.property_registration', 'discovery.aarm_review']), 403, 'Missing permission: discovery.property_registration');
        abort_if(
            in_array($discovery->status, [PropertyDiscovery::STATUS_REGISTERED, PropertyDiscovery::STATUS_COMPLETED], true),
            422,
            'Already processed — this discovery is at "'.$discovery->status.'" and cannot be processed again.'
        );
        $this->guardStatus($discovery, [PropertyDiscovery::STATUS_ASSIGNED_ACCT], 'Only records assigned to an Account & Record Officer can be processed.');
        abort_unless($discovery->current_owner_id === $user->id || $user->canPermission('discovery.aarm_review'), 403, 'This property is not assigned to you for processing.');

        $data = $request->validate([
            'property_id' => ['required', 'string', 'max:60'],
            'document_number' => ['required', 'string', 'max:80'],
            'property_tax_amount' => ['required', 'numeric', 'min:0'],
            'tax_period' => ['required', 'string', 'max:50'],
            'taxpayer_name' => ['nullable', 'string', 'max:255'],
            'interest_charged' => ['nullable', 'numeric', 'min:0'],
            'penalty_charged' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $propertyId = trim((string) ($data['property_id'] ?? ''));
        $documentNumber = trim((string) ($data['document_number'] ?? ''));
        $remarks = trim((string) ($data['remarks'] ?? ''));
        $bill = null;
        $billAction = 'created';

        DB::transaction(function () use ($discovery, $user, $data, $propertyId, $documentNumber, $remarks, &$bill, &$billAction) {
            $discovery->update([
                'property_id' => $propertyId,
                'document_number' => $documentNumber,
                'status' => PropertyDiscovery::STATUS_REGISTERED,
                'processed_by' => $user->id,
                'processed_at' => now(),
                'remarks' => $remarks !== '' ? $remarks : $discovery->remarks,
            ]);

            // The source system has now issued the Property ID: stamp it on the
            // permanent identity and on every record already linked to it.
            $this->identity->recordSourcePropertyId($discovery, $propertyId);

            // The officer's processing task automatically becomes a completed task.
            $this->completeProcessingTasks([$discovery], $propertyId, $documentNumber, $user);

            // Mirror onto any linked valuation so the whole lifecycle stays consistent.
            if ($discovery->valuation_id) {
                $valuation = Valuation::find($discovery->valuation_id);
                if ($valuation) {
                    $valuation->update([
                        'property_id' => $propertyId,
                        'document_number' => $documentNumber,
                        'registration_status' => Valuation::REGISTRATION_COMPLETED,
                        'registration_completed_by' => $user->id,
                        'registration_completed_at' => now(),
                        'status' => Valuation::STATUS_REGISTERED,
                    ]);
                    $this->completeProcessingTasks([$valuation], $propertyId, $documentNumber, $user);
                }
            }

            // Update the property bill record in RETD — logged when the
            // assessment is new, amended when the property/tax period is
            // already on the register.
            $bill = $this->syncRegisteredPropertyBill($discovery, $data, $user, $billAction);

            $this->audit->record($discovery, 'discovery.registration_recorded', $user->id, [], $data);
        });

        return response()->json([
            'data' => array_merge($this->present($discovery->fresh()), $bill ? [
                'bill_action' => $billAction,
                'bill' => [
                    'bill_id' => $bill->id,
                    'document_number' => $bill->document_number,
                    'tax_period' => $bill->tax_period,
                    'total_tax_due' => $bill->total_tax_due,
                    'outstanding_balance' => $bill->outstanding_balance,
                    'outcome' => $billAction,
                    'logged_by' => $user->full_name,
                ],
            ] : []),
            'message' => $billAction === 'updated'
                ? 'Property processed and the existing property bill record was updated.'
                : 'Property processed and the property bill record was created.',
        ]);
    }

    /** Close the open processing tasks for a record (completion + engagement). */
    private function completeProcessingTasks(array $records, string $propertyId, string $documentNumber, User $user): void
    {
        $details = 'Property processing recorded. Property ID: '.($propertyId !== '' ? $propertyId : '—').', Document #: '.($documentNumber !== '' ? $documentNumber : '—');

        foreach ($records as $record) {
            $record->tasks()
                ->where('task_type', Task::TYPE_ACCOUNT_PROCESSING)
                ->whereNotIn('status', Task::COMPLETED_STATUSES)
                ->get()
                ->each(function (Task $task) use ($details, $user) {
                    $task->forceTransition('Closed', 'registration_completed', $user, $details);
                    $task->recordEngagement('completed', 'registration_completed', $details, $user);
                });
        }
    }

    /**
     * Update the property bill record credited to the staff member who completed
     * the processing. The one-assessment rule (Property ID + TIN + Tax Period)
     * still applies: an existing assessment for the same property and period is
     * amended in place rather than duplicated, and a new one is logged otherwise.
     * Either way the bill enters/keeps its place in the enforcement queue.
     */
    private function syncRegisteredPropertyBill(PropertyDiscovery $discovery, array $data, User $user, string &$outcome = 'created'): PropertyBill
    {
        $propertyId = trim((string) $data['property_id']);
        $tin = trim((string) ($discovery->tin ?? ''));
        $period = trim((string) ($data['tax_period'] ?? ''));

        // The approved assessment is the authority for the bill. Discovery data is
        // only the fallback for a record that never went through valuation.
        $valuation = $discovery->valuation_id ? Valuation::find($discovery->valuation_id) : null;

        if (filled($valuation?->tin)) {
            $tin = trim((string) $valuation->tin);
        }

        $taxAmount = (float) $data['property_tax_amount'];

        // A manually entered tax amount must not silently contradict the approved
        // assessment: the register would then carry a figure no authorised record
        // supports. The officer records the approved amount, or the assessment goes
        // back to the Valuation Section.
        if ($valuation && $valuation->annual_tax !== null) {
            $approvedTax = round((float) $valuation->annual_tax, 2);

            if (abs($taxAmount - $approvedTax) > 0.01) {
                abort(422, sprintf(
                    'The tax amount entered (%s) does not match the approved valuation %s (annual tax %s). Record the approved amount, or return the assessment to the Valuation Section before processing.',
                    $this->money($taxAmount),
                    $valuation->valuation_reference,
                    $this->money($approvedTax),
                ));
            }

            $taxAmount = $approvedTax;
        }

        $interest = (float) ($data['interest_charged'] ?? 0);
        $penalty = (float) ($data['penalty_charged'] ?? 0);
        $total = $taxAmount + $interest + $penalty;

        $attributes = [
            'document_number' => trim((string) $data['document_number']),
            'property_id' => $propertyId,
            'taxpayer_name' => trim((string) ($data['taxpayer_name'] ?? $valuation?->owner_name ?? $discovery->owner_name ?? '')),
            'tin' => $tin,
            'property_classification' => $valuation?->property_classification ?: $discovery->property_classification,
            'property_address' => $valuation?->property_address ?: $discovery->property_address,
            'assessed_value' => $valuation?->assessed_value ?? $discovery->official_assessed_value ?? $discovery->declared_assessed_value ?? $discovery->declared_value,
            'tax_amount' => $taxAmount,
            'interest_charged' => $interest,
            'penalty_charged' => $penalty,
            'total_tax_due' => $total,
            'tax_period' => $period !== '' ? $period : null,
            'property_type' => $discovery->property_type ?: $discovery->occupancy_use,
            'account_staff_id' => $user->id,
        ];

        // Same assessment: Property ID + Tax Period, with the TIN used only when
        // both the discovery and the register carry one. A discovery with no TIN
        // therefore still finds — and updates — the bill already logged for the
        // property instead of raising a second one.
        $existing = PropertyBill::findEquivalentAssessment($propertyId, $tin, $period);

        if ($existing) {
            // The register is the authority on the identifiers it already holds:
            // processing a discovery that has no TIN must not erase the TIN the
            // logged bill carries.
            if ($tin === '') {
                unset($attributes['tin']);
            }

            $before = $existing->only(['document_number', 'taxpayer_name', 'tax_amount', 'interest_charged', 'penalty_charged', 'total_tax_due', 'outstanding_balance', 'assessed_value']);
            $existing->update($attributes);
            $outcome = 'updated';

            $this->audit->record($existing, 'bill.updated_from_processing', $user->id, $before, array_merge($attributes, [
                'discovery_id' => $discovery->id,
                'previous_document_number' => $before['document_number'],
                'value_declaration_type' => $discovery->value_declaration_type,
            ]));

            return $existing->fresh();
        }

        $bill = PropertyBill::create($attributes + [
            'recipient_type' => PropertyBill::RECIPIENT_WALK_IN,
            'date_logged' => now()->toDateString(),
            'delivery_status' => 'Logged',
            'payment_status' => 'Unpaid',
            'case_status' => 'Logged',
        ]);
        $outcome = 'created';

        // One source of truth for delivery: a logged bill leaves the register
        // head-office queue for enforcement assignment.
        $this->tasks->createTaskFromBill($bill);

        $this->audit->record($bill, 'bill.logged_from_processing', $user->id, [], [
            'discovery_id' => $discovery->id,
            'document_number' => $bill->document_number,
            'tax_period' => $period,
            'value_declaration_type' => $discovery->value_declaration_type,
            'declared_assessed_value' => $discovery->declared_assessed_value ?? $discovery->declared_value,
            'official_assessed_value' => $discovery->official_assessed_value,
            'assessed_value_used' => $bill->assessed_value,
        ]);

        return $bill;
    }

    public function complete(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['discovery.property_registration', 'discovery.aarm_review']), 403, 'Missing permission.');
        $this->guardStatus($discovery, [PropertyDiscovery::STATUS_REGISTERED], 'Only registered discoveries can be completed.');

        $discovery->update(['status' => PropertyDiscovery::STATUS_COMPLETED, 'completed_at' => now()]);
        $this->audit->record($discovery, 'discovery.completed', $user->id, [], ['status' => PropertyDiscovery::STATUS_COMPLETED]);

        if ($discovery->valuation_id) {
            Valuation::where('id', $discovery->valuation_id)
                ->where('status', Valuation::STATUS_REGISTERED)
                ->update(['status' => Valuation::STATUS_COMPLETED]);
        }

        return response()->json(['data' => $this->present($discovery->fresh()), 'message' => 'Discovery completed.']);
    }

    /** Reopen a rejected / returned discovery so the discovering officer can correct it. */
    public function reopen(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->canPermission('discovery.reopen'), 403, 'Missing permission: discovery.reopen');
        $this->guardStatus($discovery, [PropertyDiscovery::STATUS_REJECTED, PropertyDiscovery::STATUS_RETURNED], 'Only rejected / returned discoveries can be reopened.');

        $discovery->update(['status' => PropertyDiscovery::STATUS_DRAFT]);
        $this->audit->record($discovery, 'discovery.reopened', $user->id, [], ['status' => PropertyDiscovery::STATUS_DRAFT]);

        return response()->json(['data' => $this->present($discovery->fresh()), 'message' => 'Discovery reopened for correction.']);
    }

    /** Discovery dashboard aggregates (drill-downable through the list). */
    public function stats(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['discovery.view', 'discovery.review', 'dashboard.view_division', 'dashboard.view_section']), 403, 'Missing permission.');

        $base = PropertyDiscovery::query();

        foreach ([
            'status' => 'status',
            'officer_id' => 'discovered_by',
            'classification' => 'property_classification',
            'value_declaration_type' => 'value_declaration_type',
        ] as $q => $col) {
            if ($out = $request->query($q)) {
                $base->where($col, $out);
            }
        }
        if ($path = $request->query('path')) {
            $base->where('decision_path', $path);
        }
        if ($from = $request->query('date_from')) {
            $base->whereDate('discovery_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $base->whereDate('discovery_date', '<=', $to);
        }

        $this->restrictDataScope($base, $user, $request->query('path'));

        $byStatus = (clone $base)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')->pluck('total', 'status');

        $byStaff = (clone $base)
            ->selectRaw('discovered_by, count(*) as total')
            ->whereNotNull('discovered_by')->groupBy('discovered_by')
            ->with('discoverer:id,full_name')
            ->get()
            ->map(fn ($d) => [
                'officer' => $d->discoverer?->full_name ?? "User #{$d->discovered_by}",
                'user_id' => $d->discovered_by,
                'total' => (int) $d->total,
            ])->values();

        $pendingReview = (int) ($byStatus[PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW] ?? 0)
            + (int) ($byStatus[PropertyDiscovery::STATUS_AWAITING_VALUATION] ?? 0)
            + (int) ($byStatus[PropertyDiscovery::STATUS_REFERRED_VALUATION] ?? 0);

        $byDeclaration = (clone $base)
            ->selectRaw('value_declaration_type, count(*) as total')
            ->whereNotNull('value_declaration_type')->groupBy('value_declaration_type')
            ->pluck('total', 'value_declaration_type')
            ->mapWithKeys(fn ($total, $type) => [$type => ['count' => (int) $total]])->all();

        $valueTotals = (clone $base)
            ->selectRaw('sum(declared_assessed_value) as declared_total, sum(official_assessed_value) as official_total, avg(declared_assessed_value) as declared_average')
            ->first();

        $n = fn (string $s) => (int) ($byStatus[$s] ?? 0);

        return response()->json(['data' => [
            'scope' => $user->scopeLevel(),
            'total' => (int) (clone $base)->count(),
            'by_status' => $byStatus,
            'by_staff' => $byStaff,
            'by_value_declaration_type' => $byDeclaration,
            'value_totals' => [
                'declared_total' => $valueTotals->declared_total !== null ? (float) $valueTotals->declared_total : 0.0,
                'official_total' => $valueTotals->official_total !== null ? (float) $valueTotals->official_total : 0.0,
                'declared_average' => $valueTotals->declared_average !== null ? (float) $valueTotals->declared_average : 0.0,
            ],
            'awaiting_review' => $pendingReview,
            'awaiting_acct_review' => $n(PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW),
            'awaiting_valuation' => $n(PropertyDiscovery::STATUS_AWAITING_VALUATION)
                + $n(PropertyDiscovery::STATUS_REFERRED_VALUATION),
            'under_valuation' => $n(PropertyDiscovery::STATUS_UNDER_VALUATION),
            'awaiting_ac' => $n(PropertyDiscovery::STATUS_AWAITING_AC_APPROVAL),
            'approved' => $n(PropertyDiscovery::STATUS_APPROVED),
            'assigned_to_account' => $n(PropertyDiscovery::STATUS_ASSIGNED_ACCT),
            'returned' => $n(PropertyDiscovery::STATUS_RETURNED),
            'rejected' => $n(PropertyDiscovery::STATUS_REJECTED),
            'registered' => $n(PropertyDiscovery::STATUS_REGISTERED),
            'completed' => $n(PropertyDiscovery::STATUS_COMPLETED),
            'path_a_pipeline' => [
                'draft' => $n(PropertyDiscovery::STATUS_DRAFT),
                'awaiting_acct_review' => $n(PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW),
                'assigned_to_account' => $n(PropertyDiscovery::STATUS_ASSIGNED_ACCT),
                'registered' => $n(PropertyDiscovery::STATUS_REGISTERED),
                'completed' => $n(PropertyDiscovery::STATUS_COMPLETED),
            ],
            'path_v_pipeline' => [
                'draft' => $n(PropertyDiscovery::STATUS_DRAFT),
                'awaiting_valuation' => $n(PropertyDiscovery::STATUS_AWAITING_VALUATION) + $n(PropertyDiscovery::STATUS_REFERRED_VALUATION),
                'under_valuation' => $n(PropertyDiscovery::STATUS_UNDER_VALUATION),
                'awaiting_ac' => $n(PropertyDiscovery::STATUS_AWAITING_AC_APPROVAL),
                'approved' => $n(PropertyDiscovery::STATUS_APPROVED),
                'rejected' => $n(PropertyDiscovery::STATUS_REJECTED),
                'assigned_to_account' => $n(PropertyDiscovery::STATUS_ASSIGNED_ACCT),
                'registered' => $n(PropertyDiscovery::STATUS_REGISTERED),
                'completed' => $n(PropertyDiscovery::STATUS_COMPLETED),
            ],
        ]]);
    }

    /**
     * Unified property lifecycle profile: resolve a property and return every
     * linked Discovery, Valuation, Reassessment and Bill record in one payload.
     *
     * The aggregation, scope enforcement and record projections live in
     * PropertyProfileService so the same rules can be reused by the Property
     * View and tested independently of this controller. The legacy
     * bills/discoveries/valuations keys are still emitted because
     * BillDrawer consumes them.
     */
    public function profile(Request $request, PropertyProfileService $profiles)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['discovery.view', 'discovery.create', 'valuation.view_history', 'valuation.review', 'reports.view', 'dashboard.view_division']), 403, 'Missing permission to view the property profile.');

        $billId = $request->query('bill_id');

        $data = $profiles->build(
            $user,
            $request->query('property_id'),
            $request->query('document_number'),
            $billId !== null ? (int) $billId : null,
            $request->query('source_reference'),
        );

        return response()->json(['data' => $data]);
    }

    /* ---------- helpers ---------- */

    private function rules(): array
    {
        return [
            'owner_name' => 'nullable|string|max:255',
            'owner_contact' => 'nullable|string|max:100',
            'tin' => 'nullable|string|max:40',
            'property_address' => 'nullable|string|max:255',
            'county' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'city_town' => 'nullable|string|max:100',
            'community' => 'nullable|string|max:100',
            'street' => 'nullable|string|max:150',
            'house_number' => 'nullable|string|max:50',
            'property_classification' => 'nullable|string|max:100',
            'property_type' => 'nullable|string|max:100',
            'occupancy_use' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:4000',
            'declared_value' => 'nullable|numeric|min:0',
            'value_declaration_type' => 'nullable|string|in:'.implode(',', PropertyDiscovery::VALUE_DECLARATION_TYPES),
            'declared_assessed_value' => 'nullable|numeric|min:0',
            'documentary_value' => 'nullable|numeric|min:0',
            'gps_lat' => 'nullable|numeric',
            'gps_lng' => 'nullable|numeric',
            'gps_coordinate' => 'nullable|string|max:100',
            'gps_accuracy' => 'nullable|numeric|min:0',
            'gps_captured_at' => 'nullable|date',
            'discovery_date' => 'nullable|date',
            'remarks' => 'nullable|string|max:2000',
            'local_transaction_id' => 'nullable|string|max:80',
        ];
    }

    /**
     * Routing: the recorded Value Declaration Type is the primary signal (§2/§4);
     * classification lists remain the fallback for legacy records that predate
     * the declaration model.
     */
    private function classificationPath(?string $classification, ?string $declaration = null): string
    {
        if ($declaration !== null && array_key_exists($declaration, config('discovery.value_declaration_types') ?? [])) {
            return config('discovery.value_declaration_types')[$declaration];
        }

        $classification = $classification ?: '';
        $rules = config('discovery');

        if (in_array($classification, $rules['self_declared'] ?? [], true)) {
            return 'account';
        }
        if (in_array($classification, $rules['valuation_required'] ?? [], true)) {
            return 'valuation';
        }

        return $rules['default_path'] ?? 'valuation';
    }

    private function classificationDecision(?string $classification, string $path, ?string $declaration = null): string
    {
        $source = config('discovery.decision_source_automatic', 'Automatic (RETD classification)');
        $declarationPart = $declaration ? $this->declarationLabel($declaration) : 'self-declared (no formal valuation)';
        $label = ($classification ?: 'Unclassified').' → '.$declarationPart;
        if ($path === 'valuation') {
            $label = ($classification ?: 'Unclassified').' → requires formal valuation';
        }

        return $source.': '.$label;
    }

    private function declarationLabel(?string $declaration): string
    {
        return app(DiscoveryPresenter::class)->declarationLabel($declaration);
    }

    private function resolveDeclaration(PropertyDiscovery $discovery, array $data): ?string
    {
        if (! empty($data['value_declaration_type'])) {
            return $data['value_declaration_type'];
        }

        return $discovery->value_declaration_type;
    }

    private function money($value): string
    {
        return $value === null ? '—' : '$'.number_format((float) $value, 2);
    }

    private function queues(): array
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

    private function hasLocationOrAddress(array $data): bool
    {
        return ! empty($data['property_address'])
            || ! empty($data['gps_coordinate'])
            || (! empty($data['gps_lat']) && ! empty($data['gps_lng']));
    }

    private function coordinateFrom(array $data): ?string
    {
        if (! empty($data['gps_coordinate'])) {
            return $data['gps_coordinate'];
        }

        return isset($data['gps_lat'], $data['gps_lng'])
            ? trim($data['gps_lat'].','.$data['gps_lng'])
            : null;
    }

    /**
     * A discovery may only be routed once it says whose property it is and what
     * kind of property it is.
     *
     * Owner Name and Property Classification are both consumed downstream — the
     * owner becomes the taxpayer on the valuation and the bill, and the
     * classification decides which desk the record goes to — so routing a record
     * without them produced a valuation and a bill nobody could match to a
     * taxpayer. The TIN is different: the source does not always provide one, so
     * its absence must not block routing. It is recorded as an explicit, traceable
     * gap instead of being invented or silently dropped.
     */
    private function assertRoutable(PropertyDiscovery $discovery, User $user): void
    {
        $missing = [];

        if (blank($discovery->owner_name)) {
            $missing[] = 'Owner Name';
        }
        if (blank($discovery->property_classification)) {
            $missing[] = 'Property Classification';
        }

        abort_if(
            $missing !== [],
            422,
            'Complete the discovery before routing it: '.implode(' and ', $missing).' required.'
        );

        if (blank($discovery->tin)) {
            $this->audit->record($discovery, 'discovery.missing_information_flagged', $user->id, [], [
                'missing' => ['TIN'],
                'note' => 'No TIN was supplied at discovery; the source-system TIN must be captured before the bill is logged.',
            ]);
        }
    }

    private function validStatus(string $status): bool
    {
        return in_array($status, PropertyDiscovery::STATUSES, true);
    }

    private function guardStatus(PropertyDiscovery $d, array $allowed, string $message): void
    {
        abort_unless(in_array($d->status, $allowed, true), 422, $message);
    }

    /** View scope: own, team (supervised), section/wider, or via assignment / valuation. */
    private function canViewDiscovery(PropertyDiscovery $d, User $user): bool
    {
        // Single authority, shared with the valuation screen and the Property Record
        // export so the three can never drift apart on who may see a record.
        return app(PropertyRecordAccess::class)->canViewDiscovery($d, $user);
    }

    /** Owner-level mutations reserved for the recording officer, their supervisor, or management. */
    private function canMutateDiscovery(PropertyDiscovery $d, User $user): bool
    {
        if (in_array($user->scopeLevel(), ['section', 'division', 'system'], true)
            || $user->hasAnyPermission(['discovery.review', 'discovery.aarm_review', 'discovery.route_to_valuation'])) {
            return true;
        }

        if ($d->discovered_by === $user->id || $d->current_owner_id === $user->id) {
            return true;
        }

        if ($user->scopeLevel() === 'team' && $d->discovered_by) {
            return User::where('id', $d->discovered_by)->where('supervisor_id', $user->id)->exists();
        }

        return false;
    }

    /**
     * own-scope officers see their own discoveries / records assigned to them /
     * routed valuations. Valuation Officers browsing Path V only see records whose
     * valuation is assigned to them (a discovery they merely recorded but that was
     * routed to another officer / manager queue stays out of their Path V list).
     */
    private function restrictDataScope(Builder $query, User $user, ?string $path = null): void
    {
        $isValuationOfficer = $user->scopeLevel() === 'own'
            && $user->hasAnyPermission(['valuation.create', 'valuation.submit', 'valuation.edit']);

        if ($path === 'valuation' && $isValuationOfficer) {
            $query->whereHas('valuation', fn (Builder $v) => $v->where('valuation_officer_id', $user->id));

            return;
        }

        if ($user->scopeLevel() === 'own') {
            $query->where(function (Builder $b) use ($user) {
                $b->where('discovered_by', $user->id)
                    ->orWhere('current_owner_id', $user->id)
                    ->orWhereHas('valuation', fn ($v) => $v->where('valuation_officer_id', $user->id));
            });

            return;
        }

        if ($user->scopeLevel() === 'team') {
            $query->where(function (Builder $b) use ($user) {
                $b->where('discovered_by', $user->id)
                    ->orWhere('current_owner_id', $user->id)
                    ->orWhereIn('discovered_by', function ($q) use ($user) {
                        $q->select('id')->from('users')
                            ->where('supervisor_id', $user->id)
                            ->where('is_active', true);
                    });
            });
        }
        // section / division / system see everything in scope.
    }

    /**
     * Delegates to DiscoveryPresenter so the discovery projection is defined
     * once and shared with the property profile.
     */
    private function present(PropertyDiscovery $d): array
    {
        return app(DiscoveryPresenter::class)->present($d);
    }
}
