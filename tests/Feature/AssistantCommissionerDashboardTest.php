<?php

namespace Tests\Feature;

use App\Models\PaymentVerification;
use App\Models\PropertyBill;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\LocalDemoUsers;
use Tests\TestCase;

/**
 * Assistant Commissioner dashboard — division-wide leadership view.
 *
 * Acceptance:
 *  - The AC gets a dedicated endpoint aggregating ALL sections (Account &
 *    Record, Valuation, Enforcement), not just one team.
 *  - Only the Assistant Commissioner role can reach it; manager/officer roles
 *    are rejected (403) and the AC is rejected from the manager endpoints.
 *  - Aggregates come from the real dataset — no hardcoded values.
 *  - The response never references the external system by name (RETD boundary).
 */
class AssistantCommissionerDashboardTest extends TestCase
{
    use LocalDemoUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function ac(): User
    {
        return User::where('email', 'ac@test.lra')->firstOrFail();
    }

    private function bill(string $document, float $total): PropertyBill
    {
        return PropertyBill::create([
            'document_number' => $document,
            'property_id' => 'P-'.substr(md5($document), 0, 8),
            'taxpayer_name' => "Taxpayer {$document}",
            'tin' => 'TIN-'.substr(md5($document), 0, 6),
            'property_address' => "Address {$document}",
            'date_logged' => '2025-02-20',
            'total_tax_due' => $total,
            'outstanding_balance' => $total,
            'tax_amount' => $total,
            'payment_status' => 'Unpaid',
            'case_status' => 'Logged',
        ]);
    }

    public function test_assistant_commissioner_dashboard_is_materialized_from_real_data(): void
    {
        Sanctum::actingAs($this->ac());

        $this->bill('DOC-AC-9001', 4000.00);
        $this->bill('DOC-AC-9002', 5000.00);

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
            'receipt_number' => 'RC-AC-1',
            'receipt_bill_number' => 'DOC-AC-9001',
            'amount_claimed' => 1500.00,
            'payment_period' => '2025',
            'verification_status' => PaymentVerification::STATUS_PENDING,
        ]);

        $response = $this->getJson('/api/v1/dashboard/ac')->assertOk()->json('data');

        $this->assertSame('ac', $response['meta']['type']);
        $this->assertSame('2025-02-20', $response['meta']['range']['start']);
        $this->assertCount(8, $response['kpis']);

        $kpis = collect($response['kpis'])->keyBy('key');
        $this->assertSame(2, $kpis['bills_logged']['value']);
        $this->assertSame(1, $kpis['pending_assignment']['value']);
        $this->assertSame(1, $kpis['payment_verification']['value']);

        $this->assertCount(6, $response['financial']);
        $this->assertSame(9000, $response['financial'][0]['value']);

        $this->assertArrayHasKey('pipeline', $response);
        $this->assertArrayHasKey('donuts', $response);
        $this->assertArrayHasKey('workload', $response);
        $this->assertArrayHasKey('charts', $response);
        $this->assertArrayHasKey('priority', $response);
        $this->assertArrayHasKey('activity', $response);
        $this->assertArrayHasKey('alerts', $response);
        $this->assertNotEmpty($response['quick_actions']);
    }

    public function test_division_workload_covers_officers_across_all_sections(): void
    {
        Sanctum::actingAs($this->ac());

        $workload = $this->getJson('/api/v1/dashboard/ac')->assertOk()->json('data.workload');

        $this->assertSame('Officer Workload', $workload['title']);
        $this->assertSame(
            ['Officer', 'Assigned', 'In Progress', 'Review', 'Overdue', 'Completed'],
            $workload['columns']
        );

        $names = collect($workload['rows'])->pluck('officer');
        $account = User::where('email', 'account.officer@test.lra')->firstOrFail();
        $enforcement = User::where('email', 'enf.officer@test.lra')->firstOrFail();
        $valuation = User::where('email', 'val.officer@test.lra')->firstOrFail();

        foreach ([$account, $enforcement, $valuation] as $officer) {
            $this->assertTrue($names->contains($officer->full_name), "{$officer->full_name} should appear in the division workload");
        }

        // Status labels (columns.slice(1)) map positionally to cols keys.
        $row = $workload['rows'][0];
        $labels = array_slice($workload['columns'], 1);
        $keys = array_keys($row['cols']);
        $this->assertCount(count($labels), $keys);
        $this->assertSame('assigned', $keys[array_search('Assigned', $labels, true)]);
        $this->assertSame('in_progress', $keys[array_search('In Progress', $labels, true)]);
        $this->assertSame('review', $keys[array_search('Review', $labels, true)]);
        $this->assertSame('overdue', $keys[array_search('Overdue', $labels, true)]);
        $this->assertSame('completed', $keys[array_search('Completed', $labels, true)]);
    }

    public function test_only_the_assistant_commissioner_can_reach_the_endpoint(): void
    {
        Sanctum::actingAs(User::where('email', 'account.officer@test.lra')->firstOrFail());
        $this->getJson('/api/v1/dashboard/ac')->assertForbidden();

        Sanctum::actingAs(User::where('email', 'account.manager@test.lra')->firstOrFail());
        $this->getJson('/api/v1/dashboard/ac')->assertForbidden();

        Sanctum::actingAs($this->localEnforcementSupervisor());
        $this->getJson('/api/v1/dashboard/ac')->assertForbidden();

        // The AC cannot reach the section-scoped manager dashboards.
        Sanctum::actingAs($this->ac());
        $this->getJson('/api/v1/dashboard/manager/account')->assertForbidden();
        $this->getJson('/api/v1/dashboard/manager/valuation')->assertForbidden();
        $this->getJson('/api/v1/dashboard/manager/enforcement')->assertForbidden();
    }

    public function test_ac_dashboard_never_references_the_external_system_by_name(): void
    {
        Sanctum::actingAs($this->ac());

        $response = $this->getJson('/api/v1/dashboard/ac')->assertOk();
        $this->assertStringNotContainsString('LITAS', $response->getContent());
    }

    public function test_ac_dashboard_advertises_reporting_period_and_monthly_activity(): void
    {
        Sanctum::actingAs($this->ac());
        $data = $this->getJson('/api/v1/dashboard/ac')->assertOk()->json('data');

        $this->assertSame('Africa/Monrovia', $data['meta']['timezone']);
        $this->assertSame('Africa/Monrovia', $data['meta']['reporting']['timezone']);
        $this->assertSame('Current Month', $data['meta']['reporting']['period_label']);

        $this->assertIsArray($data['monthly']);
        $this->assertNotEmpty($data['monthly']);
        foreach ($data['monthly'] as $m) {
            $this->assertSame('period', $m['kind']);
            $this->assertArrayHasKey('kpi_code', $m);
            $this->assertArrayHasKey('event_field', $m);
            $this->assertArrayHasKey('event', $m);
        }

        foreach ($data['kpis'] as $kpi) {
            $this->assertSame('current', $kpi['kind']);
        }
    }
}
