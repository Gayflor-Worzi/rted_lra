<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyBill;
use App\Models\Task;
use App\Models\User;
use App\Services\AssignmentEligibilityService;
use App\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * RETD Bill Register — logs LITAS-generated bills.
 * Document # and Property ID are LITAS identifiers and are validated, never generated.
 */
class BillRegisterController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeBills('bills.view');

        $query = PropertyBill::query()
            ->with(['accountStaff:id,full_name', 'enforcementOfficer:id,full_name']);

        // Search / filters
        if ($q = $request->query('q')) {
            $query->where(function ($b) use ($q) {
                $b->where('document_number', 'like', like_term($q))
                    ->orWhere('property_id', 'like', like_term($q))
                    ->orWhere('tin', 'like', like_term($q))
                    ->orWhere('taxpayer_name', 'like', like_term($q))
                    ->orWhere('property_address', 'like', like_term($q));
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

        $this->applyBillScope($query, $request->user());

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

        $officerId = $data['assigned_enforcement_officer_id'] ?? null;

        // Only a staff member eligible for Bill Delivery can be directly assigned at logging time.
        if ($officerId) {
            $officer = User::find($officerId);
            abort_if(! $officer, 422, 'Assigned user not found.');
            abort_if(
                ! AssignmentEligibilityService::canExecuteTaskType($officer, Task::TYPE_BILL_DELIVERY),
                422,
                'Assignment rejected. '.$officer->full_name.' does not have permission to perform Bill Delivery.'
            );
        }

        $total = ((float) ($data['tax_amount'] ?? 0))
            + ((float) ($data['interest_charged'] ?? 0))
            + ((float) ($data['penalty_charged'] ?? 0));

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

        $this->ensureBillInScope($property_bill);

        $data = $request->validate([
            'taxpayer_name' => 'sometimes|string|max:255',
            'tin' => 'sometimes|string|max:40',
            'property_classification' => 'sometimes|nullable|string|max:100',
            'property_address' => 'sometimes|string|max:255',
            'assessed_value' => 'sometimes|nullable|numeric|min:0',
            'tax_amount' => 'sometimes|numeric|min:0',
            'interest_charged' => 'sometimes|numeric|min:0',
            'penalty_charged' => 'sometimes|numeric|min:0',
            'tax_period' => 'sometimes|nullable|string|max:50',
            'property_type' => 'sometimes|nullable|string|max:100',
            'recipient_name' => 'sometimes|nullable|string|max:255',
            'recipient_contact' => 'sometimes|nullable|string|max:100',
            'remarks' => 'sometimes|nullable|string|max:2000',
        ]);

        $currentTotal = (float) $property_bill->total_tax_due;

        $property_bill->update($data);

        if (array_intersect(array_keys($data), ['tax_amount', 'interest_charged', 'penalty_charged'])) {
            $property_bill->total_tax_due = ((float) $property_bill->tax_amount)
                + ((float) $property_bill->interest_charged)
                + ((float) $property_bill->penalty_charged);
            $property_bill->recalculateOutstanding();
        }

        return response()->json(['data' => $this->present($property_bill), 'message' => 'Bill updated.']);
    }

    /** Direct assignment of a bill (walk-in queue or reassignment). */
    public function assign(Request $request, PropertyBill $property_bill)
    {
        $this->authorizeBills('bills.assign');

        $this->ensureBillInScope($property_bill);

        $data = $request->validate([
            'officer_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ]);

        $officer = User::find($data['officer_id']);

        abort_if(
            ! $officer || ! AssignmentEligibilityService::canExecuteTaskType($officer, Task::TYPE_BILL_DELIVERY),
            422,
            'Assignment rejected. '.($officer?->full_name ?? 'User').' does not have permission to perform Bill Delivery.'
        );

        $task = $this->tasks->assignBillTask($property_bill, $officer);

        return response()->json([
            'data' => ['bill' => $this->present($property_bill->fresh()), 'task_id' => $task->id, 'task_reference' => $task->task_reference],
            'message' => 'Bill assigned to officer.',
        ]);
    }

    /** Global search across bills (Document #, Property ID, TIN, taxpayer, address, task). */
    public function search(Request $request)
    {
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
            })
            ->limit(25)
            ->get()
            ->map(fn ($bill) => $this->present($bill));

        return response()->json(['data' => $rows]);
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

    private function ensureBillInScope(PropertyBill $bill): void
    {
        $user = request()->user();

        if ($this->userSeesAllBills($user)) {
            return;
        }

        $allowedIds = $this->billIdsInScope($user);

        abort_unless(in_array($bill->id, $allowedIds, true), 403, 'Bill is outside your data scope.');
    }

    private function applyBillScope($query, User $user): void
    {
        $ids = $this->billIdsInScope($user);

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }
    }

    /** Returns array of allowed ids, or null for unrestricted. */
    private function billIdsInScope(User $user): ?array
    {
        if ($this->userSeesAllBills($user)) {
            return null;
        }

        $scope = $user->scopeLevel();

        if (in_array($scope, ['system', 'division'], true)) {
            return null;
        }

        $q = PropertyBill::query()->select('id');

        if ($scope === 'own') {
            $q->where(function ($b) use ($user) {
                $b->where('assigned_enforcement_officer_id', $user->id)
                    ->orWhere('account_staff_id', $user->id);
            });
        } elseif ($scope === 'team') {
            $q->whereIn('assigned_enforcement_officer_id', function ($sub) use ($user) {
                $sub->select('id')->from('users')->where('supervisor_id', $user->id);
            })->orWhere('account_staff_id', $user->id);
        } else { // section
            $q->whereIn('assigned_enforcement_officer_id', function ($sub) use ($user) {
                $sub->select('id')->from('users')->where('section_id', $user->section_id)->where('is_active', true);
            })->orWhere('account_staff_id', $user->id);
        }

        return $q->pluck('id')->all();
    }

    /**
     * Custodians of the full bill register (System, Assistant Commissioner /
     * management, and the Account Manager) can see every bill & record.
     */
    private function userSeesAllBills(User $user): bool
    {
        if (in_array($user->scopeLevel(), ['system', 'division'], true)) {
            return true;
        }

        return $user->hasRole('Account Manager');
    }

    private function present(PropertyBill $bill): array
    {
        return [
            'id' => $bill->id,
            'document_number' => $bill->document_number,
            'property_id' => $bill->property_id,
            'taxpayer_name' => $bill->taxpayer_name,
            'tin' => $bill->tin,
            'property_classification' => $bill->property_classification,
            'property_address' => $bill->property_address,
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
