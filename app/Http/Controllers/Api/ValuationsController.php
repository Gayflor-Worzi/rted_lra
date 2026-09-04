<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EvidencePhoto;
use App\Models\PropertyBill;
use App\Models\User;
use App\Models\Valuation;
use App\Models\ValuationPropertyDescription;
use App\Services\AssignmentEligibilityService;
use App\Services\AuditService;
use App\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Valuation workflow — Draft → Submitted → Manager Review → AC Approval → Approved.
 * Produces an assessed value + recommended annual tax; does not generate bills,
 * Document #s or Property IDs (all originate in LITAS and are retained as given).
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
            ->with(['valuationOfficer:id,full_name', 'bill:id,document_number,property_id', 'descriptions', 'discovery:id,discovery_reference,status']);

        if ($status = $request->query('status')) {
            if ($status === 'Submitted') {
                $query->whereIn('status', ['Submitted', 'Manager Review']);
            } elseif (in_array($status, Valuation::STATUSES, true)) {
                $query->where('status', $status);
            }
        }
        if ($q = $request->query('q')) {
            $query->where(function ($b) use ($q) {
                $b->where('valuation_reference', 'like', like_term($q))
                    ->orWhere('document_number', 'like', like_term($q))
                    ->orWhere('property_id', 'like', like_term($q))
                    ->orWhere('owner_name', 'like', like_term($q));
            });
        }

        $this->applyScope($query, $user);

        $rows = $query->orderByDesc('id')->paginate($request->query('per_page', 20))->withQueryString();
        $rows->getCollection()->transform(fn ($v) => $this->present($v));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canPermission('valuation.create'), 403, 'Missing permission: valuation.create');

        $data = $request->validate([
            'bill_id' => 'nullable|integer|exists:property_bills,id',
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

        // Reassessment must reference a real existing LITAS bill whose Property ID
        // matches — prevents linking a reassessment to an unrelated/duplicate bill
        // (spec #16.6 / #16.7).
        if ($data['valuation_type'] === 'reassessment') {
            abort_unless($bill, 422, 'Reassessment must reference an existing LITAS bill.');
            if (!empty($data['property_id']) && $bill->property_id !== $data['property_id']) {
                abort_unless(($bill->property_id === null && $data['property_id'] === $bill->property_id), 422, 'Property ID does not match the referenced bill.');
            }
        }

        $valuation = Valuation::create([
            'valuation_type' => $data['valuation_type'],
            'bill_id' => $bill?->id,
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
            'status' => 'Draft',
            'remarks' => $data['remarks'] ?? null,
        ]);

        $this->syncDescriptions($valuation, $data['descriptions'] ?? []);

        $totals = $this->rollupTotals($valuation);

        $this->audit->record($valuation, 'valuation.created', $user->id, [], [
            'valuation_reference' => $valuation->valuation_reference,
            'valuation_type' => $valuation->valuation_type,
        ]);

        $this->tasks->notifySection('VAL', 'Valuation drafted', "Valuation {$valuation->valuation_reference} drafted.", 'valuation');

        return response()->json([
            'data' => $this->present($valuation->fresh(['valuationOfficer:id,full_name', 'descriptions', 'photos'])),
            'message' => 'Valuation draft created.',
        ], 201);
    }

    public function show(Valuation $valuation)
    {
        abort_unless($this->canSee($valuation), 403, 'Valuation outside your data scope.');

        $valuation->load(['valuationOfficer:id,full_name', 'bill:id,document_number,property_id', 'reviews', 'descriptions', 'photos', 'discovery:id,discovery_reference,status']);

        return response()->json(['data' => $this->present($valuation)]);
    }

    public function update(Request $request, Valuation $valuation)
    {
        $user = $request->user();
        abort_unless($user->canPermission('valuation.edit'), 403, 'Missing permission: valuation.edit');
        abort_unless($this->canSee($valuation), 403, 'Valuation outside your data scope.');
        abort_unless(in_array($valuation->status, ['Draft', 'Returned', 'Submitted'], true), 422, 'Valuation cannot be edited in its current status.');

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
        ]);

        $valuation->update($data);

        if ($request->has('descriptions')) {
            $this->syncDescriptions($valuation, $request->input('descriptions') ?? []);
        }

        $this->audit->record($valuation, 'valuation.updated', $user->id, [], $data);

        return response()->json([
            'data' => $this->present($valuation->fresh(['descriptions', 'photos'])),
            'message' => 'Valuation updated.',
        ]);
    }

    /** Bulk-replace the repeatable Property Description sub-table. */
    public function descriptions(Request $request, Valuation $valuation)
    {
        $user = $request->user();
        abort_unless($user->canPermission('valuation.edit'), 403, 'Missing permission: valuation.edit');
        abort_unless($this->canSee($valuation), 403, 'Valuation outside your data scope.');
        abort_unless(in_array($valuation->status, ['Draft', 'Returned', 'Submitted'], true), 422, 'Valuation cannot be edited in its current status.');

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

        $this->audit->record($valuation, 'valuation.descriptions', $user->id, [], ['rows' => count($data['descriptions'])]);

        return response()->json([
            'data' => [
                'descriptions' => $valuation->descriptions()->get(),
                'totals' => $totals,
            ],
            'message' => 'Property descriptions saved.',
        ]);
    }

    /** Officer submits a draft for manager review — full form integrity is enforced here. */
    public function submit(Request $request, Valuation $valuation)
    {
        $user = $request->user();
        abort_unless($user->canPermission('valuation.submit'), 403, 'Missing permission: valuation.submit');
        abort_unless($this->canSee($valuation), 403, 'Valuation outside your data scope.');
        abort_unless(in_array($valuation->status, ['Draft', 'Returned'], true), 422, 'Valuation cannot be submitted in its current status.');

        $data = $request->validate([
            'assessed_value' => 'required|numeric|min:0',
            'annual_tax' => 'required|numeric|min:0',
            'applicable_tax_rate' => 'nullable|numeric|min:0',
            'other_amounts' => 'nullable|numeric|min:0',
            'remarks' => 'nullable|string|max:2000',
        ]);

        $this->assertSubmittable($valuation, $data);

        $reassessed = $data['assessed_value'];
        $annualTax = $data['annual_tax'];

        if (! empty($data['applicable_tax_rate']) && empty($data['annual_tax'])) {
            $annualTax = round($reassessed * ($data['applicable_tax_rate'] / 100), 2);
        }

        $totalPropertyValue = (float) $valuation->descriptions()->sum('value');
        $totalTaxPayable = $annualTax + (float) ($data['other_amounts'] ?? 0);

        $valuation->update([
            'assessed_value' => $reassessed,
            'reassessed_value' => $valuation->reassessed_value ?? $reassessed,
            'annual_tax' => $annualTax,
            'applicable_tax_rate' => $data['applicable_tax_rate'] ?? $valuation->applicable_tax_rate,
            'other_amounts' => $data['other_amounts'] ?? $valuation->other_amounts,
            'total_property_value' => $totalPropertyValue ?: $reassessed,
            'total_tax_payable' => $totalTaxPayable,
            'submitted_at' => now(),
            'prepared_by_designation' => $this->designation($user),
            'status' => 'Submitted',
            'remarks' => $data['remarks'] ?? $valuation->remarks,
        ]);

        $valuation->reviews()->create([
            'stage' => 'supervisor',
            'decision' => 'forward',
            'reviewer_id' => $user->id,
            'remarks' => 'Submitted by officer.',
        ]);

        $this->syncDiscovery($valuation);

        $this->audit->record($valuation, 'valuation.submitted', $user->id, [], [
            'assessed_value' => $reassessed,
            'annual_tax' => $annualTax,
        ]);

        $this->tasks->notifySection('VAL', 'Valuation submitted', "Valuation {$valuation->valuation_reference} awaiting manager review.", 'valuation');

        return response()->json([
            'data' => $this->present($valuation->fresh(['descriptions', 'photos'])),
            'message' => 'Valuation submitted.',
        ]);
    }

    /** Manager reviews — approve (forward to AC) or return to officer. */
    /** Manager / supervisor assigns (or reassigns) the valuation to an officer. */
    public function assign(Request $request, Valuation $valuation)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['valuation.review', 'valuation.forward_ac', 'valuation.approve']), 403, 'Missing permission: valuation.review');
        abort_unless($this->canSee($valuation), 403, 'Valuation outside your data scope.');
        abort_unless(in_array($valuation->status, ['Draft', 'Returned'], true), 422, 'Only Draft or Returned valuations can be assigned.');

        $data = $request->validate([
            'officer_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $officer = User::find($data['officer_id']);

        abort_if(
            ! $officer || ! AssignmentEligibilityService::canExecuteTaskType($officer, \App\Models\Task::TYPE_VALUATION),
            422,
            'Assignment rejected. '.($officer?->full_name ?? 'User').' does not have permission to perform Valuation.'
        );

        $oldId = $valuation->valuation_officer_id;
        $valuation->update(['valuation_officer_id' => $officer->id]);

        $this->tasks->createValuationTask($valuation, $officer, $user);
        $this->tasks->notify($officer, 'Valuation assigned', "Valuation {$valuation->valuation_reference} assigned to you.", 'valuation');

        $this->syncDiscovery($valuation);

        $this->audit->record($valuation, 'valuation.assigned', $user->id, ['valuation_officer_id' => $oldId], ['valuation_officer_id' => $officer->id]);

        return response()->json([
            'data' => $this->present($valuation->fresh(['valuationOfficer:id,full_name'])),
            'message' => "Valuation assigned to {$officer->full_name}.",
        ]);
    }

    public function review(Request $request, Valuation $valuation)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['valuation.review', 'valuation.approve']), 403, 'Missing permission: valuation.review');
        abort_unless($this->canSee($valuation), 403, 'Valuation outside your data scope.');

        $data = $request->validate([
            'decision' => ['required', Rule::in(['forward_ac', 'return'])],
            'remarks' => 'nullable|string|max:2000',
        ]);

        abort_unless(in_array($valuation->status, ['Submitted', 'Manager Review', 'AC Approval'], true), 422, 'Valuation not in a reviewable status.');

        if ($data['decision'] === 'forward_ac') {
            $valuation->update([
                'status' => 'AC Approval',
                'manager_decision' => 'forward_ac',
                'manager_remarks' => $data['remarks'] ?? null,
                'manager_reviewed_by' => $user->id,
                'manager_reviewed_at' => now(),
            ]);
        } else {
            $valuation->update([
                'status' => 'Returned',
                'manager_decision' => 'return',
                'manager_remarks' => $data['remarks'] ?? null,
                'manager_reviewed_by' => $user->id,
                'manager_reviewed_at' => now(),
            ]);
        }

        $valuation->reviews()->create([
            'stage' => 'manager',
            'decision' => $data['decision'],
            'reviewer_id' => $user->id,
            'remarks' => $data['remarks'] ?? null,
        ]);

        $this->syncDiscovery($valuation);

        $this->audit->record($valuation, 'valuation.reviewed', $user->id, [], $data);

        $this->tasks->notifySection('VAL', 'Valuation reviewed', "Valuation {$valuation->valuation_reference} {$data['decision']}.", 'valuation');

        return response()->json([
            'data' => $this->present($valuation->fresh()),
            'message' => 'Valuation reviewed.',
        ]);
    }

    /** AC approves or rejects the valuation. */
    public function decide(Request $request, Valuation $valuation)
    {
        $user = $request->user();
        abort_unless($user->canPermission('valuation.approve'), 403, 'Missing permission: valuation.approve');
        abort_unless($this->canSee($valuation), 403, 'Valuation outside your data scope.');
        abort_if($valuation->valuation_officer_id === $user->id, 422, 'Separation of duties: you may not approve/reject a valuation you prepared.');

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'remarks' => 'nullable|string|max:2000',
        ]);

        abort_unless($valuation->status === 'AC Approval', 422, 'Valuation not awaiting AC approval.');

        $valuation->update([
            'status' => $data['decision'] === 'approve' ? 'Approved' : 'Rejected',
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

        if ($data['decision'] === 'approve' && $valuation->bill_id) {
            $bill = PropertyBill::find($valuation->bill_id);
            if ($bill) {
                $bill->update([
                    'assessed_value' => $valuation->assessed_value,
                    'property_classification' => $valuation->property_classification ?? $bill->property_classification,
                ]);
            }
        }

        $this->audit->record($valuation, 'valuation.decided', $user->id, [], $data);

        $this->tasks->notifySection('VAL', 'Valuation decided', "Valuation {$valuation->valuation_reference} {$data['decision']}d.", 'valuation');

        return response()->json([
            'data' => $this->present($valuation->fresh()),
            'message' => 'Valuation '.$data['decision'].'d.',
        ]);
    }

    /** Account Manager confirms the approved result was processed in the source system. */
    public function processing(Request $request, Valuation $valuation)
    {
        $user = $request->user();
        abort_unless($user->canPermission('valuation.litas_processing'), 403, 'Missing permission: valuation.litas_processing');

        abort_unless($valuation->status === 'Approved', 422, 'Only approved valuations can be marked processed.');
        abort_if($valuation->litas_processing_status === Valuation::LITAS_PROCESSED, 422, 'Already marked processed.');

        $valuation->update([
            'litas_processing_status' => Valuation::LITAS_PROCESSED,
            'litas_processed_by' => $user->id,
            'litas_processed_at' => now(),
        ]);

        $this->audit->record($valuation, 'valuation.processed_in_litas', $user->id);
        $this->mirrorProcessedDiscovery($valuation, $user->id);

        return response()->json([
            'data' => $this->present($valuation->fresh()),
            'message' => 'Valuation result confirmed as processed in the source system.',
        ]);
    }

    /** Once the approved valuation is processed, the linked discovery enters the same final stage. */
    private function mirrorProcessedDiscovery(Valuation $valuation, ?int $actorId): void
    {
        if (! $valuation->discovery_id) {
            return;
        }

        $discovery = \App\Models\PropertyDiscovery::find($valuation->discovery_id);
        if (! $discovery) {
            return;
        }

        $discovery->update([
            'status' => \App\Models\PropertyDiscovery::STATUS_PROCESSED_IN_LITAS,
            'ac_decision' => $discovery->ac_decision ?: 'approved',
        ]);

        $this->audit->record($discovery, 'discovery.processed_in_litas', $actorId, [], ['status' => 'PROCESSED_IN_LITAS']);
    }

    /** Mirror the valuation lifecycle onto the linked discovery (Path B). */
    private function syncDiscovery(Valuation $valuation): void
    {
        if (! $valuation->discovery_id) {
            return;
        }

        $discovery = \App\Models\PropertyDiscovery::find($valuation->discovery_id);
        if (! $discovery) {
            return;
        }

        $map = [
            'Submitted' => \App\Models\PropertyDiscovery::STATUS_VALUATION_MANAGER_REVIEW,
            'Returned' => \App\Models\PropertyDiscovery::STATUS_RETURNED_FOR_CORRECTION,
            'AC Approval' => \App\Models\PropertyDiscovery::STATUS_PENDING_AC_APPROVAL,
            'Approved' => \App\Models\PropertyDiscovery::STATUS_AC_APPROVED,
            'Rejected' => \App\Models\PropertyDiscovery::STATUS_AC_REJECTED,
        ];

        if (isset($map[$valuation->status])) {
            $discovery->update([
                'status' => $map[$valuation->status],
                'ac_decision' => $valuation->status === 'Rejected' ? 'rejected' : ($valuation->status === 'Approved' ? 'approved' : $discovery->ac_decision),
                'ac_decided_by' => in_array($valuation->status, ['Approved', 'Rejected'], true)
                    ? ($discovery->ac_decided_by ?? $valuation->ac_reviewed_by) : $discovery->ac_decided_by,
                'ac_decided_at' => in_array($valuation->status, ['Approved', 'Rejected'], true)
                    ? ($discovery->ac_decided_at ?? $valuation->ac_reviewed_at) : $discovery->ac_decided_at,
                'ac_remarks' => in_array($valuation->status, ['Approved', 'Rejected'], true)
                    && $valuation->ac_remarks ? $valuation->ac_remarks : $discovery->ac_remarks,
            ]);
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
        }
        if (! $valuation->photos && $valuation->photos()->count() === 0) {
            $missing[] = 'a Property Photo';
        }

        if ($missing) {
            abort(422, 'Valuation cannot be submitted — missing required fields: '.implode(', ', $missing).'.');
        }
    }

    private function syncDescriptions(Valuation $valuation, array $rows): void
    {
        $valuation->descriptions()->delete();

        $seq = 1;
        foreach (array_values($rows) as $row) {
            $amount = (float) ($row['amount'] ?? 0);
            $quantity = (int) ($row['quantity'] ?? 1) ?: 1;
            $depreciation = (float) ($row['depreciation_pct'] ?? 0);

            $valuation->descriptions()->create([
                'seq' => $seq++,
                'description' => $row['description'] ?? '',
                'level' => $row['level'] ?? null,
                'area_sqft' => $row['area_sqft'] ?? null,
                'tar' => $row['tar'] ?? null,
                'quantity' => $quantity,
                'amount' => $row['amount'] ?? null,
                'building_age' => $row['building_age'] ?? null,
                'depreciation_pct' => $depreciation,
                'value' => ValuationPropertyDescription::computeValue($amount, $quantity, $depreciation),
            ]);
        }
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
        if ($scope === 'section') {
            return $valuation->valuation_officer_id
                && in_array($valuation->valuation_officer_id, User::where('section_id', $user->section_id)->pluck('id')->all());
        }
        if ($scope === 'team') {
            return $valuation->valuation_officer_id === $user->id
                || in_array($valuation->valuation_officer_id, User::where('supervisor_id', $user->id)->pluck('id')->all());
        }

        return $valuation->valuation_officer_id == $user->id;
    }

    private function applyScope($query, $user): void
    {
        $scope = $user->scopeLevel();
        if (in_array($scope, ['system', 'division'], true)) {
            return;
        }
        if ($scope === 'section') {
            $query->whereIn('valuation_officer_id', User::where('section_id', $user->section_id)->where('is_active', true)->pluck('id')->all());
        } elseif ($scope === 'team') {
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
            'valuation_type' => $v->valuation_type,
            'bill_id' => $v->bill_id,
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
            'annual_tax' => $v->annual_tax,
            'applicable_tax_rate' => $v->applicable_tax_rate,
            'other_amounts' => $v->other_amounts,
            'total_property_value' => $v->total_property_value,
            'total_tax_payable' => $v->total_tax_payable,
            'submitted_at' => $v->submitted_at?->toISOString(),
            'prepared_by_designation' => $v->prepared_by_designation,
            'gps_coordinate' => $v->gps_coordinate,
            'gps_accuracy' => $v->gps_accuracy,
            'photos' => $v->photos,
            'evidence_photos' => $v->relationLoaded('photos') ? $v->photos?->map(fn (EvidencePhoto $p) => [
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
            'litas_processing_status' => $v->litas_processing_status,
            'discovery_reference' => $v->relationLoaded('discovery') ? $v->discovery?->discovery_reference : null,
            'discovery_id' => $v->discovery_id,
            'discovery_status' => $v->relationLoaded('discovery') ? $v->discovery?->status : null,
            'reviews' => $v->relationLoaded('reviews') ? $v->reviews : null,
            'created_at' => $v->created_at?->toISOString(),
        ];
    }
}
