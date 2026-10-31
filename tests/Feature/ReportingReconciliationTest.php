<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\PropertyBill;
use App\Models\StaffTarget;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Gate 6 — Reporting / KPI reconciliation: every report total must equal the
 * authoritative table count/sum. Seeds a controlled business subset and proves
 * each operational report agrees with direct DB aggregates (no join over-counts,
 * no drift).
 */
class ReportingReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function assistantCommissioner(): User
    {
        return User::where('email', 'ac@test.lra')->firstOrFail();
    }

    private function makeBill(string $doc, string $propertyId, float $total, string $paymentStatus): PropertyBill
    {
        return PropertyBill::create([
            'document_number' => $doc,
            'property_id' => $propertyId,
            'property_tax_amount' => $total,
            'taxpayer_name' => 'Reconciliation Taxpayer',
            'property_classification' => 'Residential',
            'property_address' => 'Reconcile Ave',
            'tax_amount' => $total,
            'interest_charged' => 0,
            'penalty_charged' => 0,
            'total_tax_due' => $total,
            'outstanding_balance' => $paymentStatus === 'Paid' ? 0 : $total,
            'tax_period' => '2026',
            'payment_status' => $paymentStatus,
            'delivery_status' => PropertyBill::DELIVERY_STAGES[0],
            'case_status' => PropertyBill::CASE_STATUSES[0],
            'date_logged' => now()->toDateString(),
        ]);
    }

    public function test_bills_and_collections_reports_reconcile_to_authoritative_values(): void
    {
        $ac = $this->assistantCommissioner();

        $b1 = $this->makeBill('2026/REC-1', 'REG-REC-1', 1000, 'Paid');
        $b2 = $this->makeBill('2026/REC-2', 'REG-REC-2', 500, 'Unpaid');
        $this->makeBill('2026/REC-3', 'REG-REC-3', 250, 'Unpaid');
        $b4 = $this->makeBill('2026/REC-4', 'REG-REC-4', 300, 'Paid');
        $this->makeBill('2026/REC-5', 'REG-REC-5', 75, 'Unpaid');

        $verifier = $ac;
        Payment::create([
            'bill_id' => $b1->id,
            'document_number' => '2026/REC-1',
            'amount' => 1000,
            'payment_period' => '2026',
            'receipt_number' => 'PAY-REC-1',
            'verified_by' => $verifier->id,
            'verified_at' => now(),
        ]);
        Payment::create([
            'bill_id' => $b4->id,
            'document_number' => '2026/REC-4',
            'amount' => 300,
            'payment_period' => '2026',
            'receipt_number' => 'PAY-REC-4',
            'verified_by' => $verifier->id,
            'verified_at' => now(),
        ]);

        PaymentVerification::create([
            'bill_id' => $b2->id,
            'claimed_by' => $verifier->id,
            'property_id' => 'REG-REC-2',
            'document_number' => '2026/REC-2',
            'receipt_number' => 'CLAIM-REC-2',
            'receipt_bill_number' => '2026/REC-2',
            'amount_claimed' => 200,
            'payment_period' => '2026',
            'receipt_date' => now()->toDateString(),
            'match_status' => 'Pending',
            'verification_status' => 'Pending',
        ]);

        Sanctum::actingAs($ac);

        $bills = $this->getJson('/api/v1/reports/bills?per_page=50')->assertOk()->json('data.rows');
        $this->assertSame(PropertyBill::count(), $bills['total'], 'The bills register must count every bill.');

        $collections = $this->getJson('/api/v1/reports/collections?per_page=50')->assertOk()->json('data.totals');
        $this->assertSame(Payment::count(), $collections['count'], 'Collections count must equal verified payments.');
        $this->assertSame(
            round((float) Payment::sum('amount'), 2),
            (float) $collections['verified_amount'],
            'Verified amount must equal the authoritative payment sum.',
        );

        $queue = $this->getJson('/api/v1/reports/payment-queue?per_page=50')->assertOk()->json('data');
        $this->assertSame(PaymentVerification::count(), $queue['rows']['total'], 'The queue register must count every claim.');
    }

    public function test_kpi_summary_reconciles_to_authoritative_counts(): void
    {
        $ac = $this->assistantCommissioner();

        $this->makeBill('2026/REC-6', 'REG-REC-6', 460, 'Unpaid');

        $billsKpis = $this->actingAs($ac)->getJson('/api/v1/dashboard/analytics?metric=bills')->assertOk()->json('data.kpis');
        $collectionsKpis = $this->actingAs($ac)->getJson('/api/v1/dashboard/analytics?metric=collections')->assertOk()->json('data.kpis');

        $this->assertSame(
            PropertyBill::count(),
            collect($billsKpis)->firstWhere('key', 'total')['value'],
            'Billing total KPI must equal the authoritative bill count.',
        );

        $this->assertSame(
            round((float) Payment::sum('amount'), 2),
            (float) collect($collectionsKpis)->firstWhere('key', 'verified_amount')['value'],
            'Collections KPI must equal the authoritative payment sum.',
        );
    }

    public function test_enforcement_report_reconciles_task_counts_without_join_overcount(): void
    {
        $ac = $this->assistantCommissioner();

        $bill = $this->makeBill('2026/REC-7', 'REG-REC-7', 800, 'Unpaid');

        Task::create([
            'task_type' => 'Deliver Notice',
            'section' => 'Enforcement',
            'reference_type' => 'property_bill',
            'reference_id' => $bill->id,
            'task_reference' => 'TASK-2026-REC-7',
            'status' => 'Assigned',
            'assigned_to' => $ac->id,
            'assigned_by' => $ac->id,
            'due_date' => now()->addDays(7)->toDateString(),
            'created_at' => now(),
        ]);
        Task::create([
            'task_type' => 'Field Visit',
            'section' => 'Enforcement',
            'reference_type' => 'property_bill',
            'reference_id' => $bill->id,
            'task_reference' => 'TASK-2026-REC-8',
            'status' => 'Delivered',
            'assigned_to' => $ac->id,
            'assigned_by' => $ac->id,
            'due_date' => now()->addDays(7)->toDateString(),
            'created_at' => now(),
        ]);

        Sanctum::actingAs($ac);

        $summary = $this->getJson('/api/v1/reports/enforcement?per_page=50')->assertOk()->json('data.summary');

        $this->assertSame(2, $summary['total'], 'Enforcement summary must count every enforcement task exactly once.');
        $this->assertSame(1, $summary['assigned']);
        $this->assertSame(1, $summary['delivered']);
        $this->assertSame(0, $summary['escalated']);
    }

    public function test_personal_reports_are_restricted_to_the_signed_in_staff_member_and_use_live_targets(): void
    {
        $officer = User::where('email', 'account.officer@test.lra')->firstOrFail();
        $other = User::where('email', 'account.manager@test.lra')->firstOrFail();

        Task::create([
            'task_reference' => '=HYPERLINK("https://example.invalid","open")',
            'task_type' => 'Other',
            'section' => 'ACCT',
            'assigned_to' => $officer->id,
            'assigned_by' => $other->id,
            'status' => 'Closed',
            'completed_at' => now(),
        ]);
        Task::create([
            'task_reference' => 'TASK-OTHER-REPORT-1',
            'task_type' => 'Other',
            'section' => 'ACCT',
            'assigned_to' => $other->id,
            'assigned_by' => $other->id,
            'status' => 'Closed',
            'completed_at' => now(),
        ]);
        for ($i = 0; $i < 80; $i++) {
            Task::create([
                'task_reference' => 'TASK-OWN-REPORT-'.$i,
                'task_type' => 'Other',
                'section' => 'ACCT',
                'assigned_to' => $officer->id,
                'assigned_by' => $other->id,
                'status' => 'Assigned',
            ]);
        }

        $target = StaffTarget::create([
            'user_id' => $officer->id,
            'section' => 'Account & Records',
            'metric' => 'completed_tasks',
            'target_value' => 2,
            'measurement_unit' => 'Tasks',
            'frequency' => 'Monthly',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'period' => (string) now()->year,
            'status' => 'Approved',
            'approved_at' => now(),
        ]);
        StaffTarget::create([
            'user_id' => $other->id,
            'section' => 'Account & Records',
            'metric' => 'completed_tasks',
            'target_value' => 10,
            'measurement_unit' => 'Tasks',
            'frequency' => 'Monthly',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'period' => (string) now()->year,
            'status' => 'Approved',
            'approved_at' => now(),
        ]);

        $this->assertFalse($officer->canPermission('reports.view'));
        Sanctum::actingAs($officer);

        $tasks = $this->getJson('/api/v1/reports/my/tasks?per_page=100')
            ->assertOk()
            ->json('data.rows.data');
        $this->assertCount(81, $tasks);
        $this->assertContains('=HYPERLINK("https://example.invalid","open")', array_column($tasks, 'task_reference'));
        $this->assertNotContains('TASK-OTHER-REPORT-1', array_column($tasks, 'task_reference'));

        $performance = $this->getJson('/api/v1/reports/my/performance')
            ->assertOk()
            ->assertJsonPath('data.targets.0.id', $target->id)
            ->assertJsonPath('data.targets.0.achieved_value', 1)
            ->assertJsonPath('data.overall.completion_rate', 50);
        $this->assertCount(1, $performance->json('data.targets'));

        $csv = $this->get('/api/v1/reports/my/tasks/export?format=csv')->assertOk()->getContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);

        $performanceCsv = $this->get('/api/v1/reports/my/performance/export?format=csv')->assertOk()->getContent();
        $this->assertStringContainsString('completed_tasks', $performanceCsv);

        $pdf = $this->get('/api/v1/reports/my/tasks/export?format=pdf')
            ->assertOk()
            ->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(1, preg_match('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdf, $pageBox));
        $this->assertEqualsWithDelta(841.89, (float) $pageBox[1], 1);
        $this->assertEqualsWithDelta(595.28, (float) $pageBox[2], 1);
        preg_match_all('/\/Type\s+\/Page\b/', $pdf, $pdfPages);
        $this->assertGreaterThan(1, count($pdfPages[0]), 'A long personal report must paginate across A4 pages.');

        $officer->syncPermissionOverrides(['tasks.view_own' => false]);
        $this->getJson('/api/v1/reports/my/tasks')->assertForbidden();
        $this->getJson('/api/v1/reports/my/performance')->assertOk();
    }
}
