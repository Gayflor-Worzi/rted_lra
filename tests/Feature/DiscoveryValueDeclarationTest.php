<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\PropertyDiscoveryEvidence;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * System-wide Discovery value model (§2/§5/§7/§9/§13/§18/§24):
 *  - the controlled Value Declaration Type drives routing,
 *  - declared / documentary / official assessed values are always kept apart,
 *  - ownership-document + up-to-3-photo evidence is scope-guarded and
 *    mutation-status guarded,
 *  - the Valuation Value can be requested but never fabricated by the
 *    Discovery Officer,
 *  - AC approval records the official assessed value without touching the
 *    declared value (Discovery ⇄ Valuation traceability, §17/§18), and
 *  - the logged bill prefers the official value while the declared value stays
 *    off the Tax Amount (§13).
 */
class DiscoveryValueDeclarationTest extends TestCase
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

    private function valManager(): User
    {
        return User::where('email', 'val.manager@test.lra')->firstOrFail();
    }

    private function valOfficer(): User
    {
        return User::where('email', 'val.officer@test.lra')->firstOrFail();
    }

    private function ac(): User
    {
        return User::where('email', 'ac@test.lra')->firstOrFail();
    }

    private function secondAccountOfficer(): User
    {
        return User::create([
            'staff_id' => 'SECOND6',
            'full_name' => 'Second Account Officer',
            'email' => 'account.officer2@test.lra',
            'password' => bcrypt('password'),
            'role_id' => Role::where('name', 'Account & Records Officer')->value('id'),
            'section_id' => Section::where('code', 'ACCT')->value('id'),
            'is_active' => true,
            'must_reset_password' => false,
        ]);
    }

    private function create(array $extra = []): array
    {
        return $this->postJson('/api/v1/discoveries', array_merge([
            'owner_name' => 'Owner Value',
            'owner_contact' => '0770-000-001',
            'tin' => 'TIN-VAL',
            'property_address' => 'Address Value Town',
            'property_classification' => 'Residential',
            'property_type' => 'House',
            'county' => 'Montserrado',
            'gps_lat' => '6.315611',
            'gps_lng' => '-10.807407',
        ], $extra))->assertCreated()->json('data');
    }

    private function attachEvidence(int $discoveryId, string $type, ?int $sequence = null): TestResponse
    {
        $file = $type === PropertyDiscoveryEvidence::TYPE_OWNERSHIP_DOCUMENT
            ? UploadedFile::fake()->create('deed.pdf', 200, 'application/pdf')
            : UploadedFile::fake()->image('photo'.($sequence ?? 1).'.jpg', 100, 100);

        $payload = ['evidence_type' => $type, 'capture_source' => 'UPLOAD', 'file' => $file];
        if ($sequence !== null) {
            $payload['sequence_no'] = $sequence;
        }

        return $this->postJson("/api/v1/discoveries/{$discoveryId}/evidence", $payload);
    }

    public function test_self_declared_discovery_stores_value_and_routes_to_account(): void
    {
        Sanctum::actingAs($this->officer());

        $discovery = $this->create([
            'value_declaration_type' => 'SELF_DECLARED',
            'declared_assessed_value' => 25000.00,
        ]);
        $submitted = $this->postJson("/api/v1/discoveries/{$discovery['id']}/submit")->assertOk()->json('data');

        $this->assertSame('SELF_DECLARED', $submitted['value_declaration_type']);
        $this->assertSame('account', $submitted['decision_path']);
        $this->assertSame(PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW, $submitted['status']);
        $this->assertSame(25000.0, (float) $submitted['declared_assessed_value']);

        $fresh = PropertyDiscovery::findOrFail($discovery['id']);
        $this->assertSame(25000.0, (float) $fresh->declared_assessed_value);
        $this->assertSame(25000.0, (float) $fresh->declared_value);

        $classified = AuditLog::where('auditable_type', PropertyDiscovery::class)
            ->where('auditable_id', $fresh->id)->where('action', 'discovery.classified')->latest('id')->firstOrFail();

        $this->assertSame('SELF_DECLARED', $classified->new_values['value_declaration_type']);
        $this->assertSame('account', $classified->new_values['decision_path']);
        $this->assertSame(25000.0, (float) $classified->new_values['declared_assessed_value']);
    }

    public function test_documentary_value_requires_ownership_evidence_to_submit(): void
    {
        Sanctum::actingAs($this->officer());

        $discovery = $this->create([
            'value_declaration_type' => 'DOCUMENTARY_VALUE',
            'declared_assessed_value' => 30000.00,
            'documentary_value' => 31000.00,
        ]);

        $missing = $this->postJson("/api/v1/discoveries/{$discovery['id']}/submit");
        $missing->assertStatus(422);
        $this->assertStringContainsString('ownership', $missing->json('message'));

        $this->attachEvidence($discovery['id'], PropertyDiscoveryEvidence::TYPE_OWNERSHIP_DOCUMENT)->assertStatus(200);

        $submitted = $this->postJson("/api/v1/discoveries/{$discovery['id']}/submit")->assertOk()->json('data');
        $this->assertSame('DOCUMENTARY_VALUE', $submitted['value_declaration_type']);
        $this->assertSame('account', $submitted['decision_path']);
        $this->assertSame(30000.0, (float) $submitted['declared_assessed_value']);
        $this->assertSame(31000.0, (float) $submitted['documentary_value']);

        $this->assertTrue(
            PropertyDiscoveryEvidence::where('discovery_id', $discovery['id'])
                ->where('evidence_type', PropertyDiscoveryEvidence::TYPE_OWNERSHIP_DOCUMENT)->exists()
        );
    }

    public function test_up_to_three_photos_and_one_ownership_document_are_enforced(): void
    {
        Sanctum::actingAs($this->officer());

        $discovery = $this->create(['value_declaration_type' => 'SELF_DECLARED']);

        foreach (range(1, 3) as $i) {
            $this->attachEvidence($discovery['id'], PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO, $i)->assertStatus(200);
        }
        $this->attachEvidence($discovery['id'], PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO, 4)->assertStatus(422);

        $this->attachEvidence($discovery['id'], PropertyDiscoveryEvidence::TYPE_OWNERSHIP_DOCUMENT)->assertStatus(200);
        $this->attachEvidence($discovery['id'], PropertyDiscoveryEvidence::TYPE_OWNERSHIP_DOCUMENT)->assertStatus(422);

        $this->assertSame(3, PropertyDiscoveryEvidence::where('discovery_id', $discovery['id'])
            ->where('evidence_type', PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO)->count());
        $this->assertSame(1, PropertyDiscoveryEvidence::where('discovery_id', $discovery['id'])
            ->where('evidence_type', PropertyDiscoveryEvidence::TYPE_OWNERSHIP_DOCUMENT)->count());

        $photo = PropertyDiscoveryEvidence::where('discovery_id', $discovery['id'])
            ->where('evidence_type', PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO)->firstOrFail();

        $this->deleteJson("/api/v1/discoveries/{$discovery['id']}/evidence/{$photo->id}")->assertStatus(200);
        $this->assertSame(2, PropertyDiscoveryEvidence::where('discovery_id', $discovery['id'])
            ->where('evidence_type', PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO)->count());
    }

    public function test_valuation_value_cannot_be_fabricated_but_routes_for_formal_valuation(): void
    {
        Sanctum::actingAs($this->officer());

        $withValue = $this->create([
            'value_declaration_type' => 'VALUATION_VALUE',
            'declared_assessed_value' => 5000.00,
        ]);
        $this->postJson("/api/v1/discoveries/{$withValue['id']}/submit")->assertStatus(422);

        $noValue = $this->create(['value_declaration_type' => 'VALUATION_VALUE']);
        $submitted = $this->postJson("/api/v1/discoveries/{$noValue['id']}/submit")->assertOk()->json('data');
        $this->assertSame('valuation', $submitted['decision_path']);
        $this->assertSame(PropertyDiscovery::STATUS_AWAITING_VALUATION, $submitted['status']);
        $this->assertNull($submitted['declared_assessed_value']);
    }

    public function test_evidence_is_role_scoped_and_mutation_status_guarded(): void
    {
        Sanctum::actingAs($this->officer());

        $discovery = $this->create([
            'value_declaration_type' => 'SELF_DECLARED',
            'declared_assessed_value' => 1000.00,
        ]);
        $this->attachEvidence($discovery['id'], PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO)->assertStatus(200);

        // No discovery permission → cannot attach or even read the record.
        Sanctum::actingAs(User::where('email', 'enf.officer@test.lra')->firstOrFail());
        $this->attachEvidence($discovery['id'], PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO)->assertStatus(403);
        $this->get("/api/v1/discoveries/{$discovery['id']}")->assertStatus(403);

        // A different account officer cannot add evidence to someone else's discovery.
        Sanctum::actingAs($this->secondAccountOfficer());
        $this->attachEvidence($discovery['id'], PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO)->assertStatus(403);

        // Even the recording officer loses the mutate right once submitted.
        Sanctum::actingAs($this->officer());
        $this->postJson("/api/v1/discoveries/{$discovery['id']}/submit")->assertOk();
        $this->attachEvidence($discovery['id'], PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO)->assertStatus(422);
    }

    public function test_ac_approval_sets_official_assessed_value_and_preserves_declared(): void
    {
        Sanctum::actingAs($this->officer());

        $discovery = $this->create([
            'value_declaration_type' => 'SELF_DECLARED',
            'declared_assessed_value' => 40000.00,
        ]);
        $this->postJson("/api/v1/discoveries/{$discovery['id']}/submit")->assertOk();

        // A&R desk determines a formal valuation is required after all.
        Sanctum::actingAs($this->manager());
        $this->postJson("/api/v1/discoveries/{$discovery['id']}/acct-review", [
            'decision' => 'refer_to_valuation',
            'remarks' => 'Requires formal valuation of the structure.',
        ])->assertOk();
        $this->assertSame('valuation', PropertyDiscovery::findOrFail($discovery['id'])->decision_path);

        // Valuation Manager routes to the Valuation Officer.
        Sanctum::actingAs($this->valManager());
        $this->postJson("/api/v1/discoveries/{$discovery['id']}/val-review", [
            'decision' => 'assign',
            'officer_id' => $this->valOfficer()->id,
        ])->assertOk();
        $valuation = Valuation::where('discovery_id', $discovery['id'])->firstOrFail();

        // Officer completes the assessment and submits.
        Sanctum::actingAs($this->valOfficer());
        $this->postJson("/api/v1/valuations/{$valuation->id}/descriptions", [
            'descriptions' => [[
                'description' => 'Land',
                'level' => 'Land',
                'tar' => 5000.00,
                'quantity' => 1,
            ], [
                'description' => 'Main structure',
                'level' => 'Ground Floor',
                'area_sqft' => 1200,
                'tar' => 28.0,
                'quantity' => 1,
                'amount' => 95000.00,
                'depreciation_pct' => 0,
            ]],
        ])->assertOk();
        $this->putJson("/api/v1/valuations/{$valuation->id}", ['photos' => 'proof://VAL-VAL-0001'])->assertOk();
        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'assessed_value' => 95000.00,
            'annual_tax' => 2850.00,
            'applicable_tax_rate' => 3.0,
        ])->assertOk();

        // Valuation Manager forwards to AC.
        Sanctum::actingAs($this->valManager());
        $this->postJson("/api/v1/valuations/{$valuation->id}/review", ['decision' => 'confirm_ac'])->assertOk();

        // AC approves → official value lands on the discovery; declared stays put.
        Sanctum::actingAs($this->ac());
        $this->postJson("/api/v1/valuations/{$valuation->id}/decide", ['decision' => 'approve', 'remarks' => 'Approved.'])->assertOk();

        $fresh = PropertyDiscovery::findOrFail($discovery['id']);
        $this->assertSame(95000.0, (float) $fresh->official_assessed_value);
        $this->assertSame(40000.0, (float) $fresh->declared_assessed_value);
        $this->assertSame(40000.0, (float) $fresh->declared_value);
        $this->assertSame(PropertyDiscovery::STATUS_APPROVED, $fresh->status);

        $log = AuditLog::where('auditable_type', PropertyDiscovery::class)
            ->where('auditable_id', $fresh->id)->where('action', 'discovery.official_assessed_value_set')
            ->latest('id')->firstOrFail();
        $this->assertSame(95000.0, (float) $log->new_values['official_assessed_value']);
        $this->assertSame((int) $valuation->id, (int) $log->new_values['source_valuation_id']);
        $this->assertSame(40000.0, (float) $log->new_values['declared_assessed_value_preserved']);

        // The discovery view exposes both numbers to AC/review desks.
        $detail = $this->getJson("/api/v1/discoveries/{$discovery['id']}")->assertOk()->json('data');
        $this->assertSame(40000.0, (float) $detail['declared_assessed_value']);
        $this->assertSame(95000.0, (float) $detail['official_assessed_value']);
    }

    public function test_index_filters_and_stats_break_out_by_declaration_type(): void
    {
        Sanctum::actingAs($this->officer());

        $this->create(['value_declaration_type' => 'SELF_DECLARED', 'declared_assessed_value' => 5000.0, 'owner_name' => 'Owner A']);
        $this->create(['value_declaration_type' => 'DOCUMENTARY_VALUE', 'declared_assessed_value' => 40000.0, 'owner_name' => 'Owner B']);
        $this->create(['value_declaration_type' => 'VALUATION_VALUE', 'owner_name' => 'Owner C']);

        $selfDeclared = $this->getJson('/api/v1/discoveries?value_declaration_type=SELF_DECLARED')->assertOk()->json('data');
        $this->assertCount(1, $selfDeclared['data']);

        $range = $this->getJson('/api/v1/discoveries?value_min=10000&value_max=45000')->assertOk()->json('data');
        $this->assertCount(1, $range['data']);
        $this->assertSame('DOCUMENTARY_VALUE', $range['data'][0]['value_declaration_type']);

        $stats = $this->getJson('/api/v1/discoveries/stats')->assertOk()->json('data');
        $this->assertSame(1, $stats['by_value_declaration_type']['SELF_DECLARED']['count']);
        $this->assertSame(1, $stats['by_value_declaration_type']['DOCUMENTARY_VALUE']['count']);
        $this->assertSame(1, $stats['by_value_declaration_type']['VALUATION_VALUE']['count']);
        $this->assertSame(45000.0, (float) $stats['value_totals']['declared_total']);
    }

    public function test_registration_bill_uses_official_value_ahead_of_declared(): void
    {
        Sanctum::actingAs($this->officer());

        $created = $this->create([
            'value_declaration_type' => 'SELF_DECLARED',
            'declared_assessed_value' => 10000.00,
        ]);
        $discovery = PropertyDiscovery::findOrFail($created['id']);
        $discovery->update(['official_assessed_value' => 60000.00]);
        $this->postJson("/api/v1/discoveries/{$discovery->id}/submit")->assertOk();

        Sanctum::actingAs($this->manager());
        $this->postJson("/api/v1/discoveries/{$discovery->id}/acct-review", [
            'decision' => 'approve',
            'officer_id' => $this->officer()->id,
        ])->assertOk();

        Sanctum::actingAs($this->officer());
        $this->postJson("/api/v1/discoveries/{$discovery->id}/account-processing", [
            'property_id' => 'REG-VAL-1',
            'document_number' => '2026/REG-VAL-1',
            'property_tax_amount' => 1800.0,
            'tax_period' => '2026',
        ])->assertOk();

        $bill = PropertyBill::where('document_number', '2026/REG-VAL-1')->firstOrFail();
        $this->assertSame(60000.0, (float) $bill->assessed_value);
        $this->assertSame(1800.0, (float) $bill->tax_amount);

        $fresh = PropertyDiscovery::findOrFail($discovery->id);
        $this->assertSame(10000.0, (float) $fresh->declared_assessed_value);
    }
}
