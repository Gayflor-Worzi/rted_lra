<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\PropertyBill;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Payment verification queue — Account & Records reviews field claims,
 * compares receipts, then confirms (verified) or rejects.
 */
class PaymentsController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
    ) {
    }

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

        $isDeskReviewer = $user->canPermission('payments.verify') || $user->canPermission('payments.reject');
        if (!$isDeskReviewer) {
            $this->applyScope($query, $user);
        }

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
        abort_unless($user->hasAnyPermission(['payments.view_queue', 'payments.view_history', 'payments.verify', 'payments.reject']), 403, 'Missing permission: payments.view_queue');

        $v = PaymentVerification::with('bill:id,document_number')
            ->findOrFail($id);

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
     * Confirm a payment claim — creates a verified Payment and marks the bill paid.
     */
    public function confirm(Request $request, PaymentVerification $verification)
    {
        $user = $request->user();
        abort_unless($user->canPermission('payments.verify'), 403, 'Missing permission: payments.verify');
        abort_if($verification->claimed_by === $user->id, 422, 'Separation of duties: you may not verify a payment claim you submitted.');

        abort_if($verification->verification_status === 'Confirmed', 422, 'Claim already confirmed.');
        abort_if($verification->verification_status === 'Rejected', 422, 'Claim already rejected.');

        $data = $request->validate([
            'verified_amount' => 'nullable|numeric|min:0',
            'litas_reference' => 'nullable|string|max:100',
            'remarks' => 'nullable|string|max:2000',
        ]);

        $amount = $data['verified_amount'] ?? $verification->amount_claimed;
        $bill = PropertyBill::find($verification->bill_id);

        $verification->update([
            'match_status' => PaymentVerification::MATCH_MATCH,
            'verified_amount' => $amount,
            'litas_reference' => $data['litas_reference'] ?? null,
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
            'litas_reference' => $verification->litas_reference,
            'verified_by' => $user->id,
            'verified_at' => now(),
            'remarks' => $data['remarks'] ?? null,
        ]);

        $bill->recalculateOutstanding();

        // Cross-module sync (§13): once the ledger is settled the bill's
        // operational case status follows the verified payment state so the
        // dashboard and the underlying record agree everywhere.
        $bill->update([
            'payment_status' => $bill->payment_status,
            'case_status' => (float) $bill->outstanding_balance <= 0 ? 'Resolved' : 'Payment Follow-up',
        ]);

        // Advance the task when applicable.
        if ($verification->task_id) {
            $task = Task::find($verification->task_id);
            if ($task) {
                try {
                    $task->transitionTo('Paid', 'verified', $user, 'Payment verified.');
                } catch (\InvalidArgumentException $e) {
                    $task->forceTransition('Closed', 'confirmed', $user, 'Payment verified — task closed directly.');
                }

                $task->recordEngagement(
                    'verification',
                    'confirmed',
                    "Payment claim confirmed — LITAS {$bill->document_number}, amount {$amount}.",
                    $user,
                );

                if ((float) $bill->outstanding_balance <= 0) {
                    $task->recordEngagement('payment_confirmed', 'paid', 'Bill settled in full.', $user);
                }
            }
        }

        $this->tasks->notifySection('ENF', 'Payment verified', "Payment for bill {$bill->document_number} verified.", 'payments');

        return response()->json([
            'data' => $this->present($verification->fresh(['bill:id,document_number,property_id', 'verifier:id,full_name'])),
            'message' => 'Payment confirmed.',
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

        abort_if($verification->verification_status === 'Rejected', 422, 'Claim already rejected.');
        abort_if($verification->verification_status === 'Confirmed', 422, 'Claim already confirmed.');

        $data = $request->validate([
            'reason' => 'required|string|max:2000',
            'mismatch' => 'nullable|boolean',
        ]);

        $verification->update([
            'verification_status' => PaymentVerification::STATUS_REJECTED,
            'match_status' => ($data['mismatch'] ?? false) ? PaymentVerification::MATCH_MISMATCH : $verification->match_status,
            'rejection_reason' => $data['reason'],
            'verified_by' => $user->id,
            'verified_at' => now(),
        ]);

        $bill = $verification->bill;
        if ($bill) {
            // Keep the ledger single-source: outstanding always equals
            // total_tax_due minus verified payments; the rejected state is an
            // explicit over-ride signal above the live balance.
            $bill->recalculateOutstanding();
            $bill->update(['case_status' => 'Payment Follow-up', 'payment_status' => 'Payment Rejected']);
        }

        if ($verification->task_id) {
            $task = Task::find($verification->task_id);
            if ($task) {
                $task->recordEngagement('verification', 'rejected', "Payment claim rejected: {$data['reason']}", $user);

                try {
                    $task->transitionTo('Payment Rejected', 'rejected', $user, "Payment claim rejected: {$data['reason']}");
                } catch (\InvalidArgumentException $e) {
                    $task->forceTransition('Payment Follow-up', 'rejected', $user, "Payment claim rejected — returned to follow-up: {$data['reason']}");
                }
            }
        }

        $this->tasks->notifySection('ENF', 'Payment rejected', "Payment claim for bill {$bill?->document_number} rejected: {$data['reason']}", 'payments');

        return response()->json([
            'data' => $this->present($verification->fresh(['bill:id,document_number', 'verifier:id,full_name'])),
            'message' => 'Payment claim rejected.',
        ]);
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

    private function applyScope($query, $user): void
    {
        $scope = $user->scopeLevel();

        if (in_array($scope, ['system', 'division'], true)) {
            return;
        }

        $q = PropertyBill::query()->select('id');

        if ($scope === 'own') {
            $q->where('assigned_enforcement_officer_id', $user->id)->orWhere('account_staff_id', $user->id);
        } elseif ($scope === 'team') {
            $q->whereIn('assigned_enforcement_officer_id', function ($s) use ($user) {
                $s->select('id')->from('users')->where('supervisor_id', $user->id);
            })->orWhere('account_staff_id', $user->id);
        } else { // section
            $q->whereIn('assigned_enforcement_officer_id', function ($s) use ($user) {
                $s->select('id')->from('users')->where('section_id', $user->section_id)->where('is_active', true);
            })->orWhere('account_staff_id', $user->id);
        }

        $query->whereIn('bill_id', $q->pluck('id')->all());
    }
}
