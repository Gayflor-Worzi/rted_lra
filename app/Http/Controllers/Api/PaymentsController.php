<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ReceiptConflictException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\PropertyBill;
use App\Models\ReceiptFingerprint;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BillScopeService;
use App\Services\ReceiptDuplicationService;
use App\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Payment verification queue — Account & Records reviews field claims,
 * compares receipts, then confirms (verified) or rejects.
 */
class PaymentsController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly ReceiptDuplicationService $duplicates,
        private readonly AuditService $audit,
    ) {}

    /**
     * Verification queue of pending payment claims (scoped).
     */
    public function queue(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['payments.view_queue', 'payments.view_history']), 403, 'Missing permission: payments.view_queue');

        $status = $request->query('status');
        $blocked = ['Pending', 'Confirmed', 'Rejected', 'Exception'];

        $query = PaymentVerification::query()
            ->with(['bill:id,document_number,property_id,taxpayer_name', 'verifier:id,full_name']);

        if ($status && in_array($status, $blocked, true)) {
            $query->where('verification_status', $status);
        } elseif ($status === 'all') {
            // no filter
        } else {
            $query->where('verification_status', 'Pending');
        }

        if ($q = $request->query('q')) {
            $query->where(function ($b) use ($q) {
                $b->where('document_number', 'like', like_term($q))
                    ->orWhere('receipt_number', 'like', like_term($q))
                    ->orWhere('property_id', 'like', like_term($q));
            });
        }

        // Always apply data scope — even desk reviewers see only their authorized section/team.
        // A user with payments.verify/reject may not enumerate the entire country's claims.
        $this->applyScope($query, $user);

        $rows = $query->orderByDesc('id')->paginate($request->query('per_page', 20))->withQueryString();
        $rows->getCollection()->transform(fn ($v) => $this->present($v));

        return response()->json(['data' => $rows]);
    }

    /**
     * Officer-facing history of claims (own).
     */
    public function history(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canPermission('payments.view_history'), 403, 'Missing permission: payments.view_history');

        $query = PaymentVerification::query()
            ->with(['bill:id,document_number,property_id', 'verifier:id,full_name'])
            ->whereNotNull('verified_at');

        // Always apply data scope — even users with payments.verify/reject must remain
        // within their authorized section/team scope. No org-wide enumeration.
        $this->applyScope($query, $user);

        $rows = $query->orderByDesc('id')->paginate($request->query('per_page', 20))->withQueryString();
        $rows->getCollection()->transform(fn ($v) => $this->present($v));

        return response()->json(['data' => $rows]);
    }

    /**
     * Receipt detail + attached photo for a payment verification claim.
     */
    public function receipt(Request $request, int $id)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['payments.view_queue', 'payments.view_history', 'payments.verify', 'payments.reject']), 403, 'Missing permission.');

        $v = PaymentVerification::with('bill:id,document_number,property_id,assigned_enforcement_officer_id,account_staff_id')
            ->findOrFail($id);

        // Always scope the receipt: the user must be authorized for this bill's
        // task/property assignment, regardless of their verify/reject permissions.
        $scoped = PaymentVerification::whereKey($v->id);
        $this->applyScope($scoped, $user);
        abort_unless($scoped->exists(), 403, 'You do not have access to this receipt.');

        return response()->json([
            'data' => [
                'id' => $v->id,
                'document_number' => $v->document_number,
                'property_id' => $v->property_id,
                'tin' => $v->tin,
                'tax_due_date' => $v->tax_due_date?->toDateString(),
                'receipt_number' => $v->receipt_number,
                'receipt_bill_number' => $v->receipt_bill_number,
                'amount_claimed' => $v->amount_claimed,
                'payment_period' => $v->payment_period,
                'receipt_date' => $v->receipt_date?->toDateString(),
                'receipt_attachment' => $v->receipt_attachment,
            ],
        ]);
    }

    /**
     * Receipt photo bytes for a payment verification claim. The stored
     * attachment is a relative local-disk path (decoded from the field
     * submission's base64 payload), so it is streamed here — always scoped to
     * the requesting user exactly like the receipt detail endpoint.
     */
    public function receiptFile(Request $request, int $id)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['payments.view_queue', 'payments.view_history', 'payments.verify', 'payments.reject']), 403, 'Missing permission.');

        $v = PaymentVerification::findOrFail($id);

        $scoped = PaymentVerification::whereKey($v->id);
        $this->applyScope($scoped, $user);
        abort_unless($scoped->exists(), 403, 'You do not have access to this receipt.');

        $path = $v->receipt_attachment;
        abort_unless(
            $path
            && ! str_contains($path, 'base64,')
            && ! str_starts_with($path, 'http')
            && str_starts_with($path, 'evidence/')
            && ! str_contains($path, '..')
            && Storage::disk('local')->exists($path),
            404,
            'Receipt photo not found.',
        );

        return Storage::disk('local')->response($path);
    }

    /**
     * Confirm a payment claim — creates a verified Payment and marks the bill paid.
     */
    public function confirm(Request $request, PaymentVerification $verification)
    {
        $user = $request->user();
        abort_unless($user->canPermission('payments.verify'), 403, 'Missing permission: payments.verify');
        abort_if($verification->claimed_by === $user->id, 422, 'Separation of duties: you may not verify a payment claim you submitted.');

        $data = $request->validate([
            'verified_amount' => 'nullable|numeric|min:0',
            'source_reference' => 'nullable|string|max:100',
            'remarks' => 'nullable|string|max:2000',
        ]);

        // The whole confirmation runs in one transaction that re-locks both the
        // claim and its bill, re-checks the receipt's uniqueness and re-applies
        // the actor's data scope — so a claim can neither be approved out of
        // scope nor alongside a competing claim for the same receipt.
        $alreadyConfirmed = false;

        try {
            $confirmed = DB::transaction(function () use ($verification, $user, $data, &$alreadyConfirmed) {
                $verification = PaymentVerification::whereKey($verification->id)->lockForUpdate()->firstOrFail();

                $this->assertWithinScope($user, $verification);

                if ($verification->verification_status === PaymentVerification::STATUS_CONFIRMED) {
                    $alreadyConfirmed = true;

                    return $verification->fresh(['bill:id,document_number,property_id', 'verifier:id,full_name']);
                }
                abort_if($verification->verification_status === 'Rejected', 422, 'Claim already rejected.');

                $bill = PropertyBill::query()->whereKey($verification->bill_id)->lockForUpdate()->first();
                abort_unless($bill, 404, 'Bill not found.');
                abort_unless(
                    $verification->document_number === $bill->document_number
                    && (! $verification->property_id || $verification->property_id === $bill->property_id),
                    422,
                    'The payment claim does not match the selected bill and property.',
                );

                $task = $this->resolveClaimTask($verification);

                $assess = $this->duplicates->assess([
                    'receipt_number' => $verification->receipt_number,
                    'amount' => $verification->amount_claimed,
                    'period' => $verification->payment_period,
                    'bill_id' => $verification->bill_id,
                    'property_id' => $verification->property_id,
                ], excludeClaimId: $verification->id);

                if ($assess['hard_block']) {
                    throw ReceiptConflictException::make((string) $assess['block_reason'], [
                        'claim_id' => $verification->id,
                        'receipt_number' => $assess['normalized_receipt_number'],
                        'fingerprint' => $assess['fingerprint'],
                        'bill_id' => $verification->bill_id,
                    ]);
                }

                $amount = $data['verified_amount'] ?? $verification->amount_claimed;
                $previousStatus = $verification->verification_status;

                $verification->update([
                    'match_status' => PaymentVerification::MATCH_MATCH,
                    'verified_amount' => $amount,
                    'source_reference' => $data['source_reference'] ?? null,
                    'verification_status' => PaymentVerification::STATUS_CONFIRMED,
                    'verified_by' => $user->id,
                    'verified_at' => now(),
                    'remarks' => $data['remarks'] ?? null,
                ]);

                Payment::create([
                    'bill_id' => $bill->id,
                    'document_number' => $bill->document_number,
                    'amount' => $amount,
                    'payment_period' => $verification->payment_period,
                    'receipt_number' => $verification->receipt_number,
                    'source_reference' => $verification->source_reference,
                    'verified_by' => $user->id,
                    'verified_at' => now(),
                    'remarks' => $data['remarks'] ?? null,
                ]);

                // Keep the fingerprint reserved so the receipt is permanently tied
                // to this property once approved.
                $this->reserveFingerprint($verification, PaymentVerification::STATUS_CONFIRMED);

                $bill->recalculateOutstanding();

                // Cross-module sync (§13): once the ledger is settled the bill's
                // operational case status follows the verified payment state so the
                // dashboard and the underlying record agree everywhere.
                $bill->update([
                    'payment_status' => $bill->payment_status,
                    'case_status' => (float) $bill->outstanding_balance <= 0 ? 'Resolved' : 'Payment Follow-up',
                ]);

                // Advance the task when applicable.
                if ($task) {
                    if (in_array($task->status, ['Assigned', 'Out for Delivery', 'Delivered', 'Payment Follow-up'], true)) {
                        $task->transitionTo('Payment Claimed', 'payment_claim', $user, 'Previously submitted payment claim linked to this task.');
                    }
                    if ($task->status === 'Payment Claimed') {
                        $task->transitionTo('Verification Pending', 'verification_started', $user, 'Payment claim entered verification.');
                    }
                    if ($task->status === 'Verification Pending') {
                        $task->transitionTo('Payment Verification', 'verification_started', $user, 'Payment verification is in progress.');
                    }

                    if ((float) $bill->outstanding_balance <= 0) {
                        $task->transitionTo('Paid', 'verified', $user, 'Payment verified; bill settled in full.');
                    } else {
                        $task->transitionTo('Payment Follow-up', 'verified', $user, 'Payment verified; outstanding balance remains.');
                    }

                    $task->recordEngagement(
                        'verification',
                        'confirmed',
                        "Payment claim confirmed — {$bill->document_number}, amount {$amount}.",
                        $user,
                    );

                    if ((float) $bill->outstanding_balance <= 0) {
                        $task->recordEngagement('payment_confirmed', 'paid', 'Bill settled in full.', $user);
                    }
                }

                $this->audit->record($verification, 'payment.claim_confirmed', $user->id, [
                    'verification_status' => $previousStatus,
                ], [
                    'verification_status' => PaymentVerification::STATUS_CONFIRMED,
                    'bill_id' => $bill->id,
                    'task_id' => $task?->id,
                    'verified_amount' => $amount,
                    'task_status' => $task?->fresh()->status,
                ]);

                return $verification->fresh(['bill:id,document_number,property_id', 'verifier:id,full_name']);
            });
        } catch (ReceiptConflictException $e) {
            $this->audit->record($verification, 'receipt.conflict_confirm_blocked', $user->id, [], $e->context);

            abort(422, $e->getMessage());
        }

        if (! $alreadyConfirmed) {
            $this->tasks->notifySection('ENF', 'Payment verified', "Payment for bill {$confirmed->document_number} verified.", 'payments');
        }

        return response()->json([
            'data' => $this->present($confirmed),
            'message' => $alreadyConfirmed ? 'Payment was already confirmed.' : 'Payment confirmed.',
        ]);
    }

    /**
     * Reject a payment claim.
     */
    public function reject(Request $request, PaymentVerification $verification)
    {
        $user = $request->user();
        abort_unless($user->canPermission('payments.reject'), 403, 'Missing permission: payments.reject');
        abort_if($verification->claimed_by === $user->id, 422, 'Separation of duties: you may not reject a payment claim you submitted.');

        $data = $request->validate([
            'reason' => 'required|string|max:2000',
            'mismatch' => 'nullable|boolean',
        ]);

        $rejected = DB::transaction(function () use ($verification, $user, $data) {
            $verification = PaymentVerification::whereKey($verification->id)->lockForUpdate()->firstOrFail();

            $this->assertWithinScope($user, $verification);

            abort_if($verification->verification_status === 'Rejected', 422, 'Claim already rejected.');
            abort_if($verification->verification_status === 'Confirmed', 422, 'Claim already confirmed.');

            $bill = PropertyBill::query()->whereKey($verification->bill_id)->lockForUpdate()->first();
            abort_unless($bill, 404, 'Bill not found.');
            abort_unless(
                $verification->document_number === $bill->document_number
                && (! $verification->property_id || $verification->property_id === $bill->property_id),
                422,
                'The payment claim does not match the selected bill and property.',
            );
            $task = $this->resolveClaimTask($verification);
            $previousStatus = $verification->verification_status;

            $verification->update([
                'verification_status' => PaymentVerification::STATUS_REJECTED,
                'match_status' => ($data['mismatch'] ?? false) ? PaymentVerification::MATCH_MISMATCH : $verification->match_status,
                'rejection_reason' => $data['reason'],
                'verified_by' => $user->id,
                'verified_at' => now(),
            ]);

            // Release the fingerprint so a legitimately corrected resubmission is
            // possible; an approved receipt keeps its reservation permanently.
            ReceiptFingerprint::where('claim_id', $verification->id)->delete();

            // Keep the ledger single-source: outstanding always equals
            // total_tax_due minus verified payments; the rejected state is an
            // explicit over-ride signal above the live balance.
            $bill->recalculateOutstanding();
            $bill->update(['case_status' => 'Payment Follow-up', 'payment_status' => 'Payment Rejected']);

            if ($task) {
                if (in_array($task->status, ['Assigned', 'Out for Delivery', 'Delivered', 'Payment Follow-up'], true)) {
                    $task->transitionTo('Payment Claimed', 'payment_claim', $user, 'Previously submitted payment claim linked to this task.');
                }
                $task->transitionTo('Payment Rejected', 'rejected', $user, "Payment claim rejected: {$data['reason']}");
                $task->recordEngagement('verification', 'rejected', "Payment claim rejected: {$data['reason']}", $user);
            }

            $this->audit->record($verification, 'payment.claim_rejected', $user->id, [
                'verification_status' => $previousStatus,
            ], [
                'verification_status' => PaymentVerification::STATUS_REJECTED,
                'bill_id' => $bill->id,
                'task_id' => $task?->id,
                'task_status' => $task?->fresh()->status,
                'reason' => $data['reason'],
            ]);

            return $verification->fresh(['bill:id,document_number', 'verifier:id,full_name']);
        });

        $this->tasks->notifySection('ENF', 'Payment rejected', "Payment claim for bill {$rejected->bill?->document_number} rejected: {$data['reason']}", 'payments');

        return response()->json([
            'data' => $this->present($rejected),
            'message' => 'Payment claim rejected.',
        ]);
    }

    /**
     * Pre-submission duplicate check for field claims. Returns only the minimum
     * conflict information (property reference + claim status) — never the other
     * taxpayer's records. The authoritative check is always the server-side
     * submission/confirmation path; this endpoint only helps the officer avoid a
     * rejected claim up front.
     */
    public function duplicateCheck(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['payments.claim', 'payments.verify', 'payments.reject', 'payments.view_queue']), 403, 'Missing permission.');

        $data = $request->validate([
            'receipt_number' => 'required|string|max:80',
            'amount' => 'required|numeric|min:0',
            'period' => 'nullable|string|max:50',
            'bill_id' => 'nullable|integer|exists:property_bills,id',
            'property_id' => 'nullable|string|max:60',
        ]);

        return response()->json([
            'data' => $this->duplicates->assess($data),
        ]);
    }

    private function resolveClaimTask(PaymentVerification $verification): ?Task
    {
        $claimableStatuses = [
            'Assigned',
            'Out for Delivery',
            'Delivered',
            'Payment Follow-up',
            'Payment Claimed',
            'Verification Pending',
            'Payment Verification',
        ];

        if ($verification->task_id) {
            $task = Task::query()->whereKey($verification->task_id)->lockForUpdate()->first();
            abort_unless(
                $task
                && $verification->claimed_by
                && $task->reference_type === 'property_bill'
                && (int) $task->reference_id === (int) $verification->bill_id
                && (int) $task->assigned_to === (int) $verification->claimed_by
                && in_array($task->status, $claimableStatuses, true),
                422,
                'The payment claim is not linked to an active task assigned to its claimant.',
            );

            return $task;
        }

        if (! $verification->claimed_by) {
            return null;
        }

        $tasks = Task::query()
            ->where('reference_type', 'property_bill')
            ->where('reference_id', $verification->bill_id)
            ->where('assigned_to', $verification->claimed_by)
            ->whereIn('status', $claimableStatuses)
            ->lockForUpdate()
            ->get();

        abort_if($tasks->count() > 1, 409, 'The payment claim matches multiple active tasks and cannot be safely associated.');

        if ($tasks->isEmpty()) {
            $claimant = User::find($verification->claimed_by);
            abort_unless(
                $claimant?->isSystemAdministrator(),
                422,
                'No active task assigned to the claimant could be found for this bill.',
            );

            return null;
        }

        $task = $tasks->sole();
        $verification->update(['task_id' => $task->id]);

        return $task;
    }

    private function present(PaymentVerification $v): array
    {
        return [
            'id' => $v->id,
            'bill_id' => $v->bill_id,
            'document_number' => $v->document_number,
            'property_id' => $v->property_id,
            'tin' => $v->tin,
            'tax_due_date' => $v->tax_due_date?->toDateString(),
            'receipt_number' => $v->receipt_number,
            'amount_claimed' => $v->amount_claimed,
            'verified_amount' => $v->verified_amount,
            'payment_period' => $v->payment_period,
            'receipt_date' => $v->receipt_date?->toDateString(),
            'match_status' => $v->match_status,
            'verification_status' => $v->verification_status,
            'rejection_reason' => $v->rejection_reason,
            'remarks' => $v->remarks,
            'verified_by' => $v->relationLoaded('verifier') ? $v->verifier?->full_name : $v->verified_by,
            'verified_at' => $v->verified_at?->toISOString(),
            'created_at' => $v->created_at?->toISOString(),
        ];
    }

    /**
     * Apply the user's record scope to a claim query. Delegates to the single
     * canonical bill-scope service so every payment surface (queue, history,
     * receipt, confirm, reject) agrees with the bill register and dashboards.
     */
    private function applyScope($query, User $user): void
    {
        $ids = BillScopeService::visibleBillIds($user);

        if ($ids === null) {
            return; // unrestricted (system/division scopes, Account Manager)
        }

        $query->whereIn('bill_id', $ids);
    }

    /**
     * Assert a single verification claim is inside the actor's record scope,
     * aborting with 403 otherwise. This is the IDOR guard that applies to the
     * mutating paths (confirm/reject) just as it does to the read paths.
     */
    private function assertWithinScope(User $user, PaymentVerification $verification): void
    {
        $ids = BillScopeService::visibleBillIds($user);

        if ($ids === null) {
            return;
        }

        abort_unless(in_array($verification->bill_id, $ids, true), 403, 'You do not have access to this claim.');
    }

    /**
     * Create or refresh the write-time fingerprint reservation for a claim.
     * Called when a claim is created (submit) and when it settles (confirm).
     */
    private function reserveFingerprint(PaymentVerification $verification, string $status): ReceiptFingerprint
    {
        $fingerprint = $verification->receipt_fingerprint
            ?? ReceiptDuplicationService::fingerprint(
                $verification->receipt_number,
                $verification->amount_claimed,
                $verification->payment_period,
            );

        return ReceiptFingerprint::updateOrCreate(
            ['fingerprint' => $fingerprint],
            [
                'claim_id' => $verification->id,
                'bill_id' => $verification->bill_id,
                'property_id' => $verification->property_id,
                'receipt_number' => ReceiptDuplicationService::normalizeReceiptNumber($verification->receipt_number),
                'amount' => $verification->amount_claimed,
                'payment_period' => $verification->payment_period ? ReceiptDuplicationService::normalizePeriod($verification->payment_period) : null,
                'verification_status' => $status,
            ],
        );
    }
}
