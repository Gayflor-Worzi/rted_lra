<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\PropertyBill;
use App\Models\Task;
use App\Models\User;
use App\Services\EscalationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Escalation business rule — every ladder step is DATE-GATED. A bill can
 * technically evaluate the ladder immediately after delivery, but no stage may
 * become eligible on a fresh delivery:
 *
 *   30-Day Reminder  — 30 elapsed days since delivery
 *   72-Hour Demand   — 30 days after the 30-day notice
 *   Final Enforcement — 3 days after the 72-hour demand
 *   Closure          — 21 days of escalation
 *
 * Payment/verification state always holds the ladder. Verified with simulated
 * dates against the real service (Beta readiness review §16).
 */
class EscalationTimelineTest extends TestCase
{
    use RefreshDatabase;

    private int $billCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function freeze(string $at): void
    {
        $this->travelTo(CarbonImmutable::parse($at, 'Africa/Monrovia'));
    }

    private function enforcementOfficer(): User
    {
        return User::where('email', 'enf.officer@test.lra')->firstOrFail();
    }

    private function makeBill(array $extra = []): PropertyBill
    {
        $this->billCounter++;
        $doc = 'ESC-'.$this->billCounter;

        return PropertyBill::create(array_merge([
            'document_number' => $doc,
            'property_id' => 'P-ESC-'.$this->billCounter,
            'taxpayer_name' => "Taxpayer {$doc}",
            'tin' => 'TIN-ESC-'.$this->billCounter,
            'property_address' => "Address {$doc}",
            'date_logged' => '2026-08-01',
            'delivery_date' => '2026-09-01',
            'total_tax_due' => 5000,
            'outstanding_balance' => 5000,
            'tax_amount' => 5000,
            'payment_status' => 'Unpaid',
            'case_status' => 'Delivered',
            'escalation_stage' => null,
        ], $extra));
    }

    private function makeTask(PropertyBill $bill, string $status = 'Delivered', ?User $officer = null): Task
    {
        return Task::create([
            'task_type' => Task::TYPE_BILL_DELIVERY,
            'section' => 'Enforcement',
            'reference_type' => 'property_bill',
            'reference_id' => $bill->id,
            'assigned_to' => $officer?->id,
            'status' => $status,
        ]);
    }

    /* ----------------------------------------------------------------
     * 30-Day Reminder — nothing may issue on a fresh delivery
     * ---------------------------------------------------------------- */

    public function test_no_reminder_is_issued_immediately_after_delivery(): void
    {
        $this->freeze('2026-09-01 10:00:00');
        $bill = $this->makeBill();
        $task = $this->makeTask($bill);
        $service = app(EscalationService::class);

        $this->assertNull((app(EscalationService::class))->evaluate($bill)['eligible_action']);
        $this->assertNull($service->autoAdvance($bill));

        $bill->refresh();
        $task->refresh();
        $this->assertNull($bill->thirty_day_notice_date);
        $this->assertNull($bill->escalation_stage);
        $this->assertNull($task->completed_at);
        $this->assertSame('Delivered', $task->status);
    }

    public function test_no_reminder_before_thirty_elapsed_days(): void
    {
        $this->freeze('2026-09-29 10:00:00');
        $bill = $this->makeBill();
        $service = app(EscalationService::class);

        $result = $service->evaluate($bill);
        $this->assertNull($result['eligible_action']);
        $this->assertSame('Not yet eligible.', $result['reason']);
        $this->assertNull($service->autoAdvance($bill));
        $this->assertNull($bill->fresh()->thirty_day_notice_date);
    }

    public function test_thirty_day_reminder_eligible_at_exactly_thirty_days(): void
    {
        $this->freeze('2026-10-01 10:00:00');
        $bill = $this->makeBill();
        $task = $this->makeTask($bill);
        $service = app(EscalationService::class);

        $result = $service->evaluate($bill);
        $this->assertSame('30-Day Warning', $result['stage']);
        $this->assertSame('issue_thirty_day', $result['eligible_action']);

        $this->assertSame('30-Day Warning', $service->autoAdvance($bill, $this->enforcementOfficer()));

        $bill->refresh();
        $task->refresh();
        $this->assertSame('2026-10-01', $bill->thirty_day_notice_date?->toDateString());
        $this->assertSame('30-Day Warning', $bill->escalation_stage);
        $this->assertSame('30-Day Warning', $task->status);
        $this->assertNull($task->completed_at);
    }

    /* ----------------------------------------------------------------
     * 72-Hour Demand — 30 days after the 30-day notice
     * ---------------------------------------------------------------- */

    public function test_no_demand_before_thirty_days_after_the_notice(): void
    {
        $this->freeze('2026-10-30 10:00:00');
        $bill = $this->makeBill([
            'thirty_day_notice_date' => '2026-10-01',
            'escalation_stage' => '30-Day Warning',
        ]);
        $this->makeTask($bill, '30-Day Warning');
        $service = app(EscalationService::class);

        $result = $service->evaluate($bill);
        $this->assertNull($result['eligible_action']);
        $this->assertNull($service->autoAdvance($bill));
        $this->assertNull($bill->fresh()->final_notice_date);
    }

    public function test_seventy_two_hour_demand_eligible_at_thirty_days_after_notice(): void
    {
        $this->freeze('2026-10-31 10:00:00');
        $bill = $this->makeBill([
            'thirty_day_notice_date' => '2026-10-01',
            'escalation_stage' => '30-Day Warning',
        ]);
        $task = $this->makeTask($bill, '30-Day Warning');
        $service = app(EscalationService::class);

        $result = $service->evaluate($bill);
        $this->assertSame('72-Hour Warning', $result['stage']);
        $this->assertSame('issue_seventy_two_hour', $result['eligible_action']);

        $this->assertSame('72-Hour Warning', $service->autoAdvance($bill, $this->enforcementOfficer()));

        $bill->refresh();
        $task->refresh();
        $this->assertSame('2026-10-31', $bill->final_notice_date?->toDateString());
        $this->assertSame('72-Hour Warning', $bill->escalation_stage);
        $this->assertSame('72-Hour Warning', $task->status);
    }

