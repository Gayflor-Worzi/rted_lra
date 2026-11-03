<?php

namespace Tests\Feature;

use App\Models\PropertyBill;
use App\Models\SyncTransaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Bill-logging duplicate prevention: one live assessment per (Property ID +
 * TIN + Tax Period) triple. A second log attempt — even by a different account
 * officer — is blocked with Amend guidance, the DB unique backstop first entry
 * into the race, and the Amendment surface remains the only correction path
 * for Document #, Penalty, and Interest.
 */
class BillRegisterSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function accountOfficer(): User
    {
        return User::where('email', 'account.officer@test.lra')->firstOrFail();
    }

    private function accountManager(): User
    {
        return User::where('email', 'account.manager@test.lra')->firstOrFail();
    }

    private function logPayload(string $doc, string $property, string $tin = 'TIN-0001', string $period = '2025'): array
    {
        return [
            'document_number' => $doc,
            'property_id' => $property,
            'taxpayer_name' => "Taxpayer {$property}",
            'tin' => $tin,
            'property_address' => "Address {$property}",
            'tax_amount' => 5000.00,
            'interest_charged' => 100.00,
            'penalty_charged' => 50.00,
            'tax_period' => $period,
        ];
    }

    public function test_another_officer_cannot_log_the_same_assessment(): void
    {
        Sanctum::actingAs($this->accountOfficer());
        $this->postJson('/api/v1/property-bills', $this->logPayload('2026/1001', 'P-0001'))->assertStatus(201);

        Sanctum::actingAs($this->accountManager());
        $this->postJson('/api/v1/property-bills', $this->logPayload('2026/1002', 'P-0001'))
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'already logged for Tax Period 2025') && str_contains($m, 'Amend'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'bill.duplicate_log_blocked',
            'auditable_type' => (new PropertyBill)->getMorphClass(),
        ]);
    }

    public function test_case_and_whitespace_variants_are_the_same_assessment(): void
    {
        Sanctum::actingAs($this->accountOfficer());
        $this->postJson('/api/v1/property-bills', $this->logPayload('2026/1003', 'P-0001'))->assertStatus(201);

        Sanctum::actingAs($this->accountManager());
        $this->postJson('/api/v1/property-bills', $this->logPayload('2026/1004', '  p-0001 ', 'TIN-0001', '2025'))
            ->assertStatus(422);
    }

    public function test_distinct_tax_period_is_a_new_assessment(): void
    {
        Sanctum::actingAs($this->accountOfficer());
        $this->postJson('/api/v1/property-bills', $this->logPayload('2026/1005', 'P-0002'))->assertStatus(201);

        $this->postJson('/api/v1/property-bills', $this->logPayload('2026/1006', 'P-0002', 'TIN-0001', '2026'))->assertStatus(201);
    }

    public function test_update_cannot_move_a_bill_onto_another_live_assessment(): void
    {
        Sanctum::actingAs($this->accountOfficer());
        $this->postJson('/api/v1/property-bills', $this->logPayload('2026/1007', 'P-0003', 'TIN-0001', '2024'))->assertStatus(201);
        $second = $this->postJson('/api/v1/property-bills', $this->logPayload('2026/1008', 'P-0003', 'TIN-0001', '2025'))
            ->assertStatus(201)->json('data');

        Sanctum::actingAs($this->accountManager());

        $this->putJson("/api/v1/property-bills/{$second['id']}", ['tax_period' => '2024'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'already exists') && str_contains($m, 'amend the existing bill'));

        $this->putJson("/api/v1/property-bills/{$second['id']}", ['tax_period' => '2026'])->assertStatus(200);
    }

    public function test_amendment_remains_the_cross_staff_correction_path(): void
    {
        Sanctum::actingAs($this->accountManager());
        $logged = $this->postJson('/api/v1/property-bills', $this->logPayload('2026/1009', 'P-0004'))->assertStatus(201)->json('data');

        Sanctum::actingAs($this->accountOfficer());

        $this->getJson('/api/v1/property-bills/lookup?property_id=P-0004&tax_period=2025')
            ->assertStatus(200)
            ->assertJsonPath('data.document_number', '2026/1009');

        $this->postJson("/api/v1/property-bills/{$logged['id']}/amend", ['penalty_charged' => 900.00, 'reason' => 'Late penalty recalculated'])
            ->assertStatus(200)
            ->assertJsonPath('data.bill.penalty_charged', '900.00');

        $this->assertDatabaseHas('bill_amendments', [
            'bill_id' => $logged['id'],
            'previous_penalty_charge' => 50.00,
            'new_penalty_charge' => 900.00,
            'new_document_number' => null,
        ]);
    }

    public function test_offline_amendment_replay_applies_and_audits_the_change_once(): void
    {
        $manager = $this->accountManager();
        Sanctum::actingAs($manager);
        $bill = $this->postJson('/api/v1/property-bills', $this->logPayload('2026/1012', 'P-OFFLINE-AMEND'))
            ->assertCreated()
            ->json('data');
        $payload = [
            'penalty_charged' => 700.00,
            'reason' => 'Field amendment prepared offline.',
            'local_transaction_id' => 'MOB-20261103-BILL-AMENDMENT',
        ];

        $first = $this->postJson("/api/v1/property-bills/{$bill['id']}/amend", $payload)
            ->assertOk()
            ->assertJsonPath('data.bill.penalty_charged', '700.00');
        $replay = $this->postJson("/api/v1/property-bills/{$bill['id']}/amend", $payload)
            ->assertOk()
            ->assertJsonPath('data.replayed', true);

        $this->assertSame($first->json('data.bill.id'), $replay->json('data.bill.id'));
        $this->assertSame(1, \App\Models\BillAmendment::where('bill_id', $bill['id'])->count());
        $this->assertSame(1, \App\Models\AuditLog::where('auditable_type', (new PropertyBill)->getMorphClass())
            ->where('auditable_id', $bill['id'])
            ->where('action', 'bill.amended')
            ->count());
        $this->assertSame(1, SyncTransaction::where('user_id', $manager->id)
            ->where('local_transaction_id', 'MOB-20261103-BILL-AMENDMENT')
            ->where('entity_type', 'bill_amendment')
            ->count());
    }

    public function test_database_unique_backstop_blocks_raw_duplicate_insert(): void
    {
        $this->assertTrue(Schema::hasColumn('property_bills', 'assessment_key'));
        $assessmentIndex = collect(Schema::getIndexes('property_bills'))
            ->first(fn (array $index) => $index['name'] === 'property_bills_assessment_unique');
        $this->assertNotNull($assessmentIndex);
        $this->assertTrue($assessmentIndex['unique']);
        Sanctum::actingAs($this->accountOfficer());
        $this->postJson('/api/v1/property-bills', $this->logPayload('2026/1010', 'P-0005'))->assertStatus(201);
        $thrown = null;

        try {
            DB::table('property_bills')->insert([
                'document_number' => '2026/1011',
                'property_id' => 'P-0005',
                'taxpayer_name' => 'Duplicate raw row',
                'tin' => 'TIN-0001',
                'property_address' => 'Same address',
                'tax_amount' => 1.00,
                'tax_period' => '2025',
                'date_logged' => now()->toDateString(),
                'delivery_status' => 'Logged',
                'payment_status' => 'Unpaid',
                'case_status' => 'Logged',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Inserting a duplicate assessment should violate the assessment_key unique index.');
        $this->assertSame('23000', (string) $thrown->getCode());
    }
}
