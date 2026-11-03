<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PaymentVerification;
use App\Models\PropertyBill;
use App\Models\SyncTransaction;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ReceiptDuplicationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Payment-verification hardening:
 *  - receipts already claimed/approved for one property cannot be claimed again
 *  - confirm/reject/read share the same record scope as the queue
 *  - submit is atomic (fingerprint reservation under a unique constraint)
 *  - the duplicate-check endpoint leaks no taxpayer PII
 *  - blocked attempts land on the hash-chained audit trail
 */
class PaymentVerificationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function officer(): User
    {
        return User::where('email', 'enf.officer@test.lra')->firstOrFail();
    }

    private function accountManager(): User
    {
        return User::where('email', 'account.manager@test.lra')->firstOrFail();
    }

    private function foreignAccountOfficer(): User
    {
        return User::where('email', 'account.officer@test.lra')->firstOrFail();
    }

    private function bill(string $document, string $property, User $officer, float $total = 5000.00): PropertyBill
    {
        $bill = PropertyBill::create([
            'document_number' => $document,
            'property_id' => $property,
            'taxpayer_name' => "Taxpayer {$property}",
            'tin' => "TIN-{$property}",
            'property_address' => "Address {$property}",
            'date_logged' => '2025-01-15',
            'total_tax_due' => $total,
            'outstanding_balance' => $total,
            'tax_amount' => $total,
            'payment_status' => 'Unpaid',
            'case_status' => 'Logged',
        ]);

        Task::create([
            'task_type' => 'Enforcement',
            'section' => 'Enforcement',
            'reference_type' => 'property_bill',
            'reference_id' => $bill->id,
            'assigned_to' => $officer->id,
            'assigned_by' => $officer->id,
            'priority' => 'Medium',
            'status' => 'Payment Follow-up',
        ]);

        return $bill;
    }

    private function submitArray(string $document, string $receipt, float $amount, string $period = '2025'): array
    {
        return [
            'billing_number' => $document,
            'amount' => $amount,
            'payment_period' => $period,
            'receipt_number' => $receipt,
            'payment_date' => '2025-03-10',
        ];
    }

    private function submitClaim(PropertyBill $bill, string $receipt = 'REC-9001', float $amount = 2500.00): int
    {
        $this->postJson('/api/v1/enforcement/submit-receipt', $this->submitArray($bill->document_number, $receipt, $amount))
            ->assertStatus(201)
            ->assertJsonPath('message', 'Payment claim submitted for verification.');

        return PaymentVerification::where('bill_id', $bill->id)->latest('id')->value('id');
    }

    public function test_officer_can_submit_and_fingerprint_is_reserved(): void
    {
        Sanctum::actingAs($this->officer());
        $bill = $this->bill('DOC-1001', 'P-0001', $this->officer());

        $claimId = $this->submitClaim($bill);

        $claim = PaymentVerification::findOrFail($claimId);
        $this->assertSame('Pending', $claim->verification_status);
        $this->assertNotNull($claim->task_id);
        $this->assertSame('Payment Claimed', Task::findOrFail($claim->task_id)->status);
        $this->assertNotEmpty($claim->receipt_fingerprint);

        $this->assertDatabaseHas('receipt_fingerprints', [
            'fingerprint' => $claim->receipt_fingerprint,
            'claim_id' => $claim->id,
            'bill_id' => $bill->id,
        ]);
    }

    public function test_receipt_offline_sync_replay_creates_one_pending_claim(): void
    {
        $officer = $this->officer();
        Sanctum::actingAs($officer);
        $bill = $this->bill('DOC-OFFLINE-RECEIPT', 'P-OFFLINE-RECEIPT', $officer);
        $payload = $this->submitArray($bill->document_number, 'REC-OFFLINE-1', 1200.00)
            + [
                'bill_id' => $bill->id,
                'property_id' => $bill->property_id,
                'tin' => $bill->tin,
                'local_transaction_id' => 'MOB-20261103-OFFLINE-RECEIPT',
            ];

        $first = $this->postJson('/api/v1/enforcement/submit-receipt', $payload)
            ->assertCreated()
            ->assertJsonPath('data.bill_id', $bill->id);
        $replay = $this->postJson('/api/v1/enforcement/submit-receipt', $payload)
            ->assertOk()
            ->assertJsonPath('data.replayed', true);

        $claimId = $first->json('data.id');
        $this->assertSame($claimId, $replay->json('data.id'));
        $this->assertSame(1, PaymentVerification::where('bill_id', $bill->id)->count());
        $this->assertSame(1, SyncTransaction::where('user_id', $officer->id)
            ->where('local_transaction_id', 'MOB-20261103-OFFLINE-RECEIPT')
            ->where('entity_type', 'receipt')
            ->count());
        $this->assertSame('Pending', PaymentVerification::findOrFail($claimId)->verification_status);
        $this->assertSame('Payment Claimed', Task::where('reference_id', $bill->id)->value('status'));
        $this->assertSame(0, $bill->payments()->count());
    }

    public function test_confirm_advances_the_linked_task_and_is_idempotent(): void
    {
        $officer = $this->officer();
        Sanctum::actingAs($officer);
        $bill = $this->bill('DOC-PAY-1', 'P-PAY-1', $officer, 2500.00);
        $claimId = $this->submitClaim($bill, 'REC-PAY-1', 2500.00);
        $claim = PaymentVerification::findOrFail($claimId);
        $task = Task::findOrFail($claim->task_id);

        Sanctum::actingAs($this->accountManager());
        $this->postJson("/api/v1/payments/verifications/{$claimId}/confirm", ['verified_amount' => 2500.00])
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'Confirmed');

        $this->assertSame('Paid', $task->fresh()->status);
        $this->assertSame(1, $task->history()->where('to_status', 'Paid')->count());
        $this->assertSame(1, $bill->payments()->count());
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => PaymentVerification::class,
            'auditable_id' => $claimId,
            'action' => 'payment.claim_confirmed',
        ]);

        $this->postJson("/api/v1/payments/verifications/{$claimId}/confirm", ['verified_amount' => 2500.00])
            ->assertOk()
            ->assertJsonPath('message', 'Payment was already confirmed.');

        $this->assertSame(1, $bill->payments()->count());
        $this->assertSame(1, $task->history()->where('to_status', 'Paid')->count());
    }

    public function test_partial_confirmation_leaves_the_task_in_payment_follow_up(): void
    {
        $officer = $this->officer();
        Sanctum::actingAs($officer);
        $bill = $this->bill('DOC-PAY-2', 'P-PAY-2', $officer, 5000.00);
        $claimId = $this->submitClaim($bill, 'REC-PAY-2', 2500.00);
        $taskId = PaymentVerification::findOrFail($claimId)->task_id;

        Sanctum::actingAs($this->accountManager());
        $this->postJson("/api/v1/payments/verifications/{$claimId}/confirm", ['verified_amount' => 2500.00])
            ->assertOk();

        $this->assertSame('Payment Follow-up', Task::findOrFail($taskId)->status);
        $this->assertSame('Partially Paid', $bill->fresh()->payment_status);
        $this->assertSame(2500.0, (float) $bill->fresh()->outstanding_balance);
    }

    public function test_pending_claim_does_not_create_payment_or_complete_its_task(): void
    {
        Sanctum::actingAs($this->officer());
        $bill = $this->bill('DOC-PAY-3', 'P-PAY-3', $this->officer());
        $claimId = $this->submitClaim($bill, 'REC-PAY-3');
        $taskId = PaymentVerification::findOrFail($claimId)->task_id;

        $this->assertSame('Payment Claimed', Task::findOrFail($taskId)->status);
        $this->assertSame('Pending', PaymentVerification::findOrFail($claimId)->verification_status);
        $this->assertSame(0, $bill->payments()->count());
        $this->assertNotSame('Paid', Task::findOrFail($taskId)->status);
    }

    public function test_rejected_claim_does_not_create_payment_and_rejects_its_task(): void
    {
        Sanctum::actingAs($this->officer());
        $bill = $this->bill('DOC-PAY-4', 'P-PAY-4', $this->officer());
        $claimId = $this->submitClaim($bill, 'REC-PAY-4');
        $taskId = PaymentVerification::findOrFail($claimId)->task_id;

        Sanctum::actingAs($this->accountManager());
        $this->postJson("/api/v1/payments/verifications/{$claimId}/reject", ['reason' => 'Receipt mismatch.'])
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'Rejected');

        $this->assertSame('Payment Rejected', Task::findOrFail($taskId)->status);
        $this->assertSame(0, $bill->payments()->count());
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => PaymentVerification::class,
            'auditable_id' => $claimId,
            'action' => 'payment.claim_rejected',
        ]);
    }

    public function test_claim_selects_an_eligible_task_for_its_own_bill_when_multiple_tasks_exist(): void
    {
        $officer = $this->officer();
        Sanctum::actingAs($officer);
        $bill = $this->bill('DOC-PAY-5', 'P-PAY-5', $officer);
        $originalTask = $bill->tasks()->firstOrFail();
        $selectedTask = Task::create([
            'task_type' => 'Payment Follow-up',
            'section' => 'Enforcement',
            'reference_type' => 'property_bill',
            'reference_id' => $bill->id,
            'assigned_to' => $officer->id,
            'assigned_by' => $officer->id,
            'priority' => 'Medium',
            'status' => 'Payment Follow-up',
        ]);

        $claimId = $this->submitClaim($bill, 'REC-PAY-5');
        $claim = PaymentVerification::findOrFail($claimId);

        $this->assertSame($selectedTask->id, $claim->task_id);
        $this->assertSame('Payment Claimed', $selectedTask->fresh()->status);
        $this->assertSame('Payment Follow-up', $originalTask->fresh()->status);
    }

    public function test_confirmation_rejects_a_task_linked_to_a_different_bill(): void
    {
        $officer = $this->officer();
        Sanctum::actingAs($officer);
        $billA = $this->bill('DOC-PAY-6A', 'P-PAY-6A', $officer);
        $billB = $this->bill('DOC-PAY-6B', 'P-PAY-6B', $officer);
        $claimId = $this->submitClaim($billA, 'REC-PAY-6');
        $taskB = $billB->tasks()->firstOrFail();
        PaymentVerification::findOrFail($claimId)->update(['task_id' => $taskB->id]);

        Sanctum::actingAs($this->accountManager());
        $this->postJson("/api/v1/payments/verifications/{$claimId}/confirm", [])
            ->assertStatus(422);

        $this->assertSame('Pending', PaymentVerification::findOrFail($claimId)->verification_status);
        $this->assertSame(0, $billA->payments()->count());
        $this->assertSame('Payment Follow-up', $taskB->fresh()->status);
    }

    public function test_confirmation_cannot_advance_a_task_assigned_to_another_officer(): void
    {
        $officer = $this->officer();
        $otherOfficer = User::create([
            'staff_id' => 'ENF-OTHER',
            'full_name' => 'Other Enforcement Officer',
            'email' => 'other.enf@test.lra',
            'password' => bcrypt('password'),
            'role_id' => $officer->role_id,
            'section_id' => $officer->section_id,
            'is_active' => true,
            'must_reset_password' => false,
        ]);

        Sanctum::actingAs($officer);
        $bill = $this->bill('DOC-PAY-8', 'P-PAY-8', $officer);
        $otherTask = Task::create([
            'task_type' => 'Payment Follow-up',
            'section' => 'Enforcement',
            'reference_type' => 'property_bill',
            'reference_id' => $bill->id,
            'assigned_to' => $otherOfficer->id,
            'assigned_by' => $officer->id,
            'priority' => 'Medium',
            'status' => 'Payment Follow-up',
        ]);
        $claimId = $this->submitClaim($bill, 'REC-PAY-8');
        PaymentVerification::findOrFail($claimId)->update(['task_id' => $otherTask->id]);

        Sanctum::actingAs($this->accountManager());
        $this->postJson("/api/v1/payments/verifications/{$claimId}/confirm", [])
            ->assertStatus(422);

        $this->assertSame('Pending', PaymentVerification::findOrFail($claimId)->verification_status);
        $this->assertSame('Payment Follow-up', $otherTask->fresh()->status);
        $this->assertSame(0, $bill->payments()->count());
    }

    public function test_legacy_claim_with_one_authoritative_task_is_linked_and_advanced(): void
    {
        $officer = $this->officer();
        Sanctum::actingAs($officer);
        $bill = $this->bill('DOC-PAY-9', 'P-PAY-9', $officer, 2500.00);
        $claimId = $this->submitClaim($bill, 'REC-PAY-9', 2500.00);
        $taskId = PaymentVerification::findOrFail($claimId)->task_id;
        PaymentVerification::findOrFail($claimId)->update(['task_id' => null]);

        Sanctum::actingAs($this->accountManager());
        $this->postJson("/api/v1/payments/verifications/{$claimId}/confirm", ['verified_amount' => 2500.00])
            ->assertOk();

        $this->assertSame($taskId, PaymentVerification::findOrFail($claimId)->task_id);
        $this->assertSame('Paid', Task::findOrFail($taskId)->status);
    }

    public function test_confirmation_rejects_a_claim_whose_property_does_not_match_its_bill(): void
    {
        $officer = $this->officer();
        Sanctum::actingAs($officer);
        $billA = $this->bill('DOC-PAY-7A', 'P-PAY-7A', $officer);
        $billB = $this->bill('DOC-PAY-7B', 'P-PAY-7B', $officer);
        $claimId = $this->submitClaim($billA, 'REC-PAY-7');
        PaymentVerification::findOrFail($claimId)->update(['property_id' => $billB->property_id]);

        Sanctum::actingAs($this->accountManager());
        $this->postJson("/api/v1/payments/verifications/{$claimId}/confirm", [])
            ->assertStatus(422);

        $this->assertSame('Pending', PaymentVerification::findOrFail($claimId)->verification_status);
        $this->assertSame(0, $billA->payments()->count());
    }

    public function test_duplicate_receipt_on_same_bill_is_blocked_and_audited(): void
    {
        Sanctum::actingAs($this->officer());
        $bill = $this->bill('DOC-1001', 'P-0001', $this->officer());
        $this->submitClaim($bill);

        $response = $this->postJson('/api/v1/enforcement/submit-receipt', $this->submitArray('DOC-1001', 'REC-9001', 2500.00));

        $response->assertStatus(422);
        $this->assertStringContainsString('already been submitted for this property', $response->json('message'));
        $this->assertSame(1, PaymentVerification::where('bill_id', $bill->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'receipt.duplicate_blocked']);
    }

    public function test_receipt_approved_for_one_property_cannot_be_claimed_for_another(): void
    {
        Sanctum::actingAs($this->officer());
        $billA = $this->bill('DOC-1001', 'P-0001', $this->officer());
        $billB = $this->bill('DOC-1002', 'P-0002', $this->officer());
        $claimId = $this->submitClaim($billA);

        Sanctum::actingAs($this->accountManager());
        $this->postJson("/api/v1/payments/verifications/{$claimId}/confirm", ['remarks' => 'verified'])
            ->assertStatus(200)
            ->assertJsonPath('data.verification_status', 'Confirmed');

        $this->assertDatabaseHas('payments', ['bill_id' => $billA->id, 'receipt_number' => 'REC-9001']);

        Sanctum::actingAs($this->officer());
        $this->postJson('/api/v1/enforcement/submit-receipt', $this->submitArray('DOC-1002', 'REC-9001', 2500.00))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This receipt has already been approved for Property ID P-0001 and cannot be used for another payment claim.');

        $this->assertDatabaseMissing('payment_verifications', ['bill_id' => $billB->id, 'receipt_number' => 'REC-9001']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'receipt.duplicate_blocked']);
    }

    public function test_reject_frees_the_fingerprint_for_corrected_resubmission(): void
    {
        Sanctum::actingAs($this->officer());
        $bill = $this->bill('DOC-1001', 'P-0001', $this->officer());
        $claimId = $this->submitClaim($bill);

        Sanctum::actingAs($this->accountManager());
        $this->postJson("/api/v1/payments/verifications/{$claimId}/reject", ['reason' => 'Receipt unreadable.'])
            ->assertStatus(200)
            ->assertJsonPath('data.verification_status', 'Rejected');

        $this->assertDatabaseMissing('receipt_fingerprints', ['claim_id' => $claimId]);

        Sanctum::actingAs($this->officer());
        $this->postJson('/api/v1/enforcement/submit-receipt', $this->submitArray('DOC-1001', 'REC-9001', 2500.00))
            ->assertStatus(201);
    }

    public function test_confirm_is_refused_outside_the_actors_record_scope(): void
    {
        [$bill] = [$this->bill('DOC-1001', 'P-0001', $this->officer())];
        Sanctum::actingAs($this->officer());
        $claimId = $this->submitClaim($bill);

        // Account & Records Officer who did not log this bill and is not
        // assigned any team/task on it must not be able to touch the claim.
        Sanctum::actingAs($this->foreignAccountOfficer());

        $this->postJson("/api/v1/payments/verifications/{$claimId}/confirm", ['remarks' => 'x'])
            ->assertStatus(403);

        $this->postJson("/api/v1/payments/verifications/{$claimId}/reject", ['reason' => 'x'])
            ->assertStatus(403);

        $this->assertSame('Pending', PaymentVerification::findOrFail($claimId)->verification_status);
    }

    public function test_duplicate_check_returns_minimal_conflict_data_without_pii(): void
    {
        $officer = $this->officer();
        Sanctum::actingAs($officer);
        $billA = $this->bill('DOC-1001', 'P-0001', $officer, 2500.00);
        $claimId = $this->submitClaim($billA);

        Sanctum::actingAs($this->accountManager());
        $this->postJson("/api/v1/payments/verifications/{$claimId}/confirm", ['remarks' => 'ok'])->assertStatus(200);

        Sanctum::actingAs($officer);
        $response = $this->postJson('/api/v1/payments/duplicate-check', [
            'receipt_number' => 'REC-9001',
            'amount' => 2500.00,
            'period' => '2025',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.hard_block', true);

        $conflicts = $response->json('data.conflicts');
        $this->assertNotEmpty($conflicts);
        foreach ($conflicts as $conflict) {
            $this->assertSame(
                ['claim_id', 'status', 'same_bill', 'property_id'],
                array_keys($conflict),
            );
        }

        // No taxpayer records, names, TINs or receipt artifacts may leak.
        $body = $response->getContent();
        $this->assertStringNotContainsString('Taxpayer P-0001', $body);
        $this->assertStringNotContainsString('TIN-P-0001', $body);
        $this->assertStringNotContainsString('taxpayer_name', $body);
        $this->assertStringNotContainsString('"tin"', $body);
        $this->assertStringNotContainsString('receipt_attachment', $body);
    }

    public function test_fingerprint_unique_constraint_is_the_write_time_backstop(): void
    {
        $fp = ReceiptDuplicationService::fingerprint('REC-9001', 2500, '2025');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/unique|UNIQUE/i');

        DB::table('receipt_fingerprints')->insert([
            ['fingerprint' => $fp, 'claim_id' => 1, 'bill_id' => 1, 'property_id' => 'P-0001', 'receipt_number' => 'REC-9001', 'amount' => 2500.00, 'payment_period' => '2025', 'verification_status' => 'Pending', 'created_at' => now(), 'updated_at' => now()],
            ['fingerprint' => $fp, 'claim_id' => 2, 'bill_id' => 2, 'property_id' => 'P-0002', 'receipt_number' => 'REC-9001', 'amount' => 2500.00, 'payment_period' => '2025', 'verification_status' => 'Pending', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_audit_entries_are_hash_chained(): void
    {
        $user = $this->officer();
        $service = app(AuditService::class);

        $service->record($user, 'test.chain.a', $user->id);
        $service->record($user, 'test.chain.b', $user->id);
        $service->record($user, 'test.chain.c', $user->id);

        $rows = AuditLog::whereIn('action', ['test.chain.a', 'test.chain.b', 'test.chain.c'])
            ->orderBy('id')->get();

        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertNotEmpty($row->hash);
            $this->assertSame($row->computeHash(), $row->hash);
        }

        $this->assertSame($rows[0]->hash, $rows[1]->previous_hash);
        $this->assertSame($rows[1]->hash, $rows[2]->previous_hash);
        $this->assertNotSame($rows[0]->hash, $rows[1]->hash);
    }

    public function test_non_assignee_cannot_submit_a_payment_claim(): void
    {
        Sanctum::actingAs($this->officer());
        $bill = $this->bill('DOC-1001', 'P-0001', $this->officer());

        $attacker = User::create([
            'staff_id' => 'MAL00',
            'full_name' => 'Malicious Officer',
            'email' => 'malicious@test.lra',
            'password' => bcrypt('password'),
            'role_id' => $this->officer()->role_id,
            'section_id' => $this->officer()->section_id,
            'is_active' => true,
            'must_reset_password' => false,
        ]);

        Sanctum::actingAs($attacker);
        $this->postJson('/api/v1/enforcement/submit-receipt', $this->submitArray('DOC-1001', 'REC-9999', 100.00))
            ->assertStatus(403);

        $this->assertDatabaseMissing('payment_verifications', ['bill_id' => $bill->id]);
    }
}
