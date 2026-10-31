<?php

namespace Tests\Feature;

use App\Models\EnforcementVisit;
use App\Models\PaymentVerification;
use App\Models\PropertyBill;
use App\Models\Task;
use App\Models\User;
use App\Services\BillScopeService;
use App\Support\BusinessClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\LocalDemoUsers;
use Tests\TestCase;

/**
 * Manager dashboards — role-specific aggregated payloads.
 *
 * Acceptance:
 *  - Account & Record Manager / Valuation Manager / Enforcement Manager each
 *    get their OWN endpoint with their OWN figures.
 *  - Only those manager roles can reach them (403 otherwise).
 *  - Aggregates come from the real scoped dataset — no hardcoded values.
 *  - The response never references the external system by name (RETD boundary).
 */
class ManagerDashboardTest extends TestCase
{
    use LocalDemoUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->localEnforcementSupervisor();
    }

    private function accountManager(): User
    {
        return User::where('email', 'account.manager@test.lra')->firstOrFail();
    }

    private function valuationManager(): User
    {
        return User::where('email', 'val.manager@test.lra')->firstOrFail();
    }

    private function enforcementSupervisor(): User
    {
        return $this->localEnforcementSupervisor();
    }

    private function bill(string $document, float $total): PropertyBill
    {
        return PropertyBill::create([
            'document_number' => $document,
            'property_id' => 'P-'.substr(md5($document), 0, 8),
            'taxpayer_name' => "Taxpayer {$document}",
            'tin' => 'TIN-'.substr(md5($document), 0, 6),
            'property_address' => "Address {$document}",
            'date_logged' => '2025-01-15',
            'total_tax_due' => $total,
            'outstanding_balance' => $total,
            'tax_amount' => $total,
            'payment_status' => 'Unpaid',
            'case_status' => 'Logged',
        ]);
    }

    public function test_account_manager_dashboard_is_materialized_from_real_data(): void
    {
        Sanctum::actingAs($this->accountManager());

        $this->bill('DOC-ACCT-9001', 4000.00);
        $this->bill('DOC-ACCT-9002', 5000.00);

        Task::create([
            'task_type' => Task::TYPE_ACCOUNT_PROCESSING,
            'section' => 'Account & Records',
            'reference_type' => 'property_bill',
            'reference_id' => 1,
            'assigned_to' => null,
            'status' => 'Logged',
        ]);

        PaymentVerification::create([
            'bill_id' => 1,
            'receipt_number' => 'RC-ACCT-1',
            'receipt_bill_number' => 'DOC-ACCT-9001',
            'amount_claimed' => 1500.00,
            'payment_period' => '2025',
            'verification_status' => PaymentVerification::STATUS_PENDING,
        ]);

        $response = $this->getJson('/api/v1/dashboard/manager/account')->assertOk()->json('data');

        $this->assertSame('account', $response['meta']['type']);
        $this->assertSame('2025-01-15', $response['meta']['range']['start']);
        $this->assertCount(8, $response['kpis']);

        $kpis = collect($response['kpis'])->keyBy('key');
        $this->assertSame(2, $kpis['bills_logged']['value']);
        $this->assertSame(1, $kpis['pending_assignment']['value']);
        $this->assertSame(1, $kpis['payment_verification']['value']);

        $this->assertCount(6, $response['financial']);
        $this->assertSame(9000, $response['financial'][0]['value']);

        $this->assertArrayHasKey('pipeline', $response);
        $this->assertArrayHasKey('workload', $response);
        $this->assertArrayHasKey('priority', $response);
        $this->assertArrayHasKey('activity', $response);
        $this->assertNotEmpty($response['quick_actions']);
    }

    public function test_valuation_manager_dashboard_shape(): void
    {
        Sanctum::actingAs($this->valuationManager());

        $response = $this->getJson('/api/v1/dashboard/manager/valuation')->assertOk()->json('data');

        $this->assertSame('valuation', $response['meta']['type']);
        $this->assertCount(8, $response['kpis']);
        $this->assertArrayHasKey('pipeline', $response);
        $this->assertArrayHasKey('review_queue', $response);
        $this->assertArrayHasKey('quality', $response);
        $this->assertArrayHasKey('gps_evidence', $response);
        $this->assertArrayHasKey('ac_approval', $response);
        $this->assertArrayHasKey('charts', $response);
    }

    public function test_enforcement_supervisor_dashboard_shape(): void
    {
        Sanctum::actingAs($this->enforcementSupervisor());

        $response = $this->getJson('/api/v1/dashboard/manager/enforcement')->assertOk()->json('data');

        $this->assertSame('enforcement', $response['meta']['type']);
        $this->assertSame('Enforcement Supervisor Dashboard', $response['meta']['label']);
        $this->assertCount(12, $response['kpis']);
        $this->assertArrayHasKey('field_activity', $response);
        $this->assertArrayHasKey('payment_followup', $response);
        $this->assertArrayHasKey('notice_escalation', $response);
        $this->assertArrayHasKey('payment_security', $response);
        $this->assertNotEmpty($response['alerts']);
    }

    public function test_enforcement_delivery_donut_is_a_visit_only_status_breakdown(): void
    {
        $supervisor = $this->enforcementSupervisor();
        $officer = User::where('email', 'enf.officer@test.lra')->firstOrFail();
        $bill = $this->bill('DOC-DELIVERY-OUTCOMES', 1000.00);
        $bill->update([
            'assigned_enforcement_officer_id' => $officer->id,
            'delivery_status' => EnforcementVisit::DELIVERY_DELIVERED,
        ]);
        foreach ([
            EnforcementVisit::DELIVERY_DELIVERED,
            EnforcementVisit::DELIVERY_OUT_FOR_DELIVERY,
            EnforcementVisit::DELIVERY_RETURNED,
            EnforcementVisit::DELIVERY_UNDELIVERED,
            EnforcementVisit::DELIVERY_NOT_MAILABLE,
            null,
            'Unrecognized',
        ] as $index => $deliveryStatus) {
            EnforcementVisit::create([
                'bill_id' => $bill->id,
                'officer_id' => $officer->id,
                'visit_date' => now()->toDateString(),
                'visit_status' => 'Attempt '.($index + 1),
                'delivery_status' => $deliveryStatus,
            ]);
        }

        Sanctum::actingAs($supervisor);
        $data = $this->getJson('/api/v1/dashboard/manager/enforcement')->assertOk()->json('data');
        $delivery = collect($data['donuts'])->firstWhere('title', 'Delivery Performance');

        $this->assertNotNull($delivery);
        $categoryCounts = collect($delivery['data'])->mapWithKeys(fn ($item) => [$item['label'] => $item['value']]);
        $this->assertSame(5, $delivery['center']);
        $this->assertSame(
            [
                'Delivered' => 1,
                'Out for Delivery' => 1,
                'Returned' => 1,
                'Undelivered' => 1,
                'Not Mailable' => 1,
            ],
            $categoryCounts->all()
        );
        $this->assertSame($delivery['center'], $categoryCounts->sum());
    }

    public function test_enforcement_workload_uses_disjoint_active_and_completed_counts(): void
    {
        $supervisor = $this->enforcementSupervisor();
        $officer = User::where('email', 'enf.officer@test.lra')->firstOrFail();

        foreach ([
            [Task::TYPE_BILL_DELIVERY, 'Assigned'],
            [Task::TYPE_PAYMENT_FOLLOWUP, 'Payment Follow-up'],
            [Task::TYPE_BILL_DELIVERY, 'Resolved'],
        ] as $index => [$type, $status]) {
            Task::create([
                'task_type' => $type,
                'section' => 'Enforcement',
                'reference_type' => 'property_bill',
                'reference_id' => 200 + $index,
                'assigned_to' => $officer->id,
                'status' => $status,
            ]);
        }

        Sanctum::actingAs($supervisor);
        $data = $this->getJson('/api/v1/dashboard/manager/enforcement')->assertOk()->json('data');
        $this->assertSame('exclusive', $data['workload']['mode']);
        $this->assertSame(['Officer', 'Active', 'Completed'], $data['workload']['columns']);

        $row = collect($data['workload']['rows'])->firstWhere('officer_id', $officer->id);
        $this->assertSame(2, $row['cols']['active']);
        $this->assertSame(1, $row['cols']['completed']);
        $this->assertSame(1, $row['operational']['delivery']);
        $this->assertSame(1, $row['operational']['followup']);
    }

    public function test_enforcement_unassigned_counts_the_out_of_scope_assignment_queue(): void
    {
        $supervisor = $this->enforcementSupervisor();

        // A walk-in bill awaiting assignment is queued as an open, unassigned
        // bill-delivery task, so it is not yet in the supervisor's team scope.
        $bill = $this->bill('DOC-QUEUE-9001', 1000.00);
        $bill->update(['case_status' => 'Awaiting Assignment']);

        Task::create([
            'task_type' => Task::TYPE_BILL_DELIVERY,
            'section' => 'Enforcement',
            'reference_type' => 'property_bill',
            'reference_id' => $bill->id,
            'assigned_to' => null,
            'status' => 'Awaiting Assignment',
        ]);

        $this->assertNotContains($bill->id, BillScopeService::visibleBillIds($supervisor));
        $this->assertContains($bill->id, BillScopeService::unassignedEnforcementBillIds());

        Sanctum::actingAs($supervisor);
        $data = $this->getJson('/api/v1/dashboard/manager/enforcement')->assertOk()->json('data');

        $this->assertSame(1, collect($data['kpis'])->firstWhere('key', 'unassigned')['value']);
        $this->assertSame(1, collect($data['alerts'])->firstWhere('label', 'Unassigned bills')['count']);
    }

    public function test_workload_rows_align_status_columns_and_cols_keys(): void
    {
        Sanctum::actingAs($this->accountManager());
        $officer = User::where('email', 'account.officer@test.lra')->firstOrFail();

        Task::create([
            'task_type' => Task::TYPE_ACCOUNT_PROCESSING,
            'section' => 'Account & Records',
            'reference_type' => 'property_bill',
            'reference_id' => 1,
            'assigned_to' => $officer->id,
            'status' => 'Logged',
        ]);

        $workload = $this->getJson('/api/v1/dashboard/manager/account')->assertOk()->json('data.workload');

        $row = collect($workload['rows'])->firstWhere('officer_id', $officer->id);
        $this->assertNotNull($row, 'officer should appear in workload rows');
        $this->assertSame($officer->full_name, $row['officer']);
        $this->assertSame(1, $row['cols']['assigned']);
        $this->assertSame(0, $row['cols']['completed']);

        // The interactive bar chart maps the status label at column index i to
        // the cols key at index i, so the two arrays must stay position-aligned.
        $labels = array_slice($workload['columns'], 1);
        $keys = array_keys($row['cols']);
        $this->assertCount(count($labels), $keys);
        $this->assertSame('assigned', $keys[array_search('Assigned', $labels, true)]);
        $this->assertSame('in_progress', $keys[array_search('In Progress', $labels, true)]);
        $this->assertSame('payment_verification', $keys[array_search('Payment Verification', $labels, true)]);
        $this->assertSame('overdue', $keys[array_search('Overdue', $labels, true)]);
        $this->assertSame('completed', $keys[array_search('Completed', $labels, true)]);
    }

    public function test_non_manager_roles_are_rejected(): void
    {
        Sanctum::actingAs(User::where('email', 'account.officer@test.lra')->firstOrFail());
        $this->getJson('/api/v1/dashboard/manager/account')->assertForbidden();
        $this->getJson('/api/v1/dashboard/manager/valuation')->assertForbidden();
        $this->getJson('/api/v1/dashboard/manager/enforcement')->assertForbidden();

        Sanctum::actingAs(User::where('email', 'enf.officer@test.lra')->firstOrFail());
        $this->getJson('/api/v1/dashboard/manager/enforcement')->assertForbidden();

        Sanctum::actingAs(User::where('email', 'ac@test.lra')->firstOrFail());
        $this->getJson('/api/v1/dashboard/manager/account')->assertForbidden();
    }

    public function test_manager_dashboards_never_reference_the_external_system_by_name(): void
    {
        $accessors = [
            'account.manager@test.lra' => '/api/v1/dashboard/manager/account',
            'val.manager@test.lra' => '/api/v1/dashboard/manager/valuation',
            'enf.supervisor@test.lra' => '/api/v1/dashboard/manager/enforcement',
        ];

        foreach ($accessors as $email => $endpoint) {
            Sanctum::actingAs(User::where('email', $email)->firstOrFail());
            $response = $this->getJson($endpoint)->assertOk();
            $this->assertStringNotContainsString('LITAS', $response->getContent());
        }
    }

    public function test_dashboards_advertise_reporting_period_and_business_timezone(): void
    {
        $accessors = [
            'account.manager@test.lra' => '/api/v1/dashboard/manager/account',
            'val.manager@test.lra' => '/api/v1/dashboard/manager/valuation',
            'enf.supervisor@test.lra' => '/api/v1/dashboard/manager/enforcement',
        ];

        foreach ($accessors as $email => $endpoint) {
            Sanctum::actingAs(User::where('email', $email)->firstOrFail());
            $data = $this->getJson($endpoint)->assertOk()->json('data');

            $this->assertSame('Africa/Monrovia', $data['meta']['timezone']);
            $this->assertSame('Current Month', $data['meta']['reporting']['period_label']);
            $this->assertSame('Africa/Monrovia', $data['meta']['reporting']['timezone']);
            $this->assertArrayHasKey('start_date', $data['meta']['reporting']['period']);
            $this->assertArrayHasKey('end_date', $data['meta']['reporting']['period']);

            $this->assertIsArray($data['monthly']);
        }
    }

    public function test_monthly_activity_kpis_use_event_dates_within_current_month(): void
    {
        Sanctum::actingAs($this->accountManager());

        $today = BusinessClock::today();
        $this->bill('DOC-MONTH-1', 1200.00);
        PropertyBill::where('document_number', 'DOC-MONTH-1')->update(['date_logged' => $today->toDateString()]);

        $data = $this->getJson('/api/v1/dashboard/manager/account')->assertOk()->json('data');

        $monthly = collect($data['monthly'])->keyBy('key');
        $this->assertSame(1, $monthly['bills_logged_period']['value']);
        $this->assertSame('period', $monthly['bills_logged_period']['kind']);
        $this->assertSame('date_logged', $monthly['bills_logged_period']['event_field']);
        $this->assertSame('AR_BILLS_LOGGED_PERIOD', $monthly['bills_logged_period']['kpi_code']);

        // Every KPI card is a current snapshot labelled as such (§81).
        $this->assertNotEmpty($data['kpis']);
        foreach ($data['kpis'] as $kpi) {
            $this->assertSame('current', $kpi['kind']);
        }
    }

    public function test_overdue_kpi_excludes_tasks_due_today_but_includes_past_due(): void
    {
        Sanctum::actingAs($this->accountManager());
        $officer = User::where('email', 'account.officer@test.lra')->firstOrFail();

        $today = BusinessClock::today();

        Task::create([
            'task_type' => Task::TYPE_ACCOUNT_PROCESSING,
            'section' => 'Account & Records',
            'reference_type' => 'property_bill',
            'reference_id' => 1,
            'assigned_to' => $officer->id,
            'status' => 'Logged',
            'due_date' => $today->toDateString(), // due today → NOT overdue
        ]);
        Task::create([
            'task_type' => Task::TYPE_ACCOUNT_PROCESSING,
            'section' => 'Account & Records',
            'reference_type' => 'property_bill',
            'reference_id' => 2,
            'assigned_to' => $officer->id,
            'status' => 'Logged',
            'due_date' => $today->subDay()->toDateString(), // overdue
        ]);

        $kpis = collect($this->getJson('/api/v1/dashboard/manager/account')->assertOk()->json('data.kpis'))->keyBy('key');

        $this->assertSame(1, $kpis['overdue']['value']);
    }

    /**
     * The dashboard used to ship "ðŸ“‹"-shaped garbage in place of its emoji
     * icons. The response was valid UTF-8, so no header or transport check would
     * ever have caught it — the payload itself has to be asserted.
     */
    public function test_dashboard_payload_ships_renderable_icons_and_no_mojibake(): void
    {
        Sanctum::actingAs($this->accountManager());

        $data = $this->getJson('/api/v1/dashboard/manager/account')->assertOk()->json('data');

        $this->assertNotEmpty($data['kpis']);
        foreach ($data['kpis'] as $kpi) {
            $this->assertArrayHasKey('icon', $kpi, "KPI [{$kpi['key']}] has no icon");
            $this->assertNotSame('', trim((string) $kpi['icon']));
            $this->assertSame(
                1,
                preg_match('//u', (string) $kpi['icon']),
                "KPI [{$kpi['key']}] icon is not valid UTF-8"
            );
        }

        $this->assertNotEmpty($data['quick_actions']);
        foreach ($data['quick_actions'] as $action) {
            $this->assertArrayHasKey('icon', $action, "Quick action [{$action['label']}] has no icon");
            $this->assertNotSame('', trim((string) $action['icon']));
        }

        // every icon must be a real pictograph, not a Latin-1 remnant
        $lead = '/[\x{00F0}\x{00E2}\x{00C3}\x{00C5}\x{00C2}\x{00EF}]/u';

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        $this->assertTrue(
            mb_check_encoding($encoded, 'UTF-8'),
            'Dashboard payload is not valid UTF-8'
        );
        $this->assertDoesNotMatchRegularExpression(
            $lead,
            (string) $encoded,
            'Dashboard payload contains mis-decoded Latin-1 characters'
        );
        $this->assertStringNotContainsString("\u{FFFD}", (string) $encoded);
    }
}
