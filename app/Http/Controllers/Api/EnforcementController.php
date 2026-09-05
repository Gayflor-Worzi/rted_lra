<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnforcementVisit;
use App\Models\EvidencePhoto;
use App\Models\PaymentVerification;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\Task;
use App\Services\AuditService;
use App\Services\TaskService;
use App\Services\TaskWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Enforcement hub — field assignments, visits, property discovery and
 * payment claims submitted from the field (mobile) or web.
 */
class EnforcementController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly AuditService $audit,
        private readonly TaskWorkflowService $workflow,
    ) {}

    /**
     * Assignments for the logged-in officer (mobile task list).
     */
    public function myAssignments(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canPermission('tasks.view_own'), 403, 'Missing permission: tasks.view_own');

        $query = Task::where('assigned_to', $user->id)
            ->with('assignedTo:id,full_name')
            ->with('assignedBy:id,full_name')
            ->withCount('engagements')
            ->whereNotIn('status', ['Resolved', 'Closed', 'Paid'])
            ->orderByRaw("CASE tasks.status WHEN 'Escalated' THEN 1 WHEN '30-Day Warning' THEN 2 WHEN '72-Hour Warning' THEN 3 WHEN 'Assigned' THEN 4 WHEN 'Out for Delivery' THEN 5 ELSE 6 END DESC")
            ->orderByDesc('id');

        $rows = $query->paginate($request->query('per_page', 50))->withQueryString();

        // Eliminate N+1: preload all linked bills (incl. soft-deleted) once for this page,
        // along with their staff references, then look them up from a map during transform.
        $billIds = $rows->getCollection()
            ->where('reference_type', 'property_bill')
            ->pluck('reference_id')
            ->filter()
            ->unique()
            ->values();

        $bills = $billIds->isNotEmpty()
            ? PropertyBill::withTrashed()->with(['accountStaff:id,full_name', 'enforcementOfficer:id,full_name'])
                ->whereIn('id', $billIds)->get()->keyBy('id')
            : collect();

        $rows->getCollection()->transform(function (Task $task) use ($bills) {
            $bill = $task->reference_type === 'property_bill'
                ? $bills->get($task->reference_id)
                : null;

            $descriptor = [];
            try {
                $descriptor = $this->workflow->descriptor($task);
            } catch (\Throwable $e) {
                $descriptor = ['previous_status' => $task->previousStatus(), 'current_status' => $task->status, 'stage' => null, 'next_action' => null, 'deadline' => null];
            }

            $data = [
                'id' => $task->id,
                'task_reference' => $task->task_reference,
                'task_type' => $task->task_type,
                'section' => $task->section,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_date' => $task->due_date?->toDateString(),
                'reference_type' => $task->reference_type,
                'reference_id' => $task->reference_id,
                'property_bill_id' => $bill?->id,
                'assigned_to' => $task->assignedTo?->full_name ?? $task->assigned_to,
                'assigned_to_id' => $task->assigned_to,
                'assigned_by' => $task->assignedBy?->full_name ?? $task->assigned_by,
                'assignment_status' => $task->assigned_to ? 'Assigned' : ($task->status === 'Awaiting Assignment' ? 'Pending Assignment' : 'Unassigned'),
                'previous_status' => $descriptor['previous_status'] ?? null,
                'stage' => $descriptor['stage'] ?? null,
                'next_action' => $descriptor['next_action'] ?? null,
                'deadline' => $descriptor['deadline'] ?? null,
                'engagements_count' => $task->engagements_count ?? null,
                'property_bill' => $bill ? [
                    'id' => $bill->id,
                    'bill_number' => $bill->document_number,
                    'document_number' => $bill->document_number,
                    'property_id' => $bill->property_id,
                    'property_address' => $bill->property_address,
                    'taxpayer_name' => $bill->taxpayer_name,
                    'tin' => $bill->tin,
                    'tax_period' => $bill->tax_period,
                    'property_classification' => $bill->property_classification,
                    'property_type' => $bill->property_type,
                    'assessed_value' => $bill->assessed_value,
                    'tax_amount' => $bill->tax_amount,
                    'interest_charged' => $bill->interest_charged,
                    'penalty_charged' => $bill->penalty_charged,
                    'total_tax_due' => $bill->total_tax_due,
                    'outstanding_balance' => $bill->outstanding_balance,
                    'payment_status' => $bill->payment_status,
                    'delivery_status' => $bill->delivery_status,
                    'case_status' => $bill->case_status,
                    'date_logged' => $bill->date_logged?->toDateString(),
                    'recipient_type' => $bill->recipient_type,
                    'recipient_name' => $bill->recipient_name,
                    'recipient_contact' => $bill->recipient_contact,
                    'delivery_date' => $bill->delivery_date?->toDateString(),
                    'thirty_day_notice_date' => $bill->thirty_day_notice_date?->toDateString(),
                    'final_notice_date' => $bill->final_notice_date?->toDateString(),
                    'escalation_stage' => $bill->escalation_stage,
                    'escalation_override_reason' => $bill->escalation_override_reason,
                    'approval_status' => $bill->approval_status,
                    'date_logged' => $bill->date_logged?->toDateString(),
                    'remarks' => $bill->remarks,
                    'account_staff' => $bill->accountStaff?->full_name ?: ($bill->account_staff_id ? "#{$bill->account_staff_id}" : null),
                    'enforcement_officer' => $bill->enforcementOfficer?->full_name ?: ($bill->assigned_enforcement_officer_id ? "#{$bill->assigned_enforcement_officer_id}" : null),
                ] : null,
            ];

            return $data;
        });

        return response()->json(['data' => $rows]);
    }

    /**
     * Officer completes or escalates their assignment.
     */
    public function assignmentAction(Request $request, Task $task)
    {
        $user = $request->user();
        abort_unless($task->assigned_to == $user->id, 403, 'Task not assigned to you.');

        $data = $request->validate([
            'action' => ['required', Rule::in(['completed', 'complete', 'escalate'])],
            'visit_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            if ($data['action'] === 'escalate') {
                $history = $task->transitionTo('Escalated', 'escalate', $user, $data['notes'] ?? null);
            } else {
                $history = $task->transitionTo('Delivered', 'complete', $user, $data['notes'] ?? null);
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($data['action'] === 'complete') {
            $task->recordEngagement('bill_delivered', 'delivered', $data['notes'] ?? 'Assignment completed — bill handed over.', $user, isset($data['visit_date']) ? \Illuminate\Support\Carbon::parse($data['visit_date']) : now());
        } else {
            $task->recordEngagement('follow_up', 'escalated', $data['notes'] ?? 'Assignment escalated by the assigned officer.', $user);
        }

        // Sync bill case status with the task when present.
        if ($task->reference_type === 'property_bill') {
            $bill = PropertyBill::find($task->reference_id);
            if ($bill) {
                $bill->case_status = $data['action'] === 'escalate' ? 'Escalated' : 'Delivered';
                $bill->save();
            }
        }

        return response()->json([
            'data' => ['task_id' => $task->id, 'task_reference' => $task->task_reference, 'status' => $task->status],
            'message' => $data['action'] === 'escalate' ? 'Assignment escalated.' : 'Assignment completed.',
        ]);
    }

    /**
     * Record a field visit (enforcement evidence + status snapshot).
     */
    public function storeVisit(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canPermission('enforcement.record_visit'), 403, 'Missing permission: enforcement.record_visit');

        $data = $request->validate([
            'assignment_id' => 'nullable|integer|exists:tasks,id',
            'task_id' => 'nullable|integer|exists:tasks,id',
            'property_bill_id' => 'nullable|integer|exists:property_bills,id',
            'bill_id' => 'nullable|integer|exists:property_bills,id',
            'status' => 'required|string|max:60',
            'visit_status' => 'nullable|string|max:60',
            'bill_delivery_status' => 'nullable|string|max:60',
            'delivery_status' => 'nullable|string|max:60',
            'notes' => 'nullable|string|max:2000',
            'remarks' => 'nullable|string|max:2000',
            'gps_lat' => 'nullable|numeric',
            'gps_lng' => 'nullable|numeric',
            'gps_coordinate' => 'nullable|string|max:100',
            'gps_accuracy' => 'nullable|numeric|min:0',
            'gps_captured_at' => 'nullable|date',
            'proof_photo' => 'nullable|string',
            'visit_photo' => 'nullable|string',
            'recipient_name' => 'nullable|string|max:255',
            'recipient_contact' => 'nullable|string|max:100',
            'photo_type' => 'nullable|string|max:40',
            // optional on-the-spot payment claim
            'claim_receipt_number' => 'nullable|string|max:80',
            'claim_amount' => 'nullable|numeric|min:0',
            'claim_payment_date' => 'nullable|date',
            'claim_receipt_photo' => 'nullable|string',
            'claim_remarks' => 'nullable|string|max:2000',
            'claim_property_id' => 'nullable|string|max:60',
            'claim_tin' => 'nullable|string|max:40',
            'claim_tax_due_date' => 'nullable|date',
        ]);

        $taskId = $data['assignment_id'] ?? $data['task_id'] ?? null;
        $billId = $data['property_bill_id'] ?? $data['bill_id'] ?? null;

        $bill = $billId ? PropertyBill::find($billId) : ($taskId ? Task::find($taskId)?->reference_type === 'property_bill' ? PropertyBill::find(Task::find($taskId)->reference_id) : null : null);

        if (! $bill && ! $billId) {
            abort(422, 'A bill is required to record a visit.');
        }

        $gps = $data['gps_coordinate'] ?? (isset($data['gps_lat'], $data['gps_lng'])
            ? trim($data['gps_lat'].','.$data['gps_lng'])
            : null);

        $visit = EnforcementVisit::create([
            'task_id' => $taskId,
            'bill_id' => $bill?->id ?? $billId,
            'document_number' => $bill?->document_number,
            'property_id' => $bill?->property_id,
            'officer_id' => $user->id,
            'visit_date' => now()->toDateString(),
            'visit_status' => $data['visit_status'] ?? $data['status'],
            'delivery_status' => $data['delivery_status'] ?? $data['bill_delivery_status'] ?? null,
            'recipient_name' => $data['recipient_name'] ?? null,
            'recipient_contact' => $data['recipient_contact'] ?? null,
            'gps_coordinate' => $gps,
            'gps_accuracy' => $data['gps_accuracy'] ?? null,
            'gps_captured_at' => $data['gps_captured_at'] ?? now(),
            'visit_photo' => $data['visit_photo'] ?? $data['proof_photo'] ?? null,
            'remarks' => $data['remarks'] ?? $data['notes'] ?? null,
            'snapshot_outstanding' => $bill?->outstanding_balance,
            'snapshot_payment_status' => $bill?->payment_status,
            'snapshot_case_status' => $bill?->case_status,
        ]);

        // Evidence photo registry entry when a capture was supplied.
        if ($data['visit_photo'] ?? $data['proof_photo'] ?? null) {
            EvidencePhoto::create([
                'photo_type' => $data['photo_type'] ?? 'PROPERTY_FULL_VIEW',
                'bill_id' => $bill?->id ?? $billId,
                'task_id' => $taskId,
                'visit_id' => $visit->id,
                'property_id' => $bill?->property_id,
                'officer_id' => $user->id,
                'file_path' => $data['visit_photo'] ?? $data['proof_photo'],
                'gps_coordinate' => $gps,
                'captured_at' => $data['gps_captured_at'] ?? now(),
                'remarks' => 'Captured at visit '.$visit->visit_reference.'.',
            ]);
        }

        $this->audit->record($visit, 'enforcement.visit_recorded', $user->id, [], [
            'visit_reference' => $visit->visit_reference,
            'bill_id' => $visit->bill_id,
            'gps_accuracy' => $visit->gps_accuracy,
        ]);

        // Advance task + bill where possible.
        if ($taskId) {
            $task = Task::find($taskId);
            $handedOver = in_array(strtolower((string) ($data['delivery_status'] ?? $data['bill_delivery_status'] ?? '')), ['delivered', 'completed', 'handed over', 'received'], true);

            if ($task && $task->status === 'Assigned') {
                try {
                    $task->transitionTo('Out for Delivery', 'visit', $user, 'Field visit recorded.');
                } catch (\InvalidArgumentException $e) {
                    // Non-blocking — visit is still persisted.
                }
            }

            // A handed-over delivery completes the delivery stage.
            if ($task && $handedOver && $task->status === 'Out for Delivery') {
                try {
                    $task->transitionTo('Delivered', 'delivered', $user, 'Bill handed over during field visit.');
                } catch (\InvalidArgumentException $e) {
                    // Non-blocking — visit is still persisted.
                }
            }

            if ($task) {
                $outcome = $handedOver ? 'delivered' : $this->visitOutcome((string) ($data['visit_status'] ?? $data['status'] ?? ''));
                $task->recordEngagement(
                    $handedOver ? 'bill_delivered' : 'delivery_attempt',
                    $outcome,
                    ($data['remarks'] ?? $data['notes'] ?? null),
                    $user,
                    now(),
                    array_filter(['gps' => $gps, 'recipient' => $data['recipient_name'] ?? null, 'visit' => $visit->visit_reference]),
                );
            }
        }

        if ($bill) {
            $handedOver = in_array(strtolower((string) ($data['delivery_status'] ?? $data['bill_delivery_status'] ?? '')), ['delivered', 'completed', 'handed over', 'received'], true);

            if ($bill->case_status === 'Logged' || $bill->case_status === 'Awaiting Assignment' || $bill->case_status === 'Assigned') {
                $bill->case_status = $handedOver ? 'Delivered' : 'Out for Delivery';
            }
            if ($bill->delivery_status === 'Logged' || ($handedOver && $bill->delivery_status === 'Out for Delivery')) {
                $bill->delivery_status = $handedOver ? 'Delivered' : 'Out for Delivery';
            }
            $bill->save();

            // Stamp the formal delivery/notice trail when the officer reports a hand-over.
            if ($handedOver) {
                if (! $bill->delivery_date) {
                    $bill->delivery_date = now()->toDateString();
                    $bill->save();
                }
            }
        }

        // Optional on-the-spot payment claim (receipt from the field).
        if ($bill && ! empty($data['claim_receipt_number']) && $data['claim_amount'] !== null) {
            $verification = PaymentVerification::create([
                'bill_id' => $bill->id,
                'property_id' => $data['claim_property_id'] ?? $bill->property_id,
                'tin' => $data['claim_tin'] ?? $bill->tin,
                'tax_due_date' => $data['claim_tax_due_date'] ?? null,
                'document_number' => $bill->document_number,
                'receipt_number' => $data['claim_receipt_number'],
                'receipt_bill_number' => $bill->document_number,
                'amount_claimed' => $data['claim_amount'],
                'payment_period' => null,
                'receipt_attachment' => $data['claim_receipt_photo'] ?? $data['visit_photo'] ?? null,
                'match_status' => PaymentVerification::MATCH_PENDING,
                'verification_status' => PaymentVerification::STATUS_PENDING,
            ]);

            $bill->case_status = 'Under Verification';
            $bill->payment_status = 'Payment Claimed';
            $bill->save();

            if ($taskId) {
                $task = Task::find($taskId);
                if ($task && in_array($task->status, ['Assigned', 'Out for Delivery', 'Delivered'], true)) {
                    try {
                        $task->transitionTo('Payment Claimed', 'payment_claim', $user, 'Claimed on the spot during field visit.');
                    } catch (\InvalidArgumentException $e) {
                        // non-blocking
                    }
                }
                if ($task) {
                    $task->recordEngagement('payment_claim', 'claim_submitted', "On-the-spot claim for bill {$bill->document_number} awaiting verification.", $user, now());
                }
            }

            $this->tasks->notifySection('ACCT', 'Payment claim', "On-the-spot claim for bill {$bill->document_number} awaiting verification.", 'payments');
            $this->audit->record($bill, 'payments.claimed_onspot', $user->id, [], ['verification_id' => $verification->id]);
        }

        $this->audit->record($visit, 'enforcement.visit_complete', $user->id, [], ['task_id' => $visit->task_id]);

        return response()->json([
            'data' => [
                'id' => $visit->id,
                'visit_reference' => $visit->visit_reference,
                'task_id' => $visit->task_id,
                'bill_id' => $visit->bill_id,
                'gps_accuracy' => $visit->gps_accuracy,
            ],
            'message' => 'Visit recorded.',
        ], 201);
    }

    /**
     * Field visits recorded by the officer — engagement history for a bill/task.
     * Scoped to the caller's own visits; a wider-view permission is not required.
     */
    public function visitsIndex(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['enforcement.record_visit', 'enforcement.view_assignments']), 403, 'Missing permission.');

        $rows = EnforcementVisit::query()
            ->with('assignedTo:id,full_name')
            ->where('officer_id', $user->id)
            ->when($request->query('bill_id'), fn ($q, $v) => $q->where('bill_id', $v))
            ->when($request->query('task_id'), fn ($q, $v) => $q->where('task_id', $v))
            ->orderByDesc('id')
            ->limit(min((int) $request->query('per_page', 50), 200))
            ->get()
            ->map(fn (EnforcementVisit $v) => [
                'id' => $v->id,
                'visit_reference' => $v->visit_reference,
                'task_id' => $v->task_id,
                'bill_id' => $v->bill_id,
                'document_number' => $v->document_number,
                'property_id' => $v->property_id,
                'visit_date' => $v->visit_date?->toDateString(),
                'visit_status' => $v->visit_status,
                'delivery_status' => $v->delivery_status,
                'recipient_name' => $v->recipient_name,
                'recipient_contact' => $v->recipient_contact,
                'gps_coordinate' => $v->gps_coordinate,
                'gps_accuracy' => $v->gps_accuracy,
                'gps_captured_at' => $v->gps_captured_at?->toISOString(),
                'remarks' => $v->remarks,
                'next_action' => $v->next_action,
                'next_followup_date' => $v->next_followup_date?->toDateString(),
                'snapshot_outstanding' => $v->snapshot_outstanding,
                'snapshot_payment_status' => $v->snapshot_payment_status,
                'snapshot_case_status' => $v->snapshot_case_status,
                'officer' => $v->assignedTo?->full_name ?? null,
                'recorded_at' => $v->created_at?->toISOString(),
            ]);

        return response()->json(['data' => $rows]);
    }

    /**
     * Field discovery of an unregistered property (mobile). Creates a
     * first-class PropertyDiscovery record (ND-... reference) — no property ID
     * or document number is generated here, and no tax bill is produced.
     */
    public function discover(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['enforcement.upload_evidence', 'discovery.create']), 403, 'Missing permission.');

        $data = $request->validate([
            'property_address' => 'required|string|max:255',
            'gps_lat' => 'required|numeric',
            'gps_lng' => 'required|numeric',
            'property_classification' => 'nullable|string|max:100',
            'owner_name' => 'nullable|string|max:255',
            'documents' => 'nullable|array',
        ]);

        $gps = trim($data['gps_lat'].','.$data['gps_lng']);

        $discovery = PropertyDiscovery::create([
            'owner_name' => $data['owner_name'] ?: null,
            'property_address' => $data['property_address'],
            'property_classification' => $data['property_classification'] ?? null,
            'gps_coordinate' => $gps,
            'status' => PropertyDiscovery::STATUS_DISCOVERED,
            'discovered_by' => $user->id,
            'source' => 'field',
            'remarks' => 'Discovery submission by user #'.$user->id.'.',
        ]);

        foreach ($data['documents'] ?? [] as $label => $dataUri) {
            $this->storeDiscoveryPhoto($discovery, is_string($dataUri) ? $dataUri : ($dataUri['data'] ?? null), (string) $label, $user->id, $gps);
        }

        $this->audit->record($discovery, 'discovery.created', $user->id, [], [
            'discovery_reference' => $discovery->discovery_reference,
        ]);

        $this->tasks->notifySection('VAL', 'Property discovery', "Discovery {$discovery->discovery_reference} for {$data['property_address']} is queued for review.", 'discovery');

        return response()->json([
            'data' => ['id' => $discovery->id, 'discovery_reference' => $discovery->discovery_reference, 'status' => $discovery->status],
            'message' => 'Property discovery registered for downstream processing.',
        ], 201);
    }

