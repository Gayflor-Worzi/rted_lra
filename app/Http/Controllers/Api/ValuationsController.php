<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EvidencePhoto;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;
use App\Models\ValuationPropertyDescription;
use App\Services\AssignmentEligibilityService;
use App\Services\AuditService;
use App\Services\PropertyRecordAccess;
use App\Services\TaskService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Valuation Assessment — spec v4.
 *
 * Independent of Discovery (spec §8 / Path D) and supporting both
 * Discovery-origin (Path B/C) and standalone existing-property assessments.
 *
 * Status flow: Assigned → Assessment in Progress → Submitted to Manager →
 *   … → Submitted to AC → Awaiting AC Approval → AC Approved/Returned/Rejected →
 *   Sent to Account & Record → Registered → Completed.
 *
 * Produces an assessed value + recommended annual tax; it does NOT generate
 * bills, Document #s, or Property IDs (those belong to the source system).
 */
class ValuationsController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['valuation.create', 'valuation.review', 'valuation.approve', 'valuation.view_history']), 403, 'Missing permission.');

        $query = Valuation::query()
            ->with(['valuationOfficer:id,full_name', 'bill:id,document_number,property_id', 'descriptions', 'discovery:id,discovery_reference,status', 'propertyRef:id,source_reference']);

        if ($status = $request->query('status')) {
            // A stage resolves through the model's authoritative map, so a tab and the
            // statuses it covers cannot drift apart. The stage is checked FIRST because
            // 'Returned for Correction' is both a stage name and a status value: matching
            // the status first would silently drop the AC Returned and AC Rejected
            // assessments from the very tab they belong to.
            if ($statuses = Valuation::statusesForStage($status)) {
                $query->whereIn('status', $statuses);
            } elseif (in_array($status, Valuation::STATUSES, true)) {
                // Not a stage, but a real status: a deep link to one record still works.
                $query->where('status', $status);
            }
        }
        if ($q = $request->query('q')) {
            $query->where(function (Builder $b) use ($q) {
                $b->where('valuation_reference', 'like', like_term($q))
                    ->orWhere('document_number', 'like', like_term($q))
                    ->orWhere('property_id', 'like', like_term($q))
                    ->orWhere('owner_name', 'like', like_term($q))
                    // same permanent property identity as the discovery and
                    // bill registers, so one reference searches every section
                    ->orWhereHas('propertyRef', fn (Builder $p) => $p->where('source_reference', 'like', like_term($q)));
            });
        }

        $this->applyScope($query, $user);

        $rows = $query->orderByDesc('id')->paginate($request->query('per_page', 20))->withQueryString();
        $rows->getCollection()->transform(fn ($v) => $this->present($v));

        return response()->json(['data' => $rows]);
    }

    /**
     * Create a valuation assessment (Path D — standalone existing / newly
     * identified property). Discovery-origin valuations are created by the
     * Valuation Manager desk via DiscoveryController::valReview.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canPermission('valuation.create'), 403, 'Missing permission: valuation.create');

        $data = $request->validate([
            'bill_id' => 'nullable|integer|exists:property_bills,id',
            'discovery_id' => 'nullable|integer|exists:property_discoveries,id',
            'valuation_type' => ['required', Rule::in(['new_property', 'reassessment'])],
            'property_id' => ['nullable', 'string', 'max:60', 'required_if:valuation_type,reassessment'],
            'document_number' => 'nullable|string|max:80',
            'owner_name' => 'required|string|max:255',
            'owner_contact' => 'nullable|string|max:100',
            'tin' => 'nullable|string|max:40',
            'property_classification' => 'nullable|string|max:100',
            'property_address' => 'required|string|max:255',
            'land_dimensions' => 'nullable|string|max:100',
            'building_specs' => 'nullable|string|max:500',
            'construction_year' => 'nullable|string|max:10',
            'condition' => 'nullable|string|max:100',
            'assessment_date' => 'nullable|date',
            'declared_value' => 'nullable|numeric|min:0',
            'reassessed_value' => 'nullable|numeric|min:0',
            'applicable_tax_rate' => 'nullable|numeric|min:0',
            'annual_tax' => 'nullable|numeric|min:0',
            'other_amounts' => 'nullable|numeric|min:0',
            'gps_coordinate' => 'nullable|string|max:100',
            'gps_accuracy' => 'nullable|numeric|min:0',
            'photos' => 'nullable|string',
            'remarks' => 'nullable|string|max:2000',
            'descriptions' => 'nullable|array',
            'descriptions.*.description' => 'nullable|string|max:255',
            'descriptions.*.level' => 'nullable|string|max:50',
            'descriptions.*.area_sqft' => 'nullable|numeric|min:0',
            'descriptions.*.tar' => 'nullable|numeric|min:0',
            'descriptions.*.quantity' => 'nullable|integer|min:1',
            'descriptions.*.amount' => 'nullable|numeric|min:0',
            'descriptions.*.building_age' => 'nullable|integer|min:0',
            'descriptions.*.depreciation_pct' => 'nullable|numeric|min:0|max:100',
        ]);

        $bill = ($data['bill_id'] ?? null) ? PropertyBill::find($data['bill_id']) : null;

        if ($data['valuation_type'] === 'reassessment') {
            abort_unless($bill, 422, 'Reassessment must reference an existing logged bill.');
            if (! empty($data['property_id']) && $bill->property_id && $data['property_id'] !== $bill->property_id) {
                abort(422, 'Property ID does not match the referenced bill.');
            }
        }

        if ($discoveryId = ($data['discovery_id'] ?? null)) {
            $discovery = PropertyDiscovery::find($discoveryId);
            abort_if($discovery->valuation_id, 422, 'This discovery already has a linked valuation.');
            abort_unless(
                $discovery->decision_path === 'valuation'
                    && in_array($discovery->status, [PropertyDiscovery::STATUS_REFERRED_VALUATION, PropertyDiscovery::STATUS_AWAITING_VALUATION], true),
                422,
                'This discovery is not on the valuation path and may not be pulled into a standalone assessment.'
            );
            $discovery->update(['status' => PropertyDiscovery::STATUS_UNDER_VALUATION]);
        }

        $valuation = Valuation::create([
            'valuation_type' => $data['valuation_type'],
            'bill_id' => $bill?->id,
            'discovery_id' => $data['discovery_id'] ?? null,
            'property_id' => $data['property_id'] ?? $bill?->property_id,
            'document_number' => $data['document_number'] ?? $bill?->document_number,
            'owner_name' => $data['owner_name'],
            'owner_contact' => $data['owner_contact'] ?? null,
            'tin' => $data['tin'] ?? $bill?->tin,
            'property_classification' => $data['property_classification'] ?? $bill?->property_classification,
            'property_address' => $data['property_address'],
            'land_dimensions' => $data['land_dimensions'] ?? null,
            'building_specs' => $data['building_specs'] ?? null,
            'construction_year' => $data['construction_year'] ?? null,
            'condition' => $data['condition'] ?? null,
            'assessment_date' => $data['assessment_date'] ?? now()->toDateString(),
            'declared_value' => $data['declared_value'] ?? null,
            'reassessed_value' => $data['reassessed_value'] ?? null,
            'annual_tax' => $data['annual_tax'] ?? null,
            'applicable_tax_rate' => $data['applicable_tax_rate'] ?? null,
            'other_amounts' => $data['other_amounts'] ?? null,
            'gps_coordinate' => $data['gps_coordinate'] ?? null,
            'gps_accuracy' => $data['gps_accuracy'] ?? null,
            'photos' => $data['photos'] ?? null,
            'valuation_officer_id' => $user->id,
            'status' => Valuation::STATUS_ASSIGNED,
            'remarks' => $data['remarks'] ?? null,
        ], );

        $this->syncDescriptions($valuation, $data['descriptions'] ?? []);

        $this->rollupTotals($valuation);

        if ($valuation->discovery_id) {
            $discovery = PropertyDiscovery::find($valuation->discovery_id);
            $discovery?->update(['valuation_id' => $valuation->id, 'current_owner_id' => $user->id]);
        }

        $this->tasks->createValuationTask($valuation, $user, $user);

        $this->audit->record($valuation, 'valuation.created', $user->id, [], [
            'valuation_reference' => $valuation->valuation_reference,
            'valuation_type' => $valuation->valuation_type,
        ]);

        $this->tasks->notifySection('VAL', 'Valuation assigned', "Valuation {$valuation->valuation_reference} assigned to {$user->full_name}.", 'valuation');

        return response()->json([
            'data' => $this->present($valuation->fresh(['valuationOfficer:id,full_name', 'descriptions', 'evidencePhotos'])),
            'message' => 'Valuation assessment created.',
        ], 201);
    }

    public function show(Valuation $valuation)
    {
        abort_unless($this->canSee($valuation), 403, 'Valuation outside your data scope.');

        $valuation->load(['valuationOfficer:id,full_name', 'bill:id,document_number,property_id', 'reviews', 'descriptions', 'evidencePhotos', 'discovery:id,discovery_reference,status,declared_value,declared_assessed_value,value_declaration_type,official_assessed_value', 'propertyRef:id,source_reference']);

        return response()->json(['data' => $this->present($valuation)]);
    }

    public function update(Request $request, Valuation $valuation)
    {
        $user = $request->user();
        abort_unless($user->canPermission('valuation.edit'), 403, 'Missing permission: valuation.edit');
        abort_unless($this->canSee($valuation), 403, 'Valuation outside your data scope.');
        $this->assertAssignedOfficer($valuation, $user);
        abort_unless(in_array($valuation->status, [
            Valuation::STATUS_ASSIGNED,
            Valuation::STATUS_ASSESSMENT_IN_PROGRESS,
            Valuation::STATUS_RETURNED,
        ], true), 422, 'Valuation cannot be edited in its current status.');

        $data = $request->validate([
            'owner_name' => 'sometimes|string|max:255',
            'owner_contact' => 'nullable|string|max:100',
            'tin' => 'nullable|string|max:40',
            'property_classification' => 'nullable|string|max:100',
            'property_address' => 'sometimes|string|max:255',
            'land_dimensions' => 'nullable|string|max:100',
            'building_specs' => 'nullable|string|max:500',
            'construction_year' => 'nullable|string|max:10',
            'condition' => 'nullable|string|max:100',
            'assessment_date' => 'nullable|date',
            'declared_value' => 'nullable|numeric|min:0',
            'reassessed_value' => 'nullable|numeric|min:0',
            'applicable_tax_rate' => 'nullable|numeric|min:0',
            'assessed_value' => 'nullable|numeric|min:0',
            'annual_tax' => 'nullable|numeric|min:0',
            'other_amounts' => 'nullable|numeric|min:0',
            'gps_coordinate' => 'nullable|string|max:100',
            'gps_accuracy' => 'nullable|numeric|min:0',
            'photos' => 'nullable|string',
            'remarks' => 'nullable|string|max:2000',
            'descriptions' => 'nullable|array',
            'descriptions.*.description' => 'nullable|string|max:255',
            'descriptions.*.level' => 'nullable|string|max:50',
            'descriptions.*.area_sqft' => 'nullable|numeric|min:0',
            'descriptions.*.tar' => 'nullable|numeric|min:0',
            'descriptions.*.quantity' => 'nullable|integer|min:1',
            'descriptions.*.amount' => 'nullable|numeric|min:0',
            'descriptions.*.building_age' => 'nullable|integer|min:0',
            'descriptions.*.depreciation_pct' => 'nullable|numeric|min:0|max:100',
        ]);

        $valuation->update($data);

        if ($request->has('descriptions')) {
            $this->syncDescriptions($valuation, $request->input('descriptions') ?? []);
        }

        $this->audit->record($valuation, 'valuation.updated', $user->id, [], $data);

        return response()->json([
            'data' => $this->present($valuation->fresh(['descriptions', 'evidencePhotos'])),
            'message' => 'Valuation updated.',
        ]);
    }

    /** Bulk-replace the repeatable Property Description sub-table. */
    public function descriptions(Request $request, Valuation $valuation)
    {
        $user = $request->user();
        abort_unless($user->canPermission('valuation.edit'), 403, 'Missing permission: valuation.edit');
        abort_unless($this->canSee($valuation), 403, 'Valuation outside your data scope.');
        $this->assertAssignedOfficer($valuation, $user);
        abort_unless(in_array($valuation->status, [
            Valuation::STATUS_ASSIGNED,
            Valuation::STATUS_ASSESSMENT_IN_PROGRESS,
            Valuation::STATUS_RETURNED,
        ], true), 422, 'Valuation cannot be edited in its current status.');

        $data = $request->validate([
            'descriptions' => 'required|array|min:1',
            'descriptions.*.description' => 'nullable|string|max:255',
            'descriptions.*.level' => 'nullable|string|max:50',
            'descriptions.*.area_sqft' => 'nullable|numeric|min:0',
            'descriptions.*.tar' => 'nullable|numeric|min:0',
            'descriptions.*.quantity' => 'nullable|integer|min:1',
            'descriptions.*.amount' => 'nullable|numeric|min:0',
            'descriptions.*.building_age' => 'nullable|integer|min:0',
            'descriptions.*.depreciation_pct' => 'nullable|numeric|min:0|max:100',
        ]);

        $this->syncDescriptions($valuation, $data['descriptions']);

        $totals = $this->rollupTotals($valuation);

        if ($valuation->status === Valuation::STATUS_ASSIGNED) {
            $valuation->update(['status' => Valuation::STATUS_ASSESSMENT_IN_PROGRESS]);
        }

        $this->audit->record($valuation, 'valuation.descriptions', $user->id, [], ['rows' => count($data['descriptions'])]);

        return response()->json([
            'data' => [
                'descriptions' => $valuation->descriptions()->get(),
                'totals' => $totals,
            ],
            'message' => 'Property descriptions saved.',
        ]);
    }

    /** The officer formally starts work on an assigned assessment. */
    public function begin(Request $request, Valuation $valuation)
    {
        $user = $request->user();
        abort_unless($user->canPermission('valuation.submit'), 403, 'Missing permission: valuation.submit');
        abort_unless($this->canSee($valuation), 403, 'Valuation outside your data scope.');
        $this->assertAssignedOfficer($valuation, $user);
        abort_unless($valuation->status === Valuation::STATUS_ASSIGNED, 422, 'Only assigned assessments can be started.');

        $valuation->update(['status' => Valuation::STATUS_ASSESSMENT_IN_PROGRESS]);
        $this->audit->record($valuation, 'valuation.started', $user->id, [], ['status' => Valuation::STATUS_ASSESSMENT_IN_PROGRESS]);

        return response()->json(['data' => $this->present($valuation->fresh()), 'message' => 'Assessment in progress.']);
    }

    /**
     * Valuation Officer submits the completed assessment — this creates a task
     * for the Valuation Manager; the assessment is NOT complete just because it
     * was submitted (§10 / Rule 9).
     */
    public function submit(Request $request, Valuation $valuation)
    {
        $user = $request->user();
        abort_unless($user->canPermission('valuation.submit'), 403, 'Missing permission: valuation.submit');
        abort_unless($this->canSee($valuation), 403, 'Valuation outside your data scope.');
        $this->assertAssignedOfficer($valuation, $user);
        abort_unless(in_array($valuation->status, [
            Valuation::STATUS_ASSIGNED,
            Valuation::STATUS_ASSESSMENT_IN_PROGRESS,
            Valuation::STATUS_RETURNED,
        ], true), 422, 'Valuation cannot be submitted in its current status.');

        $data = $request->validate([
            'assessed_value' => 'nullable|numeric|min:0',
            'reassessed_value' => 'nullable|numeric|min:0',
            'annual_tax' => 'nullable|numeric|min:0',
            'applicable_tax_rate' => 'nullable|numeric|min:0',
            'other_amounts' => 'nullable|numeric|min:0',
            'remarks' => 'nullable|string|max:2000',
        ]);

        $this->assertSubmittable($valuation, $data);

        // The Valuation Assessment form records the officer's figure as
        // `reassessed_value`, and that is the figure a submission is based on.
        // `assessed_value` is the older name for the same money and is still accepted;
        // it takes precedence when a caller sends it explicitly, so the bulk submit on
        // the valuations list keeps behaving exactly as it did.
        $assessed = $data['assessed_value']
            ?? $data['reassessed_value']
            ?? $valuation->reassessed_value
            ?? $valuation->assessed_value;

        $rate = $data['applicable_tax_rate'] ?? $valuation->applicable_tax_rate;
        $annualTax = $data['annual_tax'] ?? null;

        // A figure is what makes a valuation assessable. An annual tax alone is enough
        // to submit on, so it stands in when no figure was ever recorded.
        if ($assessed === null && empty($annualTax)) {
            abort(422, 'Complete the assessment values on the valuation form before submitting.');
        }

        // Derived rather than demanded, exactly as rollupTotals() does it: the officer
        // enters a value and a rate, not a tax figure they then have to keep in step
        // with it. An explicitly supplied annual tax still wins.
        if (empty($annualTax) && $rate > 0 && $assessed > 0) {
            $annualTax = round($assessed * ($rate / 100), 2);
        }

        $totalPropertyValue = (float) $valuation->descriptions()->sum('value');
        $totalTaxPayable = $annualTax + (float) ($data['other_amounts'] ?? 0);

        $valuation->update([
            'assessed_value' => $assessed,
            'reassessed_value' => $data['reassessed_value'] ?? $valuation->reassessed_value ?? $assessed,
            'annual_tax' => $annualTax,
            'applicable_tax_rate' => $data['applicable_tax_rate'] ?? $valuation->applicable_tax_rate,
            'other_amounts' => $data['other_amounts'] ?? $valuation->other_amounts,
            'total_property_value' => $totalPropertyValue ?: $assessed,
            'total_tax_payable' => $totalTaxPayable,
            'submitted_at' => now(),
            'prepared_by_designation' => $this->designation($user),
            'status' => Valuation::STATUS_SUBMITTED_TO_MANAGER,
            'remarks' => $data['remarks'] ?? $valuation->remarks,
        ]);

        $valuation->reviews()->create([
            'stage' => 'supervisor',
            'decision' => 'forward',
            'reviewer_id' => $user->id,
            'remarks' => 'Submitted by officer.',
        ]);

        $this->syncDiscovery($valuation);

        // The officer's stage is finished, so their Valuation task retires before the
        // manager's is raised — the assessment moves down the workflow as one property
        // with one current task, instead of leaving the officer holding an open task
        // for work already done alongside the manager's new one.
        $this->tasks->closeStageTasks(
            Task::TYPE_VALUATION,
            'valuation',
            $valuation->id,
            'submitted_to_manager',
            $user,
            'Assessment submitted to the Valuation Manager for review.'
        );

        $this->tasks->createValuationReviewTask($valuation, $this->tasks->deskUser('Valuation Manager'), $user);

        $this->audit->record($valuation, 'valuation.submitted', $user->id, [], [
            'assessed_value' => $assessed,
            'annual_tax' => $annualTax,
        ]);

        $this->tasks->notifySection('VAL', 'Valuation submitted', "Valuation {$valuation->valuation_reference} awaiting manager review.", 'valuation');

        return response()->json([
            'data' => $this->present($valuation->fresh(['descriptions', 'evidencePhotos'])),
            'message' => 'Valuation submitted to the Valuation Manager.',
        ]);
    }

    /** Manager assigns (or reassigns) the valuation to an eligible officer. */
    public function assign(Request $request, Valuation $valuation)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['valuation.review', 'valuation.forward_ac', 'valuation.approve']), 403, 'Missing permission: valuation.review');
        abort_unless($this->canSee($valuation), 403, 'Valuation outside your data scope.');
        $this->assertWriteAuthority($valuation, $user);
        abort_unless(in_array($valuation->status, [
            Valuation::STATUS_ASSIGNED,
            Valuation::STATUS_RETURNED,
            Valuation::STATUS_AC_RETURNED,
        ], true), 422, 'Only assigned / returned assessments can be assigned.');

        $data = $request->validate([
            'officer_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $officer = User::find($data['officer_id']);

        abort_if(
            ! $officer || ! AssignmentEligibilityService::canExecuteTaskType($officer, Task::TYPE_VALUATION),
            422,
            'Assignment rejected. '.($officer?->full_name ?? 'User').' does not have permission to perform Valuation.'
        );

        $oldId = $valuation->valuation_officer_id;

        // An AC-returned assessment is not workable as it stands: submit() only
        // accepts Assigned / In Progress / Returned, so handing it to an officer
        // without reopening it would give them a task they cannot act on. The
        // assessment is reopened in place — same Valuation ID — so the officer
        // corrects and resubmits the record that was returned.
        $reopened = $valuation->status === Valuation::STATUS_AC_RETURNED;

        $valuation->update([
            'valuation_officer_id' => $officer->id,
            'status' => $reopened ? Valuation::STATUS_ASSIGNED : $valuation->status,
        ]);

        $this->tasks->createValuationTask($valuation, $officer, $user);
        $this->tasks->notify($officer, 'Valuation assigned', "Valuation {$valuation->valuation_reference} assigned to you.", 'valuation');

        $this->syncDiscovery($valuation);

        $this->audit->record(
            $valuation,
            'valuation.assigned',
            $user->id,
            ['valuation_officer_id' => $oldId] + ($reopened ? ['status' => Valuation::STATUS_AC_RETURNED] : []),
            ['valuation_officer_id' => $officer->id] + ($reopened ? ['status' => Valuation::STATUS_ASSIGNED] : []),
        );

        return response()->json([
            'data' => $this->present($valuation->fresh(['valuationOfficer:id,full_name'])),
            'message' => "Valuation assigned to {$officer->full_name}.",
        ]);
    }

    /**
     * Valuation Manager review of a submitted assessment (§11).
     * Decisions: confirm_ac (submit to Assistant Commissioner) or return (to the
     * valuation officer for correction / additional information).
     */
    public function review(Request $request, Valuation $valuation)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['valuation.review', 'valuation.approve']), 403, 'Missing permission: valuation.review');
        abort_unless($this->canSee($valuation), 403, 'Valuation outside your data scope.');

        $data = $request->validate([
            'decision' => ['required', Rule::in(['confirm_ac', 'return'])],
            'remarks' => 'nullable|string|max:2000',
            // Permitted manager corrections before forwarding
            'assessed_value' => 'nullable|numeric|min:0',
            'annual_tax' => 'nullable|numeric|min:0',
        ]);

        abort_if(
            $valuation->valuation_officer_id === $user->id,
            422,
            'Separation of duties: you may not review an assessment you conducted yourself.'
        );

        $this->assertWriteAuthority($valuation, $user);

        abort_unless(in_array($valuation->status, [Valuation::STATUS_SUBMITTED_TO_MANAGER, Valuation::STATUS_UNDER_MANAGER_REVIEW], true), 422, 'Valuation not awaiting manager review.');

        $corrections = array_filter([
            'assessed_value' => $data['assessed_value'] ?? null,
            'annual_tax' => $data['annual_tax'] ?? null,
        ], fn ($v) => $v !== null);
        if ($corrections) {
            $valuation->update($corrections);
        }

        if ($data['decision'] === 'confirm_ac') {
            $valuation->update([
                'status' => Valuation::STATUS_SUBMITTED_TO_AC,
                'manager_decision' => 'forward_ac',
                'manager_remarks' => $data['remarks'] ?? null,
                'manager_reviewed_by' => $user->id,
                'manager_reviewed_at' => now(),
            ]);
            $this->tasks->createAcApprovalTask($valuation, $this->tasks->deskUser('Assistant Commissioner'), $user);
            $this->tasks->notifySection('MGT', 'Valuation awaiting approval', "Valuation {$valuation->valuation_reference} awaits Assistant Commissioner approval.", 'valuation');
        } else {
            $valuation->update([
                'status' => Valuation::STATUS_RETURNED,
                'manager_decision' => 'return',
                'manager_remarks' => $data['remarks'] ?? null,
                'manager_reviewed_by' => $user->id,
                'manager_reviewed_at' => now(),
            ]);
            if ($valuation->valuation_officer_id) {
                $this->tasks->createValuationTask($valuation, User::find($valuation->valuation_officer_id), $user);
                $this->tasks->notify(User::find($valuation->valuation_officer_id), 'Valuation returned', "Valuation {$valuation->valuation_reference} returned for correction: ".($data['remarks'] ?? 'Review remarks omitted.'), 'valuation');
            }
        }

        // The Valuation Manager's review is complete whichever way it went, so their
        // task retires here. Left open it kept a finished review sitting in the manager's
        // Active list for the rest of the valuation's life.
        $this->tasks->closeStageTasks(
            Task::TYPE_VALUATION_REVIEW,
            'valuation',
            $valuation->id,
            $data['decision'] === 'confirm_ac' ? 'forwarded_to_ac' : 'returned_to_officer',
            $user,
            $data['remarks'] ?? null,
        );

        $valuation->reviews()->create([
            'stage' => 'manager',
            'decision' => $data['decision'],
            'reviewer_id' => $user->id,
            'remarks' => $data['remarks'] ?? null,
        ]);

        $this->syncDiscovery($valuation);

        $this->audit->record($valuation, 'valuation.reviewed', $user->id, [], $data);

        return response()->json([
            'data' => $this->present($valuation->fresh()),
            'message' => $data['decision'] === 'confirm_ac' ? 'Valuation submitted to the Assistant Commissioner.' : 'Valuation returned for correction.',
        ]);
    }

    /** Assistant Commissioner approves, returns, or rejects the valuation (§12/Rule 5). */
    public function decide(Request $request, Valuation $valuation)
    {
        $user = $request->user();
        abort_unless($user->canPermission('valuation.approve'), 403, 'Missing permission: valuation.approve');
        abort_unless($this->canSee($valuation), 403, 'Valuation outside your data scope.');
        abort_if($valuation->valuation_officer_id === $user->id, 422, 'Separation of duties: you may not approve/reject a valuation you prepared.');

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject', 'return'])],
            'remarks' => 'nullable|string|max:2000',
        ]);

        abort_unless(in_array($valuation->status, [Valuation::STATUS_SUBMITTED_TO_AC, Valuation::STATUS_AWAITING_AC_APPROVAL], true), 422, 'Valuation not awaiting AC approval.');

        $status = match ($data['decision']) {
            'approve' => Valuation::STATUS_AC_APPROVED,
            'reject' => Valuation::STATUS_AC_REJECTED,
            'return' => Valuation::STATUS_AC_RETURNED,
        };

        $valuation->update([
            'status' => $status,
            'ac_decision' => $data['decision'],
            'ac_remarks' => $data['remarks'] ?? null,
            'ac_reviewed_by' => $user->id,
            'ac_reviewed_at' => now(),
        ]);

        $valuation->reviews()->create([
            'stage' => 'ac',
            'decision' => $data['decision'],
            'reviewer_id' => $user->id,
            'remarks' => $data['remarks'] ?? null,
        ]);

        $this->syncDiscovery($valuation);

        // The Assistant Commissioner's decision is recorded above, so their approval task
        // retires. The stage is finished at each of the three outcomes the endpoint can
        // produce, so approving a property no longer leaves the AC holding open work for
        // a decision they have already made.
        $this->tasks->closeStageTasks(
            Task::TYPE_AC_APPROVAL,
            'valuation',
            $valuation->id,
            'ac_'.$data['decision'],
            $user,
            $data['remarks'] ?? null,
        );

        if ($data['decision'] === 'approve') {
            $this->tasks->createAccountManagerReviewTask('valuation', $valuation->id, "Valuation {$valuation->valuation_reference}", $this->tasks->deskUser('Account Manager'), $user);
            $this->tasks->notifySection('ACCT', 'Approved valuation', "Valuation {$valuation->valuation_reference} approved — awaiting Account & Record final review.", 'valuation');

            if ($valuation->bill_id) {
                $bill = PropertyBill::find($valuation->bill_id);
                if ($bill) {
                    // The approved reassessment is the authority on this property's
                    // value AND its annual tax. Copying only the value left the
                    // register claiming the new assessment while still carrying the
                    // old tax due. Derived totals and the balance follow from the
                    // bill's own columns (the model recomputes them on save).
                    $before = $bill->only([
                        'taxpayer_name', 'tin', 'property_address', 'assessed_value',
                        'property_classification', 'tax_amount', 'total_tax_due',
                        'outstanding_balance',
                    ]);

                    $attributes = [
                        'assessed_value' => $valuation->assessed_value,
                        'property_classification' => $valuation->property_classification ?? $bill->property_classification,
                    ];

                    // Owner and address corrected during valuation are equally part
                    // of the approved assessment; a bill that kept the stale versions
                    // sent Enforcement after the wrong taxpayer at the wrong address.
                    if (filled($valuation->owner_name)) {
                        $attributes['taxpayer_name'] = $valuation->owner_name;
                    }
                    if (filled($valuation->property_address)) {
                        $attributes['property_address'] = $valuation->property_address;
                    }
                    if (filled($valuation->tin)) {
                        $attributes['tin'] = $valuation->tin;
                    }

                    if ($valuation->annual_tax !== null) {
                        $attributes['tax_amount'] = $valuation->annual_tax;
                        $attributes['total_tax_due'] = round(
                            (float) $valuation->annual_tax
                            + (float) $bill->interest_charged
                            + (float) $bill->penalty_charged,
                            2
                        );
                    }

                    $bill->update($attributes);

                    // A bill is a financial record: every change to it is audited,
                    // including this propagation from the approved assessment.
                    $this->audit->record($bill, 'bill.assessment_updated_from_valuation', $user->id, $before, $attributes + [
                        'valuation_id' => $valuation->id,
                        'valuation_reference' => $valuation->valuation_reference,
                    ]);
                }
            }
        } else {
            $this->tasks->notifySection('VAL', $data['decision'] === 'reject' ? 'Valuation rejected' : 'Valuation returned', "Valuation {$valuation->valuation_reference} {$data['decision']}d by the Assistant Commissioner.", 'valuation');

            // No Valuation Review task here on purpose. syncDiscovery() has just put
            // the underlying discovery back into the recording officer's correction
            // queue, and review() only accepts the two manager-review statuses, so a
            // manager review task on an AC-returned valuation could never be
            // completed by anyone. The recorder's correction task below carries the
            // work, and resubmit() puts the record back in front of the Valuation
            // Manager.
            //
            // The section broadcast above only reaches the Valuation desk, so the
            // officer who recorded this has to be told directly - otherwise they are
            // left holding a silently returned, read-only record.
            $discovery = $valuation->discovery_id ? PropertyDiscovery::find($valuation->discovery_id) : null;

            if ($discovery && $discovery->discovered_by !== $user->id) {
                $rejected = $data['decision'] === 'reject';
                $this->tasks->notify(
                    $discovery->relationLoaded('discoverer') ? $discovery->discoverer : User::find($discovery->discovered_by),
                    $rejected ? 'Your discovery was rejected' : 'Your discovery was returned for correction',
                    $rejected
                        ? "Discovery {$discovery->discovery_reference} was rejected by the Assistant Commissioner: ".($data['remarks'] ?? 'No reason recorded.')
                        : "Discovery {$discovery->discovery_reference} was returned by the Assistant Commissioner: ".($data['remarks'] ?? 'Additional information required.').' Open the property view to correct the record and resubmit.',
                    'discovery'
                );
            }

            // A return is a correction request, so it raises real work for the
            // recorder. A rejection is terminal by policy and deliberately raises
            // no correction task — rejection is not a disguised return.
            if ($data['decision'] === 'return' && $discovery) {
                $this->tasks->createDiscoveryCorrectionTask(
                    $discovery,
                    $discovery->discoverer,
                    $user,
                    'Returned by the Assistant Commissioner: '.($data['remarks'] ?? 'Additional information required.'),
                );
            }
        }

        $this->audit->record($valuation, 'valuation.decided', $user->id, [], $data);

        return response()->json([
            'data' => $this->present($valuation->fresh()),
            'message' => 'Valuation '.$data['decision'].'d.',
        ]);
    }

    /**
     * Account & Record Manager final review after AC approval (§13).
     * Decisions: approve (assign an Account & Record Officer) or return (refer
     * the assessment back to the Valuation Section).
     *
     * Approval hands the record to the officer for PROCESSING — not review. When
     * the valuation originates from a discovery, the processing task is raised
     * on the discovery itself so the officer lands on the single processing
     * step that records the source identifiers and updates the bill record.
     */
    public function accountReview(Request $request, Valuation $valuation)
    {
        $user = $request->user();
        abort_unless($user->canPermission('valuation.account_review'), 403, 'Missing permission: valuation.account_review');
        abort_unless($this->canSee($valuation), 403, 'Valuation outside your data scope.');

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'return'])],
            'officer_id' => 'nullable|integer|exists:users,id',
            'remarks' => 'nullable|string|max:2000',
        ]);

        abort_unless($valuation->status === Valuation::STATUS_AC_APPROVED, 422, 'Valuation not awaiting Account & Record final review.');

        if ($data['decision'] === 'approve') {
            abort_unless($data['officer_id'], 422, 'Assign an eligible Account & Record Officer to process the property registration.');
            $officer = User::find($data['officer_id']);
            abort_unless(
                $officer && AssignmentEligibilityService::canExecuteTaskType($officer, Task::TYPE_ACCOUNT_PROCESSING),
                422,
                'Assignment rejected. '.($officer?->full_name ?? 'User').' does not have permission to process property registration.'
            );

            $valuation->update([
                'status' => Valuation::STATUS_SENT_TO_ACCT,
                'account_reviewed_by' => $user->id,
                'account_reviewed_at' => now(),
                'account_remarks' => $data['remarks'] ?? null,
            ]);

            // Mirror onto the linked discovery first so the record shows the
            // officer who now owns the processing task.
            $this->syncDiscovery($valuation, ['current_owner_id' => $officer->id]);

            $discovery = $valuation->discovery_id ? PropertyDiscovery::find($valuation->discovery_id) : null;

            if ($discovery) {
                $this->tasks->createAccountProcessingTask(
                    'property_discovery',
                    $discovery->id,
                    "Discovery {$discovery->discovery_reference}",
                    $officer,
                    $user,
                );
                $target = "Discovery {$discovery->discovery_reference}";
            } else {
                $this->tasks->createAccountProcessingTask('valuation', $valuation->id, "Valuation {$valuation->valuation_reference}", $officer, $user);
                $target = "Valuation {$valuation->valuation_reference}";
            }

            $this->tasks->notify($officer, 'Approved valuation assigned', "{$target} assigned to you — process the property registration in the source system, then update the property bill record in RETD.", 'valuation');

            // The A&R Manager has now decided, so their own review task retires. Left
            // open it competed with the officer's processing task raised above, and a
            // property that had been processed through still counted as Active work.
            $this->tasks->closeStageTasks(
                Task::TYPE_ACCOUNT_MANAGER_REVIEW,
                'valuation',
                $valuation->id,
                'approved_for_processing',
                $user,
                "Valuation {$valuation->valuation_reference} approved and assigned to {$officer->full_name} for processing.",
            );

            $this->audit->record($valuation, 'valuation.account_approved', $user->id, [], ['officer_id' => $officer->id, 'discovery_id' => $valuation->discovery_id]);

            return response()->json([
                'data' => $this->present($valuation->fresh()),
                'message' => "Valuation approved for processing and assigned to {$officer->full_name}.",
            ]);
        }

        $valuation->update([
            'status' => Valuation::STATUS_RETURNED,
            'account_reviewed_by' => $user->id,
            'account_reviewed_at' => now(),
            'account_remarks' => $data['remarks'] ?? null,
        ]);

        // The assessment goes back to the Valuation Officer exactly as a manager
        // return does. STATUS_AC_RETURNED was wrong here: that status is the
        // Assistant Commissioner's own decision, it is rejected by the manager review
        // endpoint, and it pushed the discovery into a correction lane its recorder
        // cannot use. The officer can correct and resubmit from STATUS_RETURNED.
        if ($valuation->valuation_officer_id) {
            $officer = User::find($valuation->valuation_officer_id);
            $this->tasks->createValuationTask($valuation, $officer, $user);
            $this->tasks->notify($officer, 'Valuation returned', "Valuation {$valuation->valuation_reference} returned by the Account & Record Manager: ".($data['remarks'] ?? 'Review remarks omitted.'), 'valuation');
        } else {
            $this->tasks->createValuationReviewTask($valuation, $this->tasks->deskUser('Valuation Manager'), $user);
            $this->tasks->notifySection('VAL', 'Valuation referred back', "Valuation {$valuation->valuation_reference} referred back by the Account & Record Manager: ".($data['remarks'] ?? 'Adjustments required.'), 'valuation');
        }

        $this->syncDiscovery($valuation);

        // Referred back, so the A&R review is over and its task retires. The officer
        // task raised just above is now the only open work on this record.
        $this->tasks->closeStageTasks(
            Task::TYPE_ACCOUNT_MANAGER_REVIEW,
            'valuation',
            $valuation->id,
            'returned_to_valuation_section',
            $user,
            $data['remarks'] ?? null,
        );

        $this->audit->record($valuation, 'valuation.account_returned', $user->id, [], $data);

        return response()->json([
            'data' => $this->present($valuation->fresh()),
            'message' => 'Valuation referred back to the Valuation Section.',
        ]);
    }

    /**
     * Standalone valuation — the assigned Account & Record Officer records the
     * completed property processing (§14).
     *
     * A valuation that originates from a discovery is processed through the
     * discovery's own processing step instead, so the source identifiers and
     * the property bill record are written exactly once, from one place.
     */
    public function processing(Request $request, Valuation $valuation)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['valuation.property_registration']), 403, 'Missing permission: valuation.property_registration');

        abort_if($valuation->discovery_id, 422, 'This valuation comes from a property discovery — process it on the discovery so the property bill record is updated there.');

        abort_unless($valuation->status === Valuation::STATUS_SENT_TO_ACCT, 422, 'Only approved valuations awaiting processing can be updated.');
        abort_if($valuation->registration_status === Valuation::REGISTRATION_COMPLETED, 422, 'Already marked registered.');

        $assignedTask = $valuation->tasks()
            ->where('task_type', Task::TYPE_ACCOUNT_PROCESSING)
            ->whereNotIn('status', Task::COMPLETED_STATUSES)
            ->where('assigned_to', $user->id)
            ->exists();
        abort_unless($assignedTask || $user->hasAnyPermission(['valuation.account_review']), 403, 'This valuation is not assigned to you for processing.');

        $data = $request->validate([
            'property_id' => 'nullable|string|max:60',
            'document_number' => 'nullable|string|max:80',
            'remarks' => 'nullable|string|max:2000',
        ]);

        $valuation->update([
            'property_id' => $data['property_id'] ?? $valuation->property_id,
            'document_number' => $data['document_number'] ?? $valuation->document_number,
            'status' => Valuation::STATUS_REGISTERED,
            'registration_status' => Valuation::REGISTRATION_COMPLETED,
            'registration_completed_by' => $user->id,
            'registration_completed_at' => now(),
            'remarks' => $data['remarks'] ?? $valuation->remarks,
        ]);

        // Recording the registration finishes the processing task, exactly as the
        // discovery-side processing step does. Logging the engagement alone left a
        // completed registration sitting in the officer's Active list for the rest
        // of the record's life.
        $details = 'Processing recorded. Property ID: '.($data['property_id'] ?? $valuation->property_id ?? '—').', Document #: '.($data['document_number'] ?? '—');

        $valuation->tasks()
            ->where('task_type', Task::TYPE_ACCOUNT_PROCESSING)
            ->whereNotIn('status', Task::COMPLETED_STATUSES)
            ->get()
            ->each(function (Task $task) use ($details, $user) {
                $task->forceTransition('Closed', 'registration_completed', $user, $details);
                $task->recordEngagement('completed', 'registration_completed', $details, $user);
            });

        $this->audit->record($valuation, 'valuation.registration_recorded', $user->id, [], $data);

        return response()->json([
            'data' => $this->present($valuation->fresh()),
            'message' => 'Valuation result confirmed as registered.',
        ]);
    }

    public function complete(Request $request, Valuation $valuation)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['valuation.complete', 'valuation.account_review']), 403, 'Missing permission.');
        $this->guardStatus($valuation, [Valuation::STATUS_REGISTERED], 'Only registered valuations can be completed.');

        $valuation->update(['status' => Valuation::STATUS_COMPLETED]);
        $this->audit->record($valuation, 'valuation.completed', $user->id, [], ['status' => Valuation::STATUS_COMPLETED]);

        if ($valuation->discovery_id) {
            PropertyDiscovery::where('id', $valuation->discovery_id)
                ->where('status', PropertyDiscovery::STATUS_REGISTERED)
                ->update(['status' => PropertyDiscovery::STATUS_COMPLETED, 'completed_at' => now()]);
        }

        return response()->json(['data' => $this->present($valuation->fresh()), 'message' => 'Valuation completed.']);
    }

    /* ---------- helpers ---------- */

    private function guardStatus(Valuation $valuation, array $allowed, string $message): void
    {
        abort_unless(in_array($valuation->status, $allowed, true), 422, $message);
    }

    /**
     * One owner per step.
     *
     * The officer steps (begin / edit / submit) belong to the officer the valuation
     * is assigned to. Without this a colleague who happens to hold valuation.edit or
     * valuation.submit — the Valuation Supervisor role holds both, alongside
     * valuation.review — can conduct an assessment that was assigned to somebody
     * else, so the Valuation Officer and the Valuation Manager end up running two
     * different processes over the same record.
     *
     * A valuation with no officer yet is unowned: the manager routes it (assign, or
     * "conduct personally") and until then any officer may pick it up, which is what
     * the New Valuation path relies on.
     */
    private function assertAssignedOfficer(Valuation $valuation, User $user): void
    {
        if ($valuation->valuation_officer_id && $valuation->valuation_officer_id !== $user->id) {
            abort(403, 'This assessment is assigned to another Valuation Officer.');
        }
    }

    /**
     * View-only supervisory scope.
     *
     * A supervisor sees every assessment belonging to the staff they supervise so they
     * can follow the work, but seeing it is not owning it: routing and reviewing a
     * colleague's record are writes, and a view-only supervisor has no authority to
     * make them. Without this, the SCOPE_TEAM_VIEW grant of visibility would quietly
     * become SCOPE_TEAM the moment the supervisor held valuation.review — which the
     * role does.
     */
    private function assertWriteAuthority(Valuation $valuation, User $user): void
    {
        if ($user->scopeLevel() === User::SCOPE_TEAM_VIEW && $valuation->valuation_officer_id !== $user->id) {
            abort(403, 'You have view-only access to your staff member\'s assessment.');
        }
    }

    /**
     * Mirror the valuation lifecycle onto the linked discovery (Path B/C).
     * $extra carries attributes owned by the calling step — e.g. the Account &
     * Record Officer who now owns the processing task.
     */
    private function syncDiscovery(Valuation $valuation, array $extra = []): void
    {
        if (! $valuation->discovery_id) {
            return;
        }

        $discovery = PropertyDiscovery::find($valuation->discovery_id);
        if (! $discovery) {
            return;
        }

        $map = [
            Valuation::STATUS_ASSIGNED => PropertyDiscovery::STATUS_UNDER_VALUATION,
            Valuation::STATUS_ASSESSMENT_IN_PROGRESS => PropertyDiscovery::STATUS_UNDER_VALUATION,
            Valuation::STATUS_SUBMITTED_TO_MANAGER => PropertyDiscovery::STATUS_UNDER_VALUATION,
            Valuation::STATUS_UNDER_MANAGER_REVIEW => PropertyDiscovery::STATUS_UNDER_VALUATION,
            // A returned assessment is the Valuation Section correcting its own
            // valuation officer, so the discovery stays under valuation. Only a
            // decision taken *against the property record* (AC return / reject) moves
            // the discovery into the recording officer's correction lane. Mapping this
            // one to the discovery's correction status handed the officer a record to
            // correct that the Valuation Section actually owned.
            Valuation::STATUS_RETURNED => PropertyDiscovery::STATUS_UNDER_VALUATION,
            Valuation::STATUS_SUBMITTED_TO_AC => PropertyDiscovery::STATUS_AWAITING_AC_APPROVAL,
            Valuation::STATUS_AWAITING_AC_APPROVAL => PropertyDiscovery::STATUS_AWAITING_AC_APPROVAL,
            Valuation::STATUS_AC_APPROVED => PropertyDiscovery::STATUS_APPROVED,
            Valuation::STATUS_AC_RETURNED => PropertyDiscovery::STATUS_RETURNED,
            Valuation::STATUS_AC_REJECTED => PropertyDiscovery::STATUS_REJECTED,
            Valuation::STATUS_SENT_TO_ACCT => PropertyDiscovery::STATUS_ASSIGNED_ACCT,
            Valuation::STATUS_REGISTERED => PropertyDiscovery::STATUS_REGISTERED,
            Valuation::STATUS_COMPLETED => PropertyDiscovery::STATUS_COMPLETED,
        ];

        if (isset($map[$valuation->status])) {
            $approved = $valuation->status === Valuation::STATUS_AC_APPROVED;

            $valueDelta = [
                'official_assessed_value' => $approved
                    ? $valuation->assessed_value
                    : $discovery->official_assessed_value,
            ];

            // The approved assessment is authoritative for the property details
            // too. A valuation-side correction to the owner, classification, TIN or
            // address is part of what the Assistant Commissioner approved, so the
            // discovery must not keep presenting the pre-assessment version.
            if ($approved) {
                foreach (['owner_name', 'owner_contact', 'tin', 'property_classification', 'property_address'] as $field) {
                    if (filled($valuation->{$field})) {
                        $valueDelta[$field] = $valuation->{$field};
                    }
                }
            }

            $discovery->update([
                'status' => $map[$valuation->status],
                'ac_decision' => $valuation->status === Valuation::STATUS_AC_REJECTED ? 'rejected'
                    : ($valuation->status === Valuation::STATUS_AC_APPROVED ? 'approved' : $discovery->ac_decision),
                'ac_decided_by' => in_array($valuation->status, [Valuation::STATUS_AC_APPROVED, Valuation::STATUS_AC_REJECTED], true)
                    ? ($discovery->ac_decided_by ?? $valuation->ac_reviewed_by) : $discovery->ac_decided_by,
                'ac_decided_at' => in_array($valuation->status, [Valuation::STATUS_AC_APPROVED, Valuation::STATUS_AC_REJECTED], true)
                    ? ($discovery->ac_decided_at ?? $valuation->ac_reviewed_at) : $discovery->ac_decided_at,
                'ac_remarks' => in_array($valuation->status, [Valuation::STATUS_AC_APPROVED, Valuation::STATUS_AC_REJECTED], true)
                    && $valuation->ac_remarks ? $valuation->ac_remarks : $discovery->ac_remarks,
            ] + $valueDelta + $extra);

            if ($approved) {
                $this->audit->record($discovery, 'discovery.official_assessed_value_set', $valuation->ac_reviewed_by, [
                    'official_assessed_value' => $discovery->official_assessed_value,
                ], [
                    'official_assessed_value' => $valuation->assessed_value,
                    'source_valuation_id' => $valuation->id,
                    'declared_assessed_value_preserved' => $discovery->declared_assessed_value ?? $discovery->declared_value,
                    // Details corrected during valuation and carried onto the record.
                    'owner_name' => $valueDelta['owner_name'] ?? null,
                    'owner_contact' => $valueDelta['owner_contact'] ?? null,
                    'tin' => $valueDelta['tin'] ?? null,
                    'property_classification' => $valueDelta['property_classification'] ?? null,
                    'property_address' => $valueDelta['property_address'] ?? null,
                ]);
            }
        }
    }

    private function assertSubmittable(Valuation $valuation, array $data): void
    {
        $missing = [];

        if (! $valuation->owner_contact) {
            $missing[] = 'Owner Contact';
        }
        if (! $valuation->tin) {
            $missing[] = 'TIN';
        }
        if (! $valuation->property_classification) {
            $missing[] = 'Property Classification';
        }
        if (! $valuation->gps_coordinate) {
            $missing[] = 'GPS coordinates';
        }
        if (! $valuation->assessment_date) {
            $missing[] = 'Assessment Date';
        }
        if ($valuation->valuation_type === 'reassessment' && ! $valuation->property_id) {
            $missing[] = 'Property ID';
        }
        if (! $valuation->descriptions()->exists()) {
            $missing[] = 'at least one Property Description row';
        } else {
            // Land is the first, mandatory row: without its market value per lot
            // and number of lots there is no assessment to submit.
            $rows = $valuation->descriptions()->orderBy('seq')->get();
            $land = $rows->first(fn ($row) => ValuationPropertyDescription::isLand($row->level));

            if (! $land) {
                $missing[] = 'the mandatory Land row (first row of Property Description)';
            } elseif ($land->tar === null || (float) $land->tar <= 0) {
                $missing[] = 'the Land market value per lot';
            } elseif ((int) $land->quantity < 1) {
                $missing[] = 'the Land number of lots';
            }

            // A building row with an area or rate missing computes no amount, and
            // an unassessed row must not pass as a zero-dollar one.
            $unpriced = $rows
                ->reject(fn ($row) => ValuationPropertyDescription::isLand($row->level))
                ->contains(fn ($row) => $row->amount === null || $row->value === null);

            if ($unpriced) {
                $missing[] = 'Area (sq ft) and TAR rate on every building row';
            }
        }
        // A property is photographed on the discovery visit, not on the assessment: the
        // valuation officer carries no camera, so the application writes no property
        // photograph against the valuation itself. Reading only the valuation's own photo
        // rows therefore reported "a Property Photo" missing on a property with three
        // photographs on file - while the printed record sheet, which does read the
        // source discovery, showed all three. The discovery's photographs are read
        // through the same resolution the record sheet uses, so the two agree. A
        // valuation with no photograph anywhere still cannot be submitted.
        if (! $valuation->photos
            && $valuation->evidencePhotos()->count() === 0
            && $valuation->discoveryPropertyPhotographs()->isEmpty()) {
            $missing[] = 'a Property Photo';
        }

        if ($missing) {
            abort(422, 'Valuation cannot be submitted — missing required fields: '.implode(', ', $missing).'.');
        }
    }

    /**
     * Bulk-replace the Property Description rows.
     *
     * Amount, depreciation and value are derived here from the officer's inputs
     * (area, TAR rate, quantity, building age) rather than read from the payload,
     * so the rules cannot be bypassed by editing the request and a reopened form
     * shows exactly what the server stored. Land is kept first and is never
     * depreciated.
     */
    private function syncDescriptions(Valuation $valuation, array $rows): void
    {
        $valuation->descriptions()->delete();

        $rows = array_values($rows);

        // Land is the first row of every property description. A payload that
        // arrives out of order is stored in the order the form enforces.
        $ordered = array_merge(
            array_values(array_filter($rows, fn ($row) => ValuationPropertyDescription::isLand($row['level'] ?? null))),
            array_values(array_filter($rows, fn ($row) => ! ValuationPropertyDescription::isLand($row['level'] ?? null))),
        );

        $seq = 1;
        foreach ($ordered as $row) {
            $level = $row['level'] ?? null;
            $quantity = max(1, (int) ($row['quantity'] ?? 1));
            $tar = $this->numberOrNull($row['tar'] ?? null);
            $area = $this->numberOrNull($row['area_sqft'] ?? null);
            $age = $this->numberOrNull($row['building_age'] ?? null);

            $amount = ValuationPropertyDescription::computeAmount($level, $area, $tar, $quantity);
            $depreciation = ValuationPropertyDescription::computeDepreciationPct(
                $level,
                $age === null ? null : max(0, (int) $age),
            );

            $valuation->descriptions()->create([
                'seq' => $seq++,
                'description' => $row['description'] ?? '',
                'level' => $level,
                'area_sqft' => $area,
                'tar' => $tar,
                'quantity' => $quantity,
                'amount' => $amount,
                'building_age' => $age === null ? null : max(0, (int) $age),
                'depreciation_pct' => $depreciation,
                'value' => ValuationPropertyDescription::computeValue($amount, $depreciation),
            ]);
        }
    }

    /** A missing or non-numeric input stays null; it is never coerced to zero. */
    private function numberOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function rollupTotals(Valuation $valuation): array
    {
        $totalPropertyValue = (float) $valuation->descriptions()->sum('value');
        $rate = (float) $valuation->applicable_tax_rate;
        $reassessed = (float) ($valuation->reassessed_value ?? $valuation->assessed_value ?? 0);
        $annualTax = $rate > 0 && $reassessed > 0 ? round($reassessed * ($rate / 100), 2) : (float) ($valuation->annual_tax ?? 0);

        $totals = [
            'total_property_value' => $totalPropertyValue,
            'applicable_tax_rate' => $rate,
            'annual_tax' => $annualTax,
            'other_amounts' => (float) ($valuation->other_amounts ?? 0),
            'total_tax_payable' => $annualTax + (float) ($valuation->other_amounts ?? 0),
        ];

        if (($valuation->assessment_date || $valuation->annual_tax) && ($totals['total_property_value'] || $totals['total_tax_payable'])) {
            $valuation->update($totals);
        }

        return $totals;
    }

    private function designation(User $user): string
    {
        return $user->role?->name ?? 'Valuation Staff';
    }

    private function canSee(Valuation $valuation): bool
    {
        $user = request()->user();
        if ($user->isSystemAdministrator()) {
            return true;
        }
        $scope = $user->scopeLevel();
        if (in_array($scope, ['system', 'division'], true)) {
            return true;
        }

        // Single authority, shared with the discovery screen and the Property Record
        // export so the three can never drift apart on who may see a record.
        return app(PropertyRecordAccess::class)->canViewValuation($valuation, $user);
    }

    private function applyScope($query, $user): void
    {
        $scope = $user->scopeLevel();
        if (in_array($scope, ['system', 'division'], true)) {
            return;
        }
        if ($scope === 'section') {
            $users = User::where('section_id', $user->section_id)->where('is_active', true)->pluck('id')->all();

            if ($user->section?->code === 'ACCT') {
                $query->where(function (Builder $b) use ($users) {
                    $b->whereIn('valuation_officer_id', $users)
                        ->orWhereIn('status', [
                            Valuation::STATUS_AC_APPROVED,
                            Valuation::STATUS_SENT_TO_ACCT,
                            Valuation::STATUS_REGISTERED,
                            Valuation::STATUS_COMPLETED,
                        ]);
                });
            } else {
                $query->whereIn('valuation_officer_id', $users);
            }
        } elseif (in_array($scope, [User::SCOPE_TEAM, User::SCOPE_TEAM_VIEW], true)) {
            $query->whereIn('valuation_officer_id', array_merge([$user->id], User::where('supervisor_id', $user->id)->pluck('id')->all()));
        } else { // own
            $query->where('valuation_officer_id', $user->id);
        }
    }

    private function present(Valuation $v): array
    {
        return [
            'id' => $v->id,
            'valuation_reference' => $v->valuation_reference,
            'source_reference' => $v->propertyRef?->source_reference,
            'valuation_type' => $v->valuation_type,
            'bill_id' => $v->bill_id,
            'discovery_id' => $v->discovery_id,
            'document_number' => $v->document_number,
            'property_id' => $v->property_id,
            'owner_name' => $v->owner_name,
            'owner_contact' => $v->owner_contact,
            'tin' => $v->tin,
            'property_classification' => $v->property_classification,
            'property_address' => $v->property_address,
            'land_dimensions' => $v->land_dimensions,
            'building_specs' => $v->building_specs,
            'construction_year' => $v->construction_year,
            'condition' => $v->condition,
            'assessment_date' => $v->assessment_date?->toDateString(),
            'declared_value' => $v->declared_value,
            'reassessed_value' => $v->reassessed_value,
            'assessed_value' => $v->assessed_value,
            'discovery_declared' => $v->relationLoaded('discovery') && $v->discovery
                ? ($v->discovery->declared_assessed_value ?? $v->discovery->declared_value) : null,
            'discovery_declaration_type' => $v->relationLoaded('discovery') && $v->discovery
                ? $v->discovery->value_declaration_type : null,
            'discovery_official_value' => $v->relationLoaded('discovery') && $v->discovery
                ? $v->discovery->official_assessed_value : null,
            'annual_tax' => $v->annual_tax,
            'applicable_tax_rate' => $v->applicable_tax_rate,
            'other_amounts' => $v->other_amounts,
            'total_property_value' => $v->total_property_value,
            'total_tax_payable' => $v->total_tax_payable,
            'submitted_at' => $v->submitted_at?->toISOString(),
            'prepared_by_designation' => $v->prepared_by_designation,
            'gps_coordinate' => $v->gps_coordinate,
            'gps_accuracy' => $v->gps_accuracy,
            'gps_verified' => $v->gps_verified,
            'gps_certified_at' => $v->gps_certified_at?->toISOString(),
            'gps_cert_remarks' => $v->gps_cert_remarks,
            'photos' => $v->photos,
            'evidence_photos' => $v->relationLoaded('evidencePhotos') ? $v->evidencePhotos?->map(fn (EvidencePhoto $p) => [
                'photo_reference' => $p->photo_reference,
                'photo_type' => $p->photo_type,
                'file_path' => $p->file_path,
                'gps_coordinate' => $p->gps_coordinate,
                'captured_at' => $p->captured_at?->toISOString(),
            ]) : null,
            'descriptions' => $v->relationLoaded('descriptions') ? $v->descriptions : null,
            'valuation_officer_id' => $v->valuation_officer_id,
            'valuation_officer' => $v->relationLoaded('valuationOfficer') ? $v->valuationOfficer?->full_name : null,
            'status' => $v->status,
            'manager_remarks' => $v->manager_remarks,
            'manager_reviewed_at' => $v->manager_reviewed_at?->toISOString(),
            'ac_remarks' => $v->ac_remarks,
            'ac_reviewed_at' => $v->ac_reviewed_at?->toISOString(),
            'account_reviewed_by' => $v->account_reviewed_by,
            'account_reviewed_at' => $v->account_reviewed_at?->toISOString(),
            'account_remarks' => $v->account_remarks,
            'registration_status' => $v->registration_status,
            'discovery_reference' => $v->relationLoaded('discovery') ? $v->discovery?->discovery_reference : null,
            'discovery_status' => $v->relationLoaded('discovery') ? $v->discovery?->status : null,
            'reviews' => $v->relationLoaded('reviews') ? $v->reviews : null,
            'created_at' => $v->created_at?->toISOString(),
        ];
    }
}
