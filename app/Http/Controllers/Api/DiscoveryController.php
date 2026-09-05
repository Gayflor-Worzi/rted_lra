<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EvidencePhoto;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;
use App\Services\AssignmentEligibilityService;
use App\Services\AuditService;
use App\Services\TaskService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * New Property Discovery — first-class workflow.
 *
 * Staff discovers -> SUBMITTED -> Valuation Manager review -> CLASSIFIED.
 *   Path A (self-declaration / land): route to Account -> LITAS processing.
 *   Path B (needs valuation): valuation task -> manager -> AC -> Account -> LITAS.
 *
 * Property ID / Document # are never generated here.
 */
class DiscoveryController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly AuditService $audit,
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['discovery.view', 'discovery.create']), 403, 'Missing permission: discovery.view');

        $query = PropertyDiscovery::query()->with(['discoverer:id,full_name', 'classifiedBy:id,full_name', 'valuation:id,valuation_reference,status,owner_name,valuation_officer_id', 'valuation.valuationOfficer:id,full_name']);

        if (($status = $request->query('status')) && in_array($status, PropertyDiscovery::STATUSES, true)) {
            $query->where('status', $status);
        }
        if ($officer = $request->query('officer_id')) {
            $query->where('discovered_by', $officer);
        }
        if ($classification = $request->query('classification')) {
            $query->where('property_classification', $classification);
        }
        if ($decision = $request->query('path')) {
            $query->where('decision_path', $decision);
        }
        if ($q = $request->query('q')) {
            $query->where(function (Builder $b) use ($q) {
                $b->where('discovery_reference', 'like', like_term($q))
                    ->orWhere('owner_name', 'like', like_term($q))
                    ->orWhere('property_address', 'like', like_term($q))
                    ->orWhere('property_id', 'like', like_term($q));
            });
        }
        if ($from = $request->query('date_from')) {
            $query->whereDate('discovery_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('discovery_date', '<=', $to);
        }

        // Own-scope users (officers) only see their own discoveries /
        // valuations routed to them; managers/section see their section.
        $this->restrictDataScope($query, $user);

        $rows = $query->orderByDesc('id')->paginate($request->query('per_page', 20))->withQueryString();
        $rows->getCollection()->transform(fn ($d) => $this->present($d));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canPermission('discovery.create'), 403, 'Missing permission: discovery.create');

        $data = $request->validate($this->rules());
        abort_unless($this->hasLocationOrAddress($data), 422, 'Provide a property address or GPS coordinate for the discovery.');

        $discovery = PropertyDiscovery::create([
            'status' => 'DISCOVERED',
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

        $this->tasks->notifySection('VAL', 'New property discovered', "Discovery {$discovery->discovery_reference} for {$discovery->property_address} is awaiting review.", 'enforcement');

        return response()->json([
            'data' => $this->present($discovery->fresh(['discoverer:id,full_name'])),
            'message' => 'Property discovery recorded.',
        ], 201);
    }

    public function show(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['discovery.view', 'discovery.create', 'discovery.review']), 403, 'Missing permission.');
        $discovery->load(['discoverer:id,full_name', 'classifiedBy:id,full_name', 'acDecidedBy:id,full_name', 'photos.officer:id,full_name', 'valuation:id,valuation_reference,status,owner_name,valuation_officer_id', 'valuation.valuationOfficer:id,full_name']);

        return response()->json(['data' => $this->present($discovery)]);
    }

    public function update(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->canPermission('discovery.create'), 403, 'Missing permission: discovery.create');
        abort_unless(in_array($discovery->status, ['DISCOVERED', 'RETURNED_FOR_CORRECTION'], true), 422, 'Discovery cannot be edited in its current status.');

        $data = $request->validate($this->rules());

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
            'gps_coordinate' => $data['gps_coordinate'] ?? ($data['gps_lat'] ?? null ? $this->coordinateFrom($data) : $discovery->gps_coordinate),
            'gps_accuracy' => $data['gps_accuracy'] ?? $discovery->gps_accuracy,
            'gps_captured_at' => $data['gps_captured_at'] ?? $discovery->gps_captured_at,
            'discovery_date' => $data['discovery_date'] ?? $discovery->discovery_date,
            'remarks' => $data['remarks'] ?? $discovery->remarks,
        ]);

        $this->audit->record($discovery, 'discovery.updated', $user->id);

        return response()->json(['data' => $this->present($discovery->fresh()), 'message' => 'Discovery updated.']);
    }

    public function submit(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->canPermission('discovery.create'), 403, 'Missing permission: discovery.create');
        $this->guardStatus($discovery, ['DISCOVERED'], 'Only a discovered record can be submitted.');
        abort_if($discovery->ac_decision, 422, 'A corrected discovery must be resubmitted.');

        $discovery->update(['status' => 'SUBMITTED']);
        $this->audit->record($discovery, 'discovery.submitted', $user->id, [], ['status' => 'SUBMITTED']);
        $this->tasks->notifySection('VAL', 'Discovery submitted', "Discovery {$discovery->discovery_reference} submitted for manager review.", 'enforcement');

        return response()->json(['data' => $this->present($discovery->fresh()), 'message' => 'Discovery submitted for review.']);
    }

    /** Corrected discovery (rejected / returned) re-enters the review pipeline. */
    public function resubmit(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->canPermission('discovery.create'), 403, 'Missing permission: discovery.create');
        $this->guardStatus($discovery, ['DISCOVERED'], 'Only a reopened discovery can be resubmitted.');
        abort_unless(in_array($discovery->ac_decision, ['rejected', 'returned'], true), 422, 'Only previously rejected or returned discoveries can be resubmitted.');

        $discovery->update(['status' => 'RESUBMITTED']);
        $this->audit->record($discovery, 'discovery.resubmitted', $user->id, [], ['status' => 'RESUBMITTED']);
        $this->tasks->notifySection('VAL', 'Discovery resubmitted', "Discovery {$discovery->discovery_reference} resubmitted after correction.", 'enforcement');

        return response()->json(['data' => $this->present($discovery->fresh()), 'message' => 'Discovery resubmitted after correction.']);
    }

    public function review(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->canPermission('discovery.review'), 403, 'Missing permission: discovery.review');
        abort_if($discovery->discovered_by === $user->id, 422, 'Separation of duties: you may not review a discovery you recorded.');
        $this->guardStatus($discovery, ['SUBMITTED', 'RESUBMITTED'], 'Only a submitted or resubmitted discovery can be reviewed.');

        $data = $request->validate(['manager_remarks' => 'nullable|string|max:2000']);

        $discovery->update([
            'status' => 'UNDER_MANAGER_REVIEW',
            'classified_by' => $user->id,
            'manager_remarks' => $data['manager_remarks'] ?? $discovery->manager_remarks,
        ]);
        $this->audit->record($discovery, 'discovery.reviewed', $user->id, [], ['status' => 'UNDER_MANAGER_REVIEW']);

        return response()->json(['data' => $this->present($discovery->fresh()), 'message' => 'Discovery under manager review.']);
    }

    /** Valuation Manager decides the pathway and classifies the property. */
    public function classify(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->canPermission('discovery.classify'), 403, 'Missing permission: discovery.classify');
        abort_if($discovery->discovered_by === $user->id, 422, 'Separation of duties: you may not classify a discovery you recorded.');
        $this->guardStatus($discovery, ['UNDER_MANAGER_REVIEW', 'SUBMITTED'], 'Discovery must be under review to classify.');

        $data = $request->validate([
            'decision_path' => ['required', Rule::in(PropertyDiscovery::DECISION_PATHS)],
            'classification_decision' => 'nullable|string|max:150',
            'manager_remarks' => 'nullable|string|max:2000',
        ]);

        $discovery->update([
            'status' => 'CLASSIFIED',
            'decision_path' => $data['decision_path'],
            'classification_decision' => $data['classification_decision'] ?? null,
            'classified_by' => $user->id,
            'classified_at' => now(),
            'manager_remarks' => $data['manager_remarks'] ?? $discovery->manager_remarks,
        ]);
        $this->audit->record($discovery, 'discovery.classified', $user->id, [], ['decision_path' => $data['decision_path']]);

        return response()->json([
            'data' => $this->present($discovery->fresh()),
            'message' => 'Property classified. Path: '.strtoupper($data['decision_path']).'.',
        ]);
    }

    /** Path A — self-declaration / land → Account & Record → LITAS. */
    public function routeToAccount(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->canPermission('discovery.route_to_account'), 403, 'Missing permission: discovery.route_to_account');
        abort_if($discovery->discovered_by === $user->id, 422, 'Separation of duties: you may not route a discovery you recorded.');
        $this->guardStatus($discovery, ['CLASSIFIED'], 'Discovery must be classified first.');
        abort_if($discovery->decision_path !== 'account', 422, 'Discovery was not classified for the account path.');

        $discovery->update(['status' => 'SENT_TO_ACCOUNT']);
        $this->audit->record($discovery, 'discovery.routed_to_account', $user->id, [], ['status' => 'SENT_TO_ACCOUNT']);
        $this->tasks->notifySection('ACCT', 'Discovery sent to account', "Discovery {$discovery->discovery_reference} is ready for Account & Record processing.", 'discovery');

        return response()->json(['data' => $this->present($discovery->fresh()), 'message' => 'Discovery routed to Account & Record.']);
    }

    /** Account Manager confirms the property was processed in the source system. */
    public function accountProcessing(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['discovery.route_to_account', 'discovery.litas_processing']), 403, 'Missing permission: discovery.litas_processing');

        // LITAS identifiers arrive WITH the source-system output — recorded, not generated.
        $data = $request->validate([
            'property_id' => 'nullable|string|max:60',
            'document_number' => 'nullable|string|max:80',
            'remarks' => 'nullable|string|max:2000',
        ]);

        $discovery->update([
            'property_id' => $data['property_id'] ?? $discovery->property_id,
            'document_number' => $data['document_number'] ?? $discovery->document_number,
            'status' => 'PROCESSED_IN_LITAS',
            'processed_by' => $user->id,
            'processed_at' => now(),
        ]);
        $this->audit->record($discovery, 'discovery.processed_in_litas', $user->id, [], $data);

        return response()->json(['data' => $this->present($discovery->fresh()), 'message' => 'Discovery confirmed processed in the source system.']);
    }

    public function complete(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['discovery.litas_processing', 'discovery.review', 'discovery.approve']), 403, 'Missing permission.');
        $this->guardStatus($discovery, ['PROCESSED_IN_LITAS'], 'Only processed discoveries can be completed.');

        $discovery->update(['status' => 'COMPLETED', 'completed_at' => now()]);
        $this->audit->record($discovery, 'discovery.completed', $user->id, [], ['status' => 'COMPLETED']);

        return response()->json(['data' => $this->present($discovery->fresh()), 'message' => 'Discovery completed.']);
    }

    /** Path B — create the valuation record and assign an officer task. */
    public function routeToValuation(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->canPermission('discovery.route_to_valuation'), 403, 'Missing permission: discovery.route_to_valuation');
        abort_if($discovery->discovered_by === $user->id, 422, 'Separation of duties: you may not route a discovery you recorded.');
        $this->guardStatus($discovery, ['CLASSIFIED'], 'Discovery must be classified first.');
        abort_if($discovery->decision_path !== 'valuation', 422, 'Discovery was not classified for the valuation path.');

        $data = $request->validate(['officer_id' => 'nullable|integer|exists:users,id']);

        // If an officer is named up-front, they must be eligible for Valuation
        // BEFORE any record is created (no partial/inconsistent state).
        $officer = $data['officer_id'] ? User::find($data['officer_id']) : null;
        if ($officer) {
            abort_if(
                ! AssignmentEligibilityService::canExecuteTaskType($officer, \App\Models\Task::TYPE_VALUATION),
                422,
                'Assignment rejected. '.$officer->full_name.' does not have permission to perform Valuation.'
            );
        }

        // Create the valuation record for the property (LITAS ids stay blank).
        // Without a named officer the valuation remains unassigned for the
        // Valuation Manager to allocate (never auto-assigned to the router).
        $valuation = Valuation::create([
            'valuation_type' => 'new_property',
            'discovery_id' => $discovery->id,
            'owner_name' => $discovery->owner_name ?? 'Discovered Property',
            'owner_contact' => $discovery->owner_contact,
            'tin' => $discovery->tin,
            'property_classification' => $discovery->property_classification,
            'property_address' => $discovery->property_address,
            'gps_coordinate' => $discovery->gps_coordinate,
            'assessment_date' => now()->toDateString(),
            'valuation_officer_id' => $officer?->id,
            'status' => 'Draft',
            'remarks' => "Created from discovery {$discovery->discovery_reference}.",
        ]);

        $discovery->update([
            'status' => 'VALUATION_REQUIRED',
            'valuation_id' => $valuation->id,
        ]);

        if ($officer) {
            $this->tasks->createValuationTask($valuation, $officer, $user);
            $discovery->update(['status' => 'VALUATION_ASSIGNED']);
            $this->tasks->notify($officer, 'Valuation assigned', "Valuation {$valuation->valuation_reference} assigned from discovery {$discovery->discovery_reference}.", 'valuation');
        }

        $this->audit->record($discovery, 'discovery.routed_to_valuation', $user->id, [], ['valuation_id' => $valuation->id]);

        return response()->json([
            'data' => $this->present($discovery->fresh(['valuation'])),
            'message' => 'Discovery routed to valuation.',
        ]);
    }

    public function approve(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->canPermission('discovery.approve'), 403, 'Missing permission: discovery.approve');
        abort_if($discovery->discovered_by === $user->id, 422, 'Separation of duties: you may not approve a discovery you recorded.');
        $this->guardStatus($discovery, ['PENDING_AC_APPROVAL'], 'Discovery must be pending AC approval.');

        $data = $request->validate(['remarks' => 'nullable|string|max:2000']);

        $discovery->update([
            'status' => 'AC_APPROVED',
            'ac_decision' => 'approved',
            'ac_decided_by' => $user->id,
            'ac_decided_at' => now(),
            'ac_remarks' => $data['remarks'] ?? null,
        ]);
        $this->audit->record($discovery, 'discovery.approved', $user->id, [], ['status' => 'AC_APPROVED']);

        return response()->json(['data' => $this->present($discovery->fresh()), 'message' => 'Discovery approved by the Assistant Commissioner.']);
    }

    public function reject(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->canPermission('discovery.reject'), 403, 'Missing permission: discovery.reject');
        abort_if($discovery->discovered_by === $user->id, 422, 'Separation of duties: you may not reject a discovery you recorded.');
        $this->guardStatus($discovery, ['PENDING_AC_APPROVAL', 'VALUATION_MANAGER_REVIEW'], 'Discovery is not pending a decision.');

        $data = $request->validate(['remarks' => 'nullable|string|max:2000']);

        $discovery->update([
            'status' => 'AC_REJECTED',
            'ac_decision' => 'rejected',
            'ac_decided_by' => $user->id,
            'ac_decided_at' => now(),
            'ac_remarks' => $data['remarks'] ?? null,
        ]);
        $this->audit->record($discovery, 'discovery.rejected', $user->id, [], ['status' => 'AC_REJECTED']);

        return response()->json(['data' => $this->present($discovery->fresh()), 'message' => 'Discovery rejected.']);
    }

    public function reopen(Request $request, PropertyDiscovery $discovery)
    {
        $user = $request->user();
        abort_unless($user->canPermission('discovery.reopen'), 403, 'Missing permission: discovery.reopen');
        $this->guardStatus($discovery, ['AC_REJECTED', 'RETURNED_FOR_CORRECTION'], 'Only rejected / returned discoveries can be reopened.');

        // Keep the rejection/return marker so the corrected record is resubmitted,
        // not treated as a brand-new submission.
        $discovery->update([
            'status' => 'DISCOVERED',
            'ac_decision' => $discovery->ac_decision ?: ($discovery->status === 'RETURNED_FOR_CORRECTION' ? 'returned' : null),
        ]);
        $this->audit->record($discovery, 'discovery.reopened', $user->id, [], ['status' => 'DISCOVERED']);

        return response()->json(['data' => $this->present($discovery->fresh()), 'message' => 'Discovery reopened for correction.']);
    }

    /** Discovery dashboard aggregates (drill-downable by list endpoint). */
    public function stats(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['discovery.view', 'discovery.review', 'dashboard.view_division', 'dashboard.view_section']), 403, 'Missing permission.');

        $base = PropertyDiscovery::query();

        // Apply the same filters the list endpoint supports so dashboard KPIs
        // reflect the filtered dataset (never unfiltered all-time figures).
        if (($status = $request->query('status')) && in_array($status, PropertyDiscovery::STATUSES, true)) {
            $base->where('status', $status);
        }
        if ($officer = $request->query('officer_id')) {
            $base->where('discovered_by', $officer);
        }
        if ($classification = $request->query('classification')) {
            $base->where('property_classification', $classification);
        }
        if ($decision = $request->query('path')) {
            $base->where('decision_path', $decision);
        }
        if ($from = $request->query('date_from')) {
            $base->whereDate('discovery_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $base->whereDate('discovery_date', '<=', $to);
        }

        // RBAC: own-scope officers only see their own discoveries; supervisors
        // their team. This mirrors the list endpoint's data restriction.
        $this->restrictDataScope($base, $user);

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

        $avgDays = (clone $base)->whereNotNull('completed_at')
            ->selectRaw($this->isPostgres()
                ? 'avg(EXTRACT(EPOCH FROM (completed_at - created_at)) / 86400) as avg'
                : 'avg(timestampdiff(DAY, created_at, completed_at)) as avg')
            ->value('avg');

        $pendingReview = (int) ($byStatus['SUBMITTED'] ?? 0) + (int) ($byStatus['UNDER_MANAGER_REVIEW'] ?? 0) + (int) ($byStatus['RESUBMITTED'] ?? 0);

        return response()->json(['data' => [
            'scope' => $user->scopeLevel(),
            'total' => (int) (clone $base)->count(),
            'by_status' => $byStatus,
            'by_staff' => $byStaff,
            'awaiting_review' => $pendingReview,
            'classified' => (int) ($byStatus['CLASSIFIED'] ?? 0),
            'sent_to_account' => (int) ($byStatus['SENT_TO_ACCOUNT'] ?? 0),
            'requiring_valuation' => (int) (($byStatus['VALUATION_REQUIRED'] ?? 0) + ($byStatus['VALUATION_ASSIGNED'] ?? 0)),
            'under_valuation' => (int) (($byStatus['UNDER_VALUATION'] ?? 0) + ($byStatus['VALUATION_MANAGER_REVIEW'] ?? 0)),
            'pending_ac' => (int) ($byStatus['PENDING_AC_APPROVAL'] ?? 0),
            'approved' => (int) ($byStatus['AC_APPROVED'] ?? 0),
            'rejected_returned' => (int) (($byStatus['AC_REJECTED'] ?? 0) + ($byStatus['RETURNED_FOR_CORRECTION'] ?? 0)),
            'processed_in_litas' => (int) ($byStatus['PROCESSED_IN_LITAS'] ?? 0),
            'completed' => (int) ($byStatus['COMPLETED'] ?? 0),
            'avg_processing_days' => $avgDays === null ? null : round((float) $avgDays, 1),
            // Path-wise pipeline so the dashboard never counts stages that are
            // inapplicable to a discovery's chosen path (Path A vs Path V).
            'path_a_pipeline' => [
                'discovered' => (int) ($byStatus['DISCOVERED'] ?? 0),
                'submitted' => (int) ($byStatus['SUBMITTED'] ?? 0),
                'under_review' => (int) ($byStatus['UNDER_MANAGER_REVIEW'] ?? 0),
                'classified' => (int) ($byStatus['CLASSIFIED'] ?? 0),
                'sent_to_account' => (int) ($byStatus['SENT_TO_ACCOUNT'] ?? 0),
                'processed_in_litas' => (int) ($byStatus['PROCESSED_IN_LITAS'] ?? 0),
                'completed' => (int) ($byStatus['COMPLETED'] ?? 0),
            ],
            'path_v_pipeline' => [
                'discovered' => (int) ($byStatus['DISCOVERED'] ?? 0),
                'submitted' => (int) ($byStatus['SUBMITTED'] ?? 0),
                'under_review' => (int) ($byStatus['UNDER_MANAGER_REVIEW'] ?? 0),
                'classified' => (int) ($byStatus['CLASSIFIED'] ?? 0),
                'valuation_required' => (int) ($byStatus['VALUATION_REQUIRED'] ?? 0),
                'valuation_assigned' => (int) ($byStatus['VALUATION_ASSIGNED'] ?? 0),
                'under_valuation' => (int) ($byStatus['UNDER_VALUATION'] ?? 0),
                'valuation_review' => (int) ($byStatus['VALUATION_MANAGER_REVIEW'] ?? 0),
                'pending_ac' => (int) ($byStatus['PENDING_AC_APPROVAL'] ?? 0),
                'ac_approved' => (int) ($byStatus['AC_APPROVED'] ?? 0),
                'ac_rejected' => (int) ($byStatus['AC_REJECTED'] ?? 0),
                'sent_to_account_manager' => (int) ($byStatus['SENT_TO_ACCOUNT_MANAGER'] ?? 0),
                'processed_in_litas' => (int) ($byStatus['PROCESSED_IN_LITAS'] ?? 0),
                'completed' => (int) ($byStatus['COMPLETED'] ?? 0),
            ],
        ]]);
    }

    /* ---------- helpers ---------- */

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

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
            'gps_lat' => 'nullable|numeric',
            'gps_lng' => 'nullable|numeric',
            'gps_coordinate' => 'nullable|string|max:100',
            'gps_accuracy' => 'nullable|numeric|min:0',
            'gps_captured_at' => 'nullable|date',
            'discovery_date' => 'nullable|date',
            'remarks' => 'nullable|string|max:2000',
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

    private function guardStatus(PropertyDiscovery $d, array $allowed, string $message): void
    {
        abort_unless(in_array($d->status, $allowed, true), 422, $message);
    }

    /** own-scope officers see their own discoveries / routed valuations only. */
    private function restrictDataScope(Builder $query, User $user): void
    {
        if ($user->scopeLevel() === 'own') {
            $query->where(function (Builder $b) use ($user) {
                $b->where('discovered_by', $user->id)
                    ->orWhere('classified_by', $user->id)
                    ->orWhereHas('valuation', fn ($v) => $v->where('valuation_officer_id', $user->id));
            });

            return;
        }

        if ($user->scopeLevel() === 'team') {
            $query->where(function (Builder $b) use ($user) {
                $b->where('discovered_by', $user->id)
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
     * Unified property lifecycle profile (spec §9/§11): resolve a LITAS property
     * (by property_id or document_number, e.g. from a bill) and return every linked
     * Discovery and Valuation record so manager/AC review screens and the property
     * detail modal can show the full history without navigating across modules.
     */
    public function profile(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['discovery.view', 'discovery.create', 'valuation.view_history', 'valuation.review', 'reports.view', 'dashboard.view_division', 'view_all_dashboard']), 403, 'Missing permission to view the property profile.');

        $propertyId = $request->query('property_id');
        $documentNumber = $request->query('document_number');
        $billId = $request->query('bill_id');

        abort_unless($propertyId || $documentNumber || $billId, 422, 'Provide property_id, document_number, or bill_id.');

        // Resolve all matching LITAS bills (same property) so the profile can show
        // the tax/bill lifecycle alongside discovery/valuation history.
        $bills = PropertyBill::query();
        if ($billId) {
            $bills->where('id', $billId);
        } elseif ($propertyId) {
            $bills->where('property_id', $propertyId);
        } else {
            $bills->where('document_number', $documentNumber);
        }
        $bills = $bills->get();

        $propertyIds = $bills->pluck('property_id')->filter()->values();
        $documentNumbers = $bills->pluck('document_number')->filter()->values();
        $billIds = $bills->pluck('id');

        // Discoveries may carry the same LITAS identifiers once the Account Manager
        // writes them after LITAS processing; otherwise match by TIN.
        $discoveries = PropertyDiscovery::query()->with(['discoverer:id,full_name', 'valuation:id,valuation_reference,status,valuation_officer_id', 'valuation.valuationOfficer:id,full_name']);
        $discoveries->where(function ($q) use ($propertyIds, $documentNumbers) {
            $q->whereIn('property_id', $propertyIds)->orWhereIn('document_number', $documentNumbers);
        });
        if ($discoveries->count() === 0 && $bills->isNotEmpty()) {
            $tin = $bills->first()->tin;
            if ($tin) {
                $discoveries = PropertyDiscovery::query()->with(['discoverer:id,full_name', 'valuation:id,valuation_reference,status,valuation_officer_id', 'valuation.valuationOfficer:id,full_name'])
                    ->where('tin', $tin);
            }
        }
        $discoveryRows = $discoveries->orderByDesc('id')->get();

        // Valuations linked to these bills, or to the linked discoveries.
        $valuationIds = $discoveryRows->pluck('valuation_id')->filter()->values();
        $valuations = Valuation::query()->with('valuationOfficer:id,full_name')
            ->where(function ($q) use ($billIds, $propertyIds, $documentNumbers, $valuationIds) {
                $q->whereIn('bill_id', $billIds)
                  ->orWhereIn('property_id', $propertyIds)
                  ->orWhereIn('document_number', $documentNumbers)
                  ->orWhereIn('id', $valuationIds);
            })
            ->orderByDesc('id')->get();

        return response()->json([
            'data' => [
                'bills' => $bills->map(fn ($b) => [
                    'id' => $b->id,
                    'property_id' => $b->property_id,
                    'document_number' => $b->document_number,
                    'tin' => $b->tin,
                    'taxpayer_name' => $b->taxpayer_name,
                    'total_tax_due' => (float) $b->total_tax_due,
                    'outstanding_balance' => (float) $b->outstanding_balance,
                    'payment_status' => $b->payment_status,
                    'case_status' => $b->case_status,
                    'date_logged' => $b->date_logged?->toDateString(),
                ]),
                'discoveries' => $discoveryRows->map(fn ($d) => $this->present($d)),
                'valuations' => $valuations->values()->map(fn ($v) => [
                    'id' => $v->id,
                    'valuation_reference' => $v->valuation_reference,
                    'valuation_type' => $v->valuation_type,
                    'property_id' => $v->property_id,
                    'document_number' => $v->document_number,
                    'bill_id' => $v->bill_id,
                    'owner_name' => $v->owner_name,
                    'status' => $v->status,
                    'assessed_value' => (float) ($v->assessed_value ?? 0),
                    'reassessed_value' => (float) ($v->reassessed_value ?? 0),
                    'annual_tax' => (float) ($v->annual_tax ?? 0),
                    'valuation_officer' => $v->relationLoaded('valuationOfficer') ? $v->valuationOfficer?->full_name : null,
                    'created_at' => $v->created_at?->toISOString(),
                ]),
                'as_of' => now()->toISOString(),
            ],
        ]);
    }

    private function present(PropertyDiscovery $d): array
    {
        return [
            'id' => $d->id,
            'discovery_reference' => $d->discovery_reference,
            'status' => $d->status,
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
            'property_id' => $d->property_id,
            'document_number' => $d->document_number,
            'gps_coordinate' => $d->gps_coordinate,
            'gps_accuracy' => $d->gps_accuracy !== null ? (float) $d->gps_accuracy : null,
            'gps_captured_at' => $d->gps_captured_at?->toISOString(),
            'discovery_date' => $d->discovery_date?->toDateString(),
            'discovered_by' => $d->relationLoaded('discoverer') ? ($d->discoverer?->full_name ?? "User #{$d->discovered_by}") : $d->discovered_by,
            'decision_path' => $d->decision_path,
            'route' => $this->routeOf($d),
            'workflow' => $this->workflowStatuses($d),
            'classification_decision' => $d->classification_decision,
            'classified_by' => $d->relationLoaded('classifiedBy') ? ($d->classifiedBy?->full_name ?? "User #{$d->classified_by}") : $d->classified_by,
            'classified_at' => $d->classified_at?->toISOString(),
            'manager_remarks' => $d->manager_remarks,
            'valuation_id' => $d->valuation_id,
            'ac_decision' => $d->ac_decision,
            'ac_remarks' => $d->ac_remarks,
            'processed_at' => $d->processed_at?->toISOString(),
            'completed_at' => $d->completed_at?->toISOString(),
            'remarks' => $d->remarks,
            'created_at' => $d->created_at?->toISOString(),
            'photos_count' => $d->relationLoaded('photos') ? $d->photos->count() : null,
            'photos' => $d->relationLoaded('photos')
                ? $d->photos->map(fn ($ph) => [
                    'id' => $ph->id,
                    'photo_reference' => $ph->photo_reference,
                    'photo_type' => $ph->photo_type,
                    'file_path' => $ph->file_path,
                    'mime' => $ph->mime,
                    'captured_at' => $ph->captured_at?->toISOString(),
                ])->values()
                : null,
            'valuation' => $d->relationLoaded('valuation') && $d->valuation
                ? [
                    'id' => $d->valuation->id,
                    'valuation_reference' => $d->valuation->valuation_reference,
                    'status' => $d->valuation->status,
                    'valuation_officer' => $d->valuation->relationLoaded('valuationOfficer')
                        ? ($d->valuation->valuationOfficer?->full_name ?? null) : null,
                ] : null,
        ];
    }

    /** The classification route actually taken by this record. */
    private function routeOf(PropertyDiscovery $d): ?string
    {
        if ($d->decision_path) {
            return $d->decision_path;
        }

        return $d->valuation_id ? 'valuation' : null;
    }

    /**
     * Route-driven workflow — only the branch actually taken, with correction /
     * rejection stages inserted only when that event actually occurred.
     */
    private function workflowStatuses(PropertyDiscovery $d): array
    {
        $common = ['DISCOVERED', 'SUBMITTED', 'UNDER_MANAGER_REVIEW', 'CLASSIFIED'];

        if (! $this->routeOf($d)) {
            return $common;
        }

        if ($this->routeOf($d) === 'account') {
            return array_merge($common, ['SENT_TO_ACCOUNT', 'PROCESSED_IN_LITAS', 'COMPLETED']);
        }

        $valuation = array_merge($common, [
            'VALUATION_REQUIRED', 'VALUATION_ASSIGNED', 'UNDER_VALUATION',
            'VALUATION_MANAGER_REVIEW', 'PENDING_AC_APPROVAL',
        ]);

        $correction = in_array($d->ac_decision, ['rejected', 'returned'], true)
            || in_array($d->status, ['RETURNED_FOR_CORRECTION', 'RESUBMITTED'], true);

        if (! $correction) {
            $correction = AuditLog::where('auditable_type', PropertyDiscovery::class)
                ->where('auditable_id', $d->id)
                ->whereIn('action', ['discovery.returned', 'discovery.rejected'])
                ->exists();
        }

        if ($correction) {
            $valuation[] = $d->ac_decision === 'rejected' ? 'AC_REJECTED' : 'RETURNED_FOR_CORRECTION';
            $valuation = array_merge($valuation, ['RESUBMITTED', 'PENDING_AC_APPROVAL']);
        }

        $valuation = array_merge($valuation, [
            'AC_APPROVED', 'SENT_TO_ACCOUNT_MANAGER', 'PROCESSED_IN_LITAS', 'COMPLETED',
        ]);

        if (! in_array($d->status, $valuation, true)) {
            $valuation[] = $d->status;
        }

        return $valuation;
    }
}