    /* ----------------------------------------------------------------
     * Final Enforcement — 3 days after the demand; Closure — 21 days
     * ---------------------------------------------------------------- */

    public function test_final_enforcement_waiting_window_is_three_days(): void
    {
        $base = [
            'delivery_date' => '2026-09-01',
            'thirty_day_notice_date' => '2026-10-01',
            'final_notice_date' => '2026-10-31',
            'case_status' => '72-Hour Warning',
            'escalation_stage' => '72-Hour Warning',
        ];

        $this->freeze('2026-11-02 10:00:00');
        $bill = $this->makeBill($base);
        $this->makeTask($bill, '72-Hour Warning');
        $service = app(EscalationService::class);

        $this->assertNull($service->evaluate($bill)['eligible_action']);
        $this->assertNull($service->autoAdvance($bill));
        $this->assertSame('72-Hour Warning', $bill->fresh()->escalation_stage);

        $this->freeze('2026-11-03 10:00:00');
        $task = $bill->tasks()->orderByDesc('id')->first();
        $this->assertSame('Escalated', $service->evaluate($bill)['stage']);
        $this->assertSame('final_enforcement', $service->evaluate($bill)['eligible_action']);
        $this->assertSame('Escalated', $service->autoAdvance($bill, $this->enforcementOfficer()));

        $bill->refresh();
        $task->refresh();
        $this->assertSame('Escalated', $bill->escalation_stage);
        $this->assertSame('Escalated', $task->status);
    }

    public function test_closure_only_after_21_days_of_escalation(): void
    {
        $base = [
            'delivery_date' => '2026-09-01',
            'thirty_day_notice_date' => '2026-10-01',
            'final_notice_date' => '2026-10-31',
            'case_status' => 'Escalated',
            'escalation_stage' => 'Escalated',
        ];

        $this->freeze('2026-11-20 10:00:00');
        $bill = $this->makeBill($base);
        $this->makeTask($bill, 'Escalated');
        $service = app(EscalationService::class);

        $this->assertNull($service->evaluate($bill)['eligible_action']);
        $this->assertNull($service->autoAdvance($bill));
        $this->assertSame('Escalated', $bill->fresh()->escalation_stage);

        $this->freeze('2026-11-22 10:00:00');
        $task = $bill->tasks()->orderByDesc('id')->first();
        $this->assertSame('closure', $service->evaluate($bill)['eligible_action']);
        $this->assertSame('Closure', $service->autoAdvance($bill, $this->enforcementOfficer()));

        $bill->refresh();
        $task->refresh();
        $this->assertSame('Closure', $bill->escalation_stage);
        $this->assertSame('Closed', $task->status);
        $this->assertNotNull($task->completed_at);
    }

    /* ----------------------------------------------------------------
     * Holds — payment/verification state stops the ladder regardless of time
     * ---------------------------------------------------------------- */

    public function test_pending_verification_holds_the_ladder_after_90_days(): void
    {
        $this->freeze('2026-11-30 10:00:00');
        $bill = $this->makeBill([
            'delivery_date' => '2026-09-01',
            'case_status' => 'Under Verification',
        ]);
        $service = app(EscalationService::class);

        $result = $service->evaluate($bill);
        $this->assertNull($result['eligible_action']);
        $this->assertSame('Payment claim pending verification.', $result['reason']);
        $this->assertNull($service->autoAdvance($bill));
        $this->assertNull($bill->fresh()->thirty_day_notice_date);
    }

    public function test_open_payment_claim_holds_the_ladder(): void
    {
        $this->freeze('2026-11-30 10:00:00');
        $bill = $this->makeBill(['delivery_date' => '2026-09-01']);
        $officer = $this->enforcementOfficer();
        PaymentVerification::create([
            'bill_id' => $bill->id,
            'claimed_by' => $officer->id,
            'document_number' => $bill->document_number,
            'receipt_number' => 'RCPT-1001',
            'receipt_bill_number' => $bill->document_number,
            'amount_claimed' => 5000,
            'payment_period' => '2025',
            'receipt_date' => '2026-11-01',
            'verification_status' => 'Pending',
        ]);
        $service = app(EscalationService::class);

        $result = $service->evaluate($bill);
        $this->assertNull($result['eligible_action']);
        $this->assertSame('Payment claim pending verification.', $result['reason']);
        $this->assertNull($service->autoAdvance($bill));
        $this->assertNull($bill->fresh()->thirty_day_notice_date);
    }

    public function test_paid_bill_never_enters_the_ladder(): void
    {
        $this->freeze('2026-11-30 10:00:00');
        $bill = $this->makeBill(['delivery_date' => '2026-09-01']);
        $officer = $this->enforcementOfficer();
        Payment::create([
            'bill_id' => $bill->id,
            'document_number' => $bill->document_number,
            'amount' => 5000,
            'payment_period' => '2025',
            'receipt_number' => 'RCPT-2001',
            'source_reference' => 'SRC-PAY-ESC-'.$bill->id,
            'verified_by' => $officer->id,
        ]);
        $bill->recalculateOutstanding();
        $service = app(EscalationService::class);

        $this->assertSame('Paid', $bill->fresh()->payment_status);
        $result = $service->evaluate($bill->fresh());
        $this->assertNull($result['eligible_action']);
        $this->assertSame('No outstanding balance.', $result['reason']);
        $this->assertNull($service->autoAdvance($bill->fresh()));
    }
}
