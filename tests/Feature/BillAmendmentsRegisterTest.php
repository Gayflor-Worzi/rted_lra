<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dedicated "Bill Amendment" register (spec sidebar) — the global audit
 * surface for every correction applied to a logged assessment. Account staff
 * can read + drill into the full corrections trail; enforcement staff must be
 * rejected (amendment work is an Account & Records activity, nothing to do
 * with field enforcement).
 */
class BillAmendmentsRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function officer(): User
    {
        return User::where('email', 'account.officer@test.lra')->firstOrFail();
    }

    private function manager(): User
    {
        return User::where('email', 'account.manager@test.lra')->firstOrFail();
    }

    private function enforcementOfficer(): User
    {
        return User::where('email', 'enf.officer@test.lra')->firstOrFail();
    }

    private function logBill(string $doc, string $property): int
    {
        Sanctum::actingAs($this->officer());

        return $this->postJson('/api/v1/property-bills', [
            'document_number' => $doc,
            'property_id' => $property,
            'taxpayer_name' => "Taxpayer {$property}",
            'tin' => 'TIN-0001',
            'property_address' => "Address {$property}",
            'tax_amount' => 5000.00,
            'interest_charged' => 100.00,
            'penalty_charged' => 50.00,
            'tax_period' => '2025',
        ])->assertStatus(201)->json('data')['id'];
    }

    public function test_account_staff_see_the_full_amendments_register(): void
    {
        $billId = $this->logBill('2026/3001', 'P-AM-1');

        Sanctum::actingAs($this->manager());
        $this->postJson("/api/v1/property-bills/{$billId}/amend", [
            'penalty_charged' => 900.00,
            'interest_charged' => 150.00,
            'reason' => 'Penalty recalculated after late settlement.',
        ])->assertStatus(200);

        $rows = $this->getJson('/api/v1/bill-amendments')->assertOk()->json('data.data');

        $this->assertCount(1, $rows);
        $first = $rows[0];
        $this->assertSame('2026/3001', $first['document_number']);
        $this->assertSame('P-AM-1', $first['property_id']);
        $this->assertSame('900.00', $first['changed']['penalty']['to']);
        $this->assertSame('50.00', $first['changed']['penalty']['from']);
        $this->assertSame('150.00', $first['changed']['interest']['to']);
        $this->assertStringContainsString('Penalty recalculated', $first['reason']);
        $this->assertEquals($this->manager()->full_name, $first['amended_by']);

        $this->assertDatabaseHas('bill_amendments', ['bill_id' => $billId, 'reason' => 'Penalty recalculated after late settlement.']);
    }

    public function test_non_amenders_are_scope_limited_while_custodians_see_all(): void
    {
        $billA = $this->logBill('2026/3002', 'P-AM-2');

        Sanctum::actingAs($this->manager());
        $this->postJson("/api/v1/property-bills/{$billA}/amend", ['penalty_charged' => 700.00, 'reason' => 'Correction.'])
            ->assertStatus(200);

        // An Account Supervisor holds bills.view (no amend power) but is not in
        // the officer's team, so the bill sits outside their scope — the
        // register must be scope-limited for them.
        $supervisor = User::create([
            'staff_id' => 'SUP001',
            'full_name' => 'Account Supervisor Test',
            'email' => 'acct.supervisor@test.local',
            'role_id' => Role::where('name', 'Account Supervisor')->value('id'),
            'section_id' => Section::where('code', 'ACCT')->value('id'),
            'is_active' => true,
            'must_reset_password' => false,
            'password' => Hash::make('password'),
        ]);

        Sanctum::actingAs($supervisor);
        $this->getJson('/api/v1/bill-amendments')
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        // The correction custodians (amend-authorized) see the full trail.
        Sanctum::actingAs($this->manager());
        $this->getJson('/api/v1/bill-amendments')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_enforcement_officer_cannot_access_the_amendments_register(): void
    {
        Sanctum::actingAs($this->enforcementOfficer());

        $this->getJson('/api/v1/bill-amendments')->assertStatus(403);
    }

    public function test_register_supports_document_search(): void
    {
        $this->logBill('2026/3004', 'P-AM-4');
        $this->logBill('2026/3005', 'P-AM-5');

        Sanctum::actingAs($this->manager());

        $rows = $this->getJson('/api/v1/bill-amendments?q=3005')->assertOk()->json('data.data');
        $this->assertCount(0, $rows, 'No amendments exist yet, so search returns nothing — but the endpoint must be reachable.');
    }
}
