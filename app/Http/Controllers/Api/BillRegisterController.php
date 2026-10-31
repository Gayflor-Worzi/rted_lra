<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillAmendment;
use App\Models\PropertyBill;
use App\Models\Task;
use App\Models\User;
use App\Services\AssignmentEligibilityService;
use App\Services\AuditService;
use App\Services\BillScopeService;
use App\Services\TaskService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * RETD Bill Register — logs source-system-generated bills.
 * Document # and Property ID are source-system identifiers and are validated, never generated.
 */
class BillRegisterController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeBills('bills.view');

        $query = PropertyBill::query()
            ->with(['accountStaff:id,full_name', 'enforcementOfficer:id,full_name', 'propertyRef:id,source_reference']);

        // Search / filters
        if ($q = $request->query('q')) {
            $query->where(function ($b) use ($q) {
                $b->where('document_number', 'like', like_term($q))
                    ->orWhere('property_id', 'like', like_term($q))
                    ->orWhere('tin', 'like', like_term($q))
                    ->orWhere('taxpayer_name', 'like', like_term($q))
                    ->orWhere('property_address', 'like', like_term($q))
                    // the permanent property identity, searchable here too
                    ->orWhereHas('propertyRef', fn ($p) => $p->where('source_reference', 'like', like_term($q)));
            });
        }

        foreach (['property_id', 'tin', 'payment_status', 'case_status', 'delivery_status', 'tax_period'] as $filter) {
            if ($request->query($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        if (($id = (int) $request->query('logged_by')) > 0) {
            $query->where('account_staff_id', $id);
        }

        if (($id = (int) $request->query('assigned_to')) > 0) {
            $query->where('assigned_enforcement_officer_id', $id);
        }

        $this->applyBillScope($query, $request->user(), includeAssignmentQueue: true);

        $billStatusOrder = array_flip(PropertyBill::CASE_STATUSES);

        $rows = $query
            ->orderBy('tin')
            ->orderByDesc('id')
            ->paginate($request->query('per_page', 20))
            ->withQueryString();

        $rows->getCollection()->transform(fn ($b) => $this->present($b));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $this->authorizeBills('bills.create');

        $data = $this->validate($request, $this->rules());

        // A property is logged once per Tax Period: the normalized (Property ID +
        // TIN + Tax Period) triple identifies one assessment. Logging it again —
        // even by a different account officer — only produces duplicate records;
        // corrections go through Amend (# / Penalty / Interest only).
        $existing = $this->findDuplicateBill($data);

        if ($existing) {
            $this->blockedLogAudit($request, $existing);
            abort(422, $this->duplicateMessage($existing));
        }

        $officerId = $data['assigned_enforcement_officer_id'] ?? null;

        if ($officerId) {
            $officer = User::find($officerId);
            abort_if(! $officer, 422, 'Assigned user not found.');
            $this->authorizeEnforcementAssignment($request->user(), $officer);
            abort_if(
                ! AssignmentEligibilityService::canExecuteTaskType($officer, Task::TYPE_BILL_DELIVERY),
                422,
                'Assignment rejected. '.$officer->full_name.' does not have permission to perform Bill Delivery.'
            );
        }

        $total = ((float) ($data['tax_amount'] ?? 0))
            + ((float) ($data['interest_charged'] ?? 0))
            + ((float) ($data['penalty_charged'] ?? 0));

        try {
            $bill = PropertyBill::create(array_merge($data, [
                'account_staff_id' => $request->user()->id,
                'date_logged' => $data['date_logged'] ?? now()->toDateString(),
                'total_tax_due' => $data['total_tax_due'] ?? $total,
                'outstanding_balance' => $data['outstanding_balance'] ?? ($data['total_tax_due'] ?? $total),
                'case_status' => 'Logged',
                'delivery_status' => 'Logged',
                'payment_status' => 'Unpaid',
                'assigned_enforcement_officer_id' => $officerId,
            ]));
        } catch (QueryException $e) {
            // Unique-index backstop: two officers logging the same assessment
            // concurrently can both pass the check above. Re-surface the winner
            // as a friendly 422 instead of leaking the raw constraint error.
            $winner = $this->findDuplicateBill($data);

            if ($winner && $this->isUniqueViolation($e)) {
                $this->blockedLogAudit($request, $winner);
                abort(422, $this->duplicateMessage($winner));
            }

            throw $e;
        }

        $bill->update([
            'recipient_type' => $officerId ? PropertyBill::RECIPIENT_DIRECT : PropertyBill::RECIPIENT_WALK_IN,
        ]);

        // Immediate assignment (Path A) vs enforcement queue (Path B)
        $this->tasks->createTaskFromBill($bill, $officerId ? User::find($officerId) : null);

        return response()->json([
            'data' => $this->present($bill->fresh(['enforcementOfficer', 'accountStaff'])),
            'message' => 'Bill logged. Task created.',
        ], 201);
    }

    public function show(PropertyBill $property_bill)
    {
        $this->authorizeBills('bills.view');

        $this->ensureBillInScope($property_bill);

        $property_bill->load([
            'accountStaff:id,full_name',
            'enforcementOfficer:id,full_name',
            'propertyRef:id,source_reference',
            'tasks' => fn ($t) => $t->with('assignedTo:id,full_name', 'history'),
            'visits' => fn ($v) => $v->with('officer:id,full_name'),
            'verifications' => fn ($v) => $v->with('verifier:id,full_name'),
            'payments' => fn ($p) => $p->with('verifier:id,full_name'),
        ]);

        return response()->json(['data' => $this->present($property_bill)]);
    }

    public function update(Request $request, PropertyBill $property_bill)
    {
        $this->authorizeBills('bills.edit');

        $this->ensureBillInScope($property_bill, includeAssignmentQueue: true);

        $data = $request->validate([
            'taxpayer_name' => 'sometimes|string|max:255',
            'tin' => 'sometimes|string|max:40',
            'property_classification' => 'sometimes|nullable|string|max:100',
            'property_address' => 'sometimes|string|max:255',
            'gps_coordinate' => 'sometimes|nullable|string|max:100',
            'gps_accuracy' => 'sometimes|nullable|numeric|min:0',
            'gps_captured_at' => 'sometimes|nullable|date',
            'assessed_value' => 'sometimes|nullable|numeric|min:0',
            'tax_period' => 'sometimes|nullable|string|max:50',
            'property_type' => 'sometimes|nullable|string|max:100',
            'recipient_name' => 'sometimes|nullable|string|max:255',
            'recipient_contact' => 'sometimes|nullable|string|max:100',
            'remarks' => 'sometimes|nullable|string|max:2000',
        ]);

        $currentTotal = (float) $property_bill->total_tax_due;

        // A TIN/Tax Period change must not move this bill onto the triple of
        // another live bill for the same property — it is still the same rule:
        // one logged assessment per Property ID + TIN + Tax Period.
        if (isset($data['tin']) || isset($data['tax_period'])) {
            $collision = $this->findDuplicateBill(array_merge([
                'property_id' => $property_bill->property_id,
                'tin' => $property_bill->tin,
                'tax_period' => $property_bill->tax_period,
            ], array_intersect_key($data, array_flip(['tin', 'tax_period']))), excludeId: $property_bill->id);

            abort_if($collision, 422, sprintf(
                'Another bill already exists for this property for Tax Period %s (Bill %s). Adjust the Tax Period/TIN back, or amend the existing bill instead.',
                $collision?->tax_period ?: 'unspecified',
                $collision?->document_number ?: '—',
            ));
        }

        $property_bill->update($data);

        return response()->json(['data' => $this->present($property_bill), 'message' => 'Bill updated.']);
    }

    /** Direct assignment of a bill (walk-in queue or reassignment). */
    public function assign(Request $request, PropertyBill $property_bill)
    {
        $this->authorizeBills('bills.assign');

        $this->ensureBillInScope($property_bill, includeAssignmentQueue: true);

        $data = $request->validate([
            'officer_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ]);

        $officer = User::find($data['officer_id']);

        abort_if(
            ! $officer || ! AssignmentEligibilityService::canExecuteTaskType($officer, Task::TYPE_BILL_DELIVERY),
            422,
            'Assignment rejected. '.($officer?->full_name ?? 'User').' does not have permission to perform Bill Delivery.'
        );
        $task = $property_bill->tasks()
            ->where('task_type', Task::TYPE_BILL_DELIVERY)
            ->where('reference_type', 'property_bill')
            ->whereNull('assigned_to')
            ->where('status', 'Awaiting Assignment')
            ->first();
        $this->authorizeEnforcementAssignment($request->user(), $officer, $task);

        $task = $this->tasks->assignBillTask($property_bill, $officer);

        return response()->json([
            'data' => ['bill' => $this->present($property_bill->fresh()), 'task_id' => $task->id, 'task_reference' => $task->task_reference],
            'message' => 'Bill assigned to officer.',
        ]);
    }

    /** Global search across bills (Document #, Property ID, TIN, taxpayer, address, task). */
    public function search(Request $request)
    {
        $user = $request->user();

        $this->authorizeBills('bills.view');

        $q = trim((string) $request->query('q'));

        if (strlen($q) < 2) {
            return response()->json(['data' => []]);
        }

        $rows = PropertyBill::with(['accountStaff:id,full_name', 'enforcementOfficer:id,full_name'])
            ->where(function ($b) use ($q) {
                $b->where('document_number', 'like', like_term($q))
                    ->orWhere('property_id', 'like', like_term($q))
                    ->orWhere('tin', 'like', like_term($q))
                    ->orWhere('taxpayer_name', 'like', like_term($q))
                    ->orWhere('property_address', 'like', like_term($q));
            });

        $this->applyBillScope($rows, $user, includeAssignmentQueue: true);

        $rows = $rows->limit(25)
            ->get()
            ->map(fn ($bill) => $this->present($bill));

        return response()->json(['data' => $rows]);
    }

    /**
     * Targeted amend lookup — the ONLY surface where an Account & Records
     * staff member may call up a bill logged by another staff member. Located
     * by the exact Property ID + Tax Period pair; requires an amendment
     * permission so it can never be used to browse outside one's own scope.
     */
    public function lookup(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user->hasAnyPermission(['bills.amend_document', 'bills.amend_penalty_interest']),
            403,
            'Missing permission: bills.amend_document or bills.amend_penalty_interest'
        );

        $propertyId = trim((string) $request->query('property_id'));
        $taxPeriod = trim((string) $request->query('tax_period'));

        abort_if($propertyId === '' || $taxPeriod === '', 422, 'Provide both Property ID and Tax Period.');

        $bill = PropertyBill::with(['accountStaff:id,full_name', 'enforcementOfficer:id,full_name'])
            ->where('property_id', $propertyId)
            ->where('tax_period', $taxPeriod)
            ->first();

        abort_if(! $bill, 404, 'No bill found for that Property ID + Tax Period.');

        return response()->json(['data' => $this->present($bill)]);
    }

    /**
     * Amend a logged bill — restricted to document #, penalty, and interest.
     *
     * Authorization:
     *   - document_number requires bills.amend_document
     *   - penalty/interest requires bills.amend_penalty_interest
     *   - the user must have at least one of these two permissions
     *
     * Scope: deliberately NOT enforced here. Amendment is the one legitimate
     * cross-staff action (records correction), reachable only via the targeted
     * Property ID + Tax Period lookup above — never through the register list.
     */
    public function amend(Request $request, PropertyBill $property_bill)
    {
        $user = $request->user();

        abort_unless(
            $user->hasAnyPermission(['bills.amend_document', 'bills.amend_penalty_interest']),
            403,
            'Missing permission: bills.amend_document or bills.amend_penalty_interest'
        );

        $data = $request->validate([
            'document_number' => ['sometimes', 'string', 'max:80', Rule::unique('property_bills', 'document_number')->ignore($property_bill->id)],
            'penalty_charged' => ['sometimes', 'numeric', 'min:0'],
            'interest_charged' => ['sometimes', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        // Enforce per-field permission
        $docChanged = isset($data['document_number']) && $data['document_number'] !== $property_bill->document_number;
        $penaltyChanged = array_key_exists('penalty_charged', $data) && (float) $data['penalty_charged'] !== (float) $property_bill->penalty_charged;
        $interestChanged = array_key_exists('interest_charged', $data) && (float) $data['interest_charged'] !== (float) $property_bill->interest_charged;

        abort_if($docChanged && ! $user->canPermission('bills.amend_document'), 403, 'Missing permission: bills.amend_document');
        abort_if(($penaltyChanged || $interestChanged) && ! $user->canPermission('bills.amend_penalty_interest'), 403, 'Missing permission: bills.amend_penalty_interest');
        abort_if(! $docChanged && ! $penaltyChanged && ! $interestChanged, 422, 'No bill fields were changed; nothing to amend.');

        // Snapshot previous values before any mutation
        $previousDocumentNumber = $property_bill->document_number;
        $previousPenalty = (float) $property_bill->penalty_charged;
        $previousInterest = (float) $property_bill->interest_charged;
        $previousTotal = (float) $property_bill->total_tax_due;

        // Original document number is the earliest ever recorded for this bill
        $firstAmendment = BillAmendment::where('bill_id', $property_bill->id)->orderBy('id')->first();
        $originalDocumentNumber = $firstAmendment?->original_document_number ?? $previousDocumentNumber;

        // Mutate the bill
        if ($docChanged) {
            $property_bill->document_number = $data['document_number'];
        }
        if (array_key_exists('penalty_charged', $data)) {
            $property_bill->penalty_charged = $data['penalty_charged'];
        }
        if (array_key_exists('interest_charged', $data)) {
            $property_bill->interest_charged = $data['interest_charged'];
        }

        // Recalculate totals
        $property_bill->total_tax_due = ((float) $property_bill->tax_amount)
            + ((float) $property_bill->interest_charged)
            + ((float) $property_bill->penalty_charged);

        $property_bill->save(); // saving hook recalculates outstanding_balance + payment_status

        // Record amendment
        BillAmendment::create([
            'bill_id' => $property_bill->id,
            'document_number' => $property_bill->document_number,
            'property_id' => $property_bill->property_id,
            'original_document_number' => $originalDocumentNumber,
            'previous_document_number' => $docChanged ? $previousDocumentNumber : null,
            'new_document_number' => $docChanged ? $property_bill->document_number : null,
            'previous_penalty_charge' => $previousPenalty,
            'new_penalty_charge' => (float) $property_bill->penalty_charged,
            'previous_interest_charge' => $previousInterest,
            'new_interest_charge' => (float) $property_bill->interest_charged,
            'previous_total_tax_due' => $previousTotal,
            'new_total_tax_due' => (float) $property_bill->total_tax_due,
            'amended_by' => $user->id,
            'reason' => $data['reason'] ?? null,
            'amended_at' => now(),
        ]);

        // Audit trail
        $this->audit->record($property_bill, 'bill.amended', $user->id, [
            'document_number' => $docChanged ? $previousDocumentNumber : null,
            'penalty_charged' => $penaltyChanged ? $previousPenalty : null,
            'interest_charged' => $interestChanged ? $previousInterest : null,
            'total_tax_due' => $previousTotal,
        ], [
            'document_number' => $docChanged ? $property_bill->document_number : null,
            'penalty_charged' => $penaltyChanged ? (float) $property_bill->penalty_charged : null,
            'interest_charged' => $interestChanged ? (float) $property_bill->interest_charged : null,
            'total_tax_due' => (float) $property_bill->total_tax_due,
        ]);

        return response()->json([
            'data' => [
                'bill' => $this->present($property_bill),
                'amendment' => BillAmendment::latest()->where('bill_id', $property_bill->id)->first(),
            ],
            'message' => 'Bill amended successfully.',
        ]);
    }

    /** Retrieve amendment history for a bill. */
    public function amendments(PropertyBill $property_bill)
    {
        $this->authorizeBills('bills.view');

        // Amendment-authorized staff can pull history for a bill reached via
        // the targeted lookup, even when that bill sits outside their scope.
        $user = request()->user();
        if (! $user->hasAnyPermission(['bills.amend_document', 'bills.amend_penalty_interest'])) {
            $this->ensureBillInScope($property_bill);
        }

        $amendments = BillAmendment::where('bill_id', $property_bill->id)
            ->with('amender:id,full_name')
            ->orderByDesc('id')
            ->paginate(request()->query('per_page', 20));

        return response()->json(['data' => $amendments]);
    }

    /**
     * Global 'Bill Amendment' register — the dedicated surface for the
     * corrections trail across all bills. Amendment-authorized staff are the
     * correction custodians and see the full trail (mirrors the per-bill
     * amendments()). Everyone else sees only amendments on bills in scope.
     */
    public function billAmendments(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission([
            'bills.view',
            'bills.history',
            'bills.amend_document',
            'bills.amend_penalty_interest',
        ]), 403, 'Missing permission to view bill amendments.');

        $query = BillAmendment::query()
            ->with([
                'bill:id,document_number,property_id,taxpayer_name,property_address,tin',
                'amender:id,full_name',
            ]);

        $scopeIds = $this->billIdsInScope($user);
        if ($scopeIds !== null && ! $user->hasAnyPermission(['bills.amend_document', 'bills.amend_penalty_interest'])) {
            $query->whereIn('bill_id', $scopeIds);
        }

        if ($q = trim((string) $request->query('q'))) {
            $query->where(function ($b) use ($q) {
                $b->orWhereHas('bill', function ($bill) use ($q) {
                    $bill->where('document_number', 'like', like_term($q))
                        ->orWhere('property_id', 'like', like_term($q))
                        ->orWhere('taxpayer_name', 'like', like_term($q))
                        ->orWhere('tin', 'like', like_term($q));
                })->orWhere('reason', 'like', like_term($q));
            });
        }

        $rows = $query
            ->orderByDesc('amended_at')
            ->orderByDesc('id')
            ->paginate($request->query('per_page', 20))
            ->withQueryString();

        $rows->getCollection()->transform(fn (BillAmendment $a) => $this->presentAmendment($a));

        return response()->json(['data' => $rows]);
    }

    private function presentAmendment(BillAmendment $a): array
    {
        return [
            'id' => $a->id,
            'bill_id' => $a->bill_id,
            'document_number' => $a->bill?->document_number,
            'property_id' => $a->bill?->property_id,
            'taxpayer_name' => $a->bill?->taxpayer_name,
            'property_address' => $a->bill?->property_address,
            'tin' => $a->bill?->tin,
            'changed' => [
                'document_number' => ['from' => $a->previous_document_number, 'to' => $a->new_document_number],
                'penalty' => ['from' => $a->previous_penalty_charge, 'to' => $a->new_penalty_charge],
                'interest' => ['from' => $a->previous_interest_charge, 'to' => $a->new_interest_charge],
            ],
            'reason' => $a->reason,
            'amended_by' => $a->amender?->full_name ?? "User #{$a->amended_by}",
            'amended_at' => $a->amended_at?->toISOString(),
        ];
    }

    /**
     * The one-assessment rule: find a live bill for the same Property ID and
     * Tax Period. Soft-deleted bills never count, and matching is
     * case/whitespace-insensitive so 'p-001  ' and 'P-001' are the same
     * property. The TIN narrows the match only when both records carry one —
     * see PropertyBill::findEquivalentAssessment().
     */
    private function findDuplicateBill(array $data, ?int $excludeId = null): ?PropertyBill
    {
        return PropertyBill::findEquivalentAssessment(
            (string) ($data['property_id'] ?? ''),
            $data['tin'] ?? null,
            $data['tax_period'] ?? null,
            $excludeId,
        );
    }

    private function duplicateMessage(PropertyBill $existing): string
    {
        return sprintf(
            'This property is already logged for Tax Period %s — Bill %s, logged by %s on %s. You cannot log the same property again; use Amend to correct the Bill/Document #, Penalty, or Interest.',
            $existing->tax_period !== null && $existing->tax_period !== '' ? $existing->tax_period : 'unspecified',
            $existing->document_number,
            $existing->accountStaff?->full_name ?? 'another account staff member',
            $existing->date_logged?->toDateString() ?? 'an earlier date',
        );
    }

    private function blockedLogAudit(Request $request, PropertyBill $existing): void
    {
        $this->audit->record($existing, 'bill.duplicate_log_blocked', $request->user()->id, [], [
            'blocked_document_number' => $existing->document_number,
        ], $request->ip());
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $driver = (int) ($e->errorInfo[1] ?? 0);

        return in_array($driver, [19, 1062], true) // SQLite constraint / MySQL duplicate
            || (string) $e->getCode() === '23000';
    }

    private function rules(): array
    {
        return [
            'document_number' => ['required', 'string', 'max:80', Rule::unique('property_bills', 'document_number')],
            'property_id' => ['required', 'string', 'max:60'],
            'taxpayer_name' => ['required', 'string', 'max:255'],
            'tin' => ['required', 'string', 'max:40'],
            'property_classification' => ['nullable', 'string', 'max:100'],
            'property_address' => ['required', 'string', 'max:255'],
            'gps_coordinate' => ['nullable', 'string', 'max:100'],
            'gps_accuracy' => ['nullable', 'numeric', 'min:0'],
            'gps_captured_at' => ['nullable', 'date'],
            'assessed_value' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['required', 'numeric', 'min:0'],
            'interest_charged' => ['nullable', 'numeric', 'min:0'],
            'penalty_charged' => ['nullable', 'numeric', 'min:0'],
            'total_tax_due' => ['nullable', 'numeric', 'min:0'],
            'outstanding_balance' => ['nullable', 'numeric', 'min:0'],
            'tax_period' => ['nullable', 'string', 'max:50'],
            'property_type' => ['nullable', 'string', 'max:100'],
            'recipient_type' => ['sometimes', Rule::in(PropertyBill::RECIPIENT_DIRECT, PropertyBill::RECIPIENT_WALK_IN, PropertyBill::RECIPIENT_EMAIL, PropertyBill::RECIPIENT_OVERSEAS)],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_contact' => ['nullable', 'string', 'max:100'],
            'date_logged' => ['nullable', 'date'],
            'assigned_enforcement_officer_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function authorizeBills(string $permission): void
    {
        abort_unless($request = request()->user(), 401);
        abort_unless($request->canPermission($permission), 403, "Missing permission: {$permission}");
    }

    private function authorizeEnforcementAssignment(User $user, User $officer, ?Task $task = null): void
    {
        app(\App\Services\EnforcementOrganizationService::class)->authorizeBillAssignment($user, $officer, $task);
    }

    private function ensureBillInScope(PropertyBill $bill, bool $includeAssignmentQueue = false): void
    {
        $user = request()->user();

        if ($this->userSeesAllBills($user)) {
            return;
        }

        $allowedIds = $this->billIdsInScope($user, $includeAssignmentQueue);

        abort_unless(in_array($bill->id, $allowedIds, true), 403, 'Bill is outside your data scope.');
    }

    private function applyBillScope($query, User $user, bool $includeAssignmentQueue = false): void
    {
        $ids = $this->billIdsInScope($user, $includeAssignmentQueue);

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }
    }

    /** Returns array of allowed ids, or null for unrestricted. */
    private function billIdsInScope(User $user, bool $includeAssignmentQueue = false): ?array
    {
        $ids = BillScopeService::visibleBillIds($user);
        if ($includeAssignmentQueue
            && $ids !== null
            && in_array($user->role?->name, ['Enforcement Supervisor', 'Enforcement Manager'], true)) {
            return array_values(array_unique(array_merge($ids, BillScopeService::unassignedEnforcementBillIds())));
        }

        return $ids;
    }

    /**
     * Custodians of the full bill register (System, Assistant Commissioner /
     * management, and the Account Manager) can see every bill & record.
     */
    private function userSeesAllBills(User $user): bool
    {
        return BillScopeService::userSeesAllBills($user);
    }

    private function present(PropertyBill $bill): array
    {
        return [
            'id' => $bill->id,
            'document_number' => $bill->document_number,
            'source_reference' => $bill->propertyRef?->source_reference,
            'property_id' => $bill->property_id,
            'taxpayer_name' => $bill->taxpayer_name,
            'tin' => $bill->tin,
            'property_classification' => $bill->property_classification,
            'property_address' => $bill->property_address,
            'gps_coordinate' => $bill->gps_coordinate,
            'gps_accuracy' => $bill->gps_accuracy !== null ? (float) $bill->gps_accuracy : null,
            'gps_captured_at' => $bill->gps_captured_at?->toISOString(),
            'assessed_value' => $bill->assessed_value,
            'tax_amount' => $bill->tax_amount,
            'interest_charged' => $bill->interest_charged,
            'penalty_charged' => $bill->penalty_charged,
            'total_tax_due' => $bill->total_tax_due,
            'outstanding_balance' => $bill->outstanding_balance,
            'tax_period' => $bill->tax_period,
            'property_type' => $bill->property_type,
            'recipient_type' => $bill->recipient_type,
            'recipient_name' => $bill->recipient_name,
            'recipient_contact' => $bill->recipient_contact,
            'date_logged' => $bill->date_logged?->toDateString(),
            'delivery_status' => $bill->delivery_status,
            'delivery_date' => $bill->delivery_date?->toDateString(),
            'thirty_day_notice_date' => $bill->thirty_day_notice_date?->toDateString(),
            'final_notice_date' => $bill->final_notice_date?->toDateString(),
            'escalation_stage' => $bill->escalation_stage,
            'escalation_override_reason' => $bill->escalation_override_reason,
            'payment_status' => $bill->payment_status,
            'case_status' => $bill->case_status,
            'approval_status' => $bill->approval_status,
            'account_staff' => $bill->relationLoaded('accountStaff') ? $bill->accountStaff?->full_name : null,
            'enforcement_officer' => $bill->relationLoaded('enforcementOfficer') ? $bill->enforcementOfficer?->full_name : null,
            'tasks' => $bill->relationLoaded('tasks') ? $bill->tasks : null,
            'visits' => $bill->relationLoaded('visits') ? $bill->visits : null,
            'verifications' => $bill->relationLoaded('verifications') ? $bill->verifications : null,
            'payments' => $bill->relationLoaded('payments') ? $bill->payments : null,
        ];
    }
}
