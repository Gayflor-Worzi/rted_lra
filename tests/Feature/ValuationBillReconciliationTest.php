<?php

namespace Tests\Feature;

use App\Models\PropertyBill;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 4 — an approved reassessment reconciles onto the property bill.
 *
 * The bill register is where a property's assessment becomes money owed, and a
 * reassessment exists to change that assessment. Approval used to copy only the
 * assessed VALUE onto the bill and leave its tax due at the previous figure, so
 * the two records for one property disagreed, and the change was written with no
 * audit row at all.
 *
 * These tests pin the reconciled behaviour: value AND annual tax propagate, the
 * bill's own interest/penalty are preserved, derived totals and the balance
 * follow from the bill, and the propagation is audited.
 */
class ValuationBillReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function act(string $email): void
    {
        Sanctum::actingAs($this->user($email));
    }

    /** Log a bill for an existing property. */
    private function logBill(array $overrides = []): PropertyBill
    {
        $this->act('account.officer@test.lra');

        $this->postJson('/api/v1/property-bills', array_merge([
            'document_number' => '2026/REC-1',
            'property_id' => 'REC-P-1',
            'taxpayer_name' => 'Reconciliation Taxpayer',
            'tin' => 'TIN-REC-1',
            'property_address' => '12 Reconciliation Road, Monrovia',
            'tax_amount' => 5000.00,
            'tax_period' => '2026',
        ], $overrides))->assertCreated();

        return PropertyBill::where('document_number', $overrides['document_number'] ?? '2026/REC-1')->firstOrFail();
    }

    /** Take a reassessment of that bill through AC approval. */
    private function approveReassessment(PropertyBill $bill, float $assessedValue, float $annualTax): Valuation
    {
        $this->act('val.officer@test.lra');

        $valuationId = $this->postJson('/api/v1/valuations', [
            'valuation_type' => 'reassessment',
            'bill_id' => $bill->id,
            'property_id' => $bill->property_id,
            'document_number' => $bill->document_number,
            'owner_name' => 'Reconciliation Taxpayer',
            'owner_contact' => '0770-100-130',
            'tin' => $bill->tin,
            'property_classification' => 'Commercial',
            'property_address' => $bill->property_address,
            'assessment_date' => now()->toDateString(),
            'gps_coordinate' => '6.3130,-10.8074',
            'photos' => 'proof://VAL-REC-1',
            'descriptions' => [[
                'description' => 'Land',
                'level' => 'Land',
                'tar' => 5000.00,
                'quantity' => 1,
            ]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/valuations/{$valuationId}/submit", [
            'assessed_value' => $assessedValue,
            'annual_tax' => $annualTax,
            'applicable_tax_rate' => 3.5,
        ])->assertOk();

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuationId}/review", ['decision' => 'confirm_ac'])->assertOk();

        $this->act('ac@test.lra');
        $this->postJson("/api/v1/valuations/{$valuationId}/decide", ['decision' => 'approve'])->assertOk();

        return Valuation::findOrFail($valuationId);
    }

    public function test_an_approved_reassessment_reconciles_value_and_tax_onto_the_bill(): void
    {
        $bill = $this->logBill();

        $this->assertSame('5000.00', (string) $bill->tax_amount);
        $this->assertSame('5000.00', (string) $bill->total_tax_due);

        $valuation = $this->approveReassessment($bill->fresh(), 1500000.00, 52000.00);

        $bill->refresh();

        // The two records now agree about the same property.
        $this->assertSame((float) $valuation->assessed_value, (float) $bill->assessed_value);
        $this->assertSame((float) $valuation->annual_tax, (float) $bill->tax_amount, 'The approved annual tax must reach the register, not only the value.');
        $this->assertSame(52000.00, (float) $bill->total_tax_due);
        $this->assertSame(52000.00, (float) $bill->outstanding_balance);

        // Reconciled in place: no second bill, no second valuation.
        $this->assertSame(1, PropertyBill::count());
        $this->assertSame(1, Valuation::count());
    }

    public function test_reconciliation_preserves_the_bills_own_interest_and_penalty(): void
    {
        $bill = $this->logBill([
            'document_number' => '2026/REC-2',
            'property_id' => 'REC-P-2',
            'tin' => 'TIN-REC-2',
            'tax_amount' => 4000.00,
            'interest_charged' => 250.00,
            'penalty_charged' => 500.00,
        ]);

        $this->assertSame(4750.00, (float) $bill->total_tax_due);

        $this->approveReassessment($bill->fresh(), 900000.00, 30000.00);

        $bill->refresh();

        $this->assertSame(30000.00, (float) $bill->tax_amount);
        $this->assertSame(250.00, (float) $bill->interest_charged, 'Interest is the bill register\'s own figure and must survive reconciliation.');
        $this->assertSame(500.00, (float) $bill->penalty_charged, 'Penalty is the bill register\'s own figure and must survive reconciliation.');
        $this->assertSame(30750.00, (float) $bill->total_tax_due);
        $this->assertSame(30750.00, (float) $bill->outstanding_balance);
    }

    public function test_the_propagation_is_written_to_the_audit_chain(): void
    {
        $bill = $this->logBill([
            'document_number' => '2026/REC-3',
            'property_id' => 'REC-P-3',
            'tin' => 'TIN-REC-3',
        ]);

        $valuation = $this->approveReassessment($bill->fresh(), 1100000.00, 38000.00);

        $audit = DB::table('audit_logs')
            ->where('auditable_type', $bill->getMorphClass())
            ->where('auditable_id', $bill->id)
            ->where('action', 'bill.assessment_updated_from_valuation')
            ->first();

        $this->assertNotNull($audit, 'An AC approval that changes a property bill must be audited.');

        $after = json_decode($audit->new_values ?? '{}', true);
        $this->assertSame($valuation->id, $after['valuation_id'] ?? null);
        $this->assertEquals(38000.00, $after['tax_amount'] ?? null);
    }
}