private function visitOutcome(string $visitStatus): string
    {
        $status = strtolower($visitStatus);
        $map = [
            'delivered' => 'delivered',
            'handed' => 'handed_over',
            'received' => 'received',
            'no answer' => 'no_answer',
            'no access' => 'no_access',
            'nobody' => 'no_answer',
            'refused' => 'refused',
            'vacant' => 'vacant',
            'closed' => 'closed',
            'promise' => 'promised_payment',
            'contacted' => 'contact_made',
            'identified' => 'identified',
            'located' => 'located',
        ];

        foreach ($map as $key => $value) {
            if (str_contains($status, $key)) {
                return $value;
            }
        }

        return 'completed';
    }

    private function storeDiscoveryPhoto(PropertyDiscovery $discovery, ?string $dataUri, string $label, int $userId, string $gps): void
    {
        if (! $dataUri) {
            return;
        }

        $filePath = null;
        $mime = 'image/jpeg';

        if (str_contains($dataUri, 'base64,')) {
            $b64 = Str::after($dataUri, 'base64,');
            if (str_starts_with($dataUri, 'data:')) {
                $mime = Str::between($dataUri, 'data:', ';');
            }
            $bytes = base64_decode(trim($b64), true);
            if ($bytes !== false) {
                $name = 'evidence/'.Str::lower(Str::random(1)).'/'.date('Ymd_His').'_'.Str::random(16).'.'.Str::after($mime, '/');
                \Illuminate\Support\Facades\Storage::disk('local')->put($name, $bytes);
                $filePath = $name;
            }
        }

        EvidencePhoto::create([
            'photo_type' => 'PROPERTY_FULL_VIEW',
            'discovery_id' => $discovery->id,
            'officer_id' => $userId,
            'file_path' => $filePath ?: $dataUri,
            'original_name' => $label,
            'mime' => $mime,
            'gps_coordinate' => $gps,
            'captured_at' => now(),
            'remarks' => $label,
        ]);
    }

    /**
     * Payment claim from the field (mobile receipt submission).
     */
    public function submitReceipt(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canPermission('payments.claim'), 403, 'Missing permission: payments.claim');

        $data = $request->validate([
            'billing_number' => 'required|string|max:80',
            'bill_id' => 'nullable|integer|exists:property_bills,id',
            'amount' => 'required|numeric|min:0',
            'period' => 'nullable|string|max:50',
            'payment_period' => 'nullable|string|max:50',
            'receipt_number' => 'required|string|max:80',
            'receipt_photo' => 'nullable|string',
            'receipt_attachment' => 'nullable|string',
            'property_id' => 'nullable|string|max:60',
            'tin' => 'nullable|string|max:40',
            'tax_due_date' => 'nullable|date',
        ]);

        $bill = PropertyBill::where('document_number', $data['billing_number'])->first()
            ?? (($data['bill_id'] ?? null) ? PropertyBill::find($data['bill_id']) : null);

        if (! $bill) {
            abort(422, 'No bill matches the reference provided.');
        }

        $verification = PaymentVerification::create([
            'bill_id' => $bill->id,
            'claimed_by' => $user->id,
            'property_id' => $data['property_id'] ?? $bill->property_id,
            'tin' => $data['tin'] ?? $bill->tin,
            'tax_due_date' => $data['tax_due_date'] ?? null,
            'document_number' => $bill->document_number,
            'receipt_number' => $data['receipt_number'],
            'receipt_bill_number' => $data['billing_number'],
            'amount_claimed' => $data['amount'],
            'payment_period' => $data['payment_period'] ?? $data['period'] ?? null,
            'receipt_attachment' => $data['receipt_attachment'] ?? $data['receipt_photo'] ?? null,
            'match_status' => PaymentVerification::MATCH_PENDING,
            'verification_status' => PaymentVerification::STATUS_PENDING,
        ]);

        // Mark the bill + task so the Account section can action the queue.
        $bill->update(['case_status' => 'Under Verification', 'payment_status' => 'Payment Claimed']);

        if ($bill->tasks()->exists()) {
            $task = $bill->tasks()->orderByDesc('id')->first();
            $task->recordEngagement('payment_claim', 'claim_submitted', "Payment claim for bill {$bill->document_number} submitted via receipt.", $user);
            if (in_array($task->status, ['Assigned', 'Out for Delivery', 'Delivered', 'Payment Follow-up'], true)) {
                try {
                    $task->transitionTo('Payment Claimed', 'payment_claim', $user, 'Payment claim submitted.');
                    $task->update(['task_id' => null]);
                } catch (\InvalidArgumentException $e) {
                    // non-blocking
                }
            }
        }

        $this->tasks->notifySection('ACCT', 'Payment claim', "Payment claim for bill {$bill->document_number} awaiting verification.", 'payments');

        return response()->json([
            'data' => ['id' => $verification->id, 'bill_id' => $bill->id, 'document_number' => $bill->document_number],
            'message' => 'Payment claim submitted for verification.',
        ], 201);
    }
}
