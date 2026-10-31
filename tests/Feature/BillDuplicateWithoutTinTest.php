<?php

namespace Tests\Feature;

use App\Models\PropertyBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One assessment per property per tax period — even when a TIN is missing.
 *
 * A bill can reach the register without a TIN: a property discovery may not
 * carry one, and Account & Record processing logs the bill from the discovery.
 * The duplicate check used to require a TIN on both sides, so a TIN-less bill
 * was invisible to it and the same assessment could be logged a second time
 * (and the TIN-less record could then be overwritten by a second sync).
 *
 * These tests pin the corrected rule: Property ID + Tax Period identify the
 * assessment, the TIN is compared only where both records carry one, and the
 * register never loses a TIN it already holds.
 */
class BillDuplicateWithoutTinTest extends TestCase
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

    /** A bill already on the register with no TIN, as processing can leave it. */
    private function tinlessBill(string $propertyId, string $period, string $documentNumber = '2026/NO-TIN-1'): PropertyBill
    {
        return PropertyBill::create([
            'document_number' => $documentNumber,
            'property_id' => $propertyId,
            'taxpayer_name' => 'No-TIN Taxpayer',
            'tin' => null,
            'property_address' => '1 No-TIN Road, Monrovia',
            'tax_amount' => 3000.00,
            'total_tax_due' => 3000.00,
            'outstanding_balance' => 3000.00,
            'tax_period' => $period,
            'date_logged' => now()->toDateString(),
            'case_status' => 'Logged',
            'delivery_status' => 'Logged',
            'payment_status' => 'Unpaid',
        ]);
    }

    public function test_a_bill_already_logged_without_a_tin_blocks_the_same_assessment(): void
    {
        $this->tinlessBill('NT-P-1', '2026');

        $this->act('account.officer@test.lra');
        $this->postJson('/api/v1/property-bills', [
            'document_number' => '2026/NT-2',
            'property_id' => 'NT-P-1',
            'taxpayer_name' => 'No-TIN Taxpayer',
            'tin' => 'TIN-NT-2',
            'property_address' => '1 No-TIN Road, Monrovia',
            'tax_amount' => 3000.00,
            'tax_period' => '2026',
        ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'already logged'));

        $this->assertSame(1, PropertyBill::where('property_id', 'NT-P-1')->count());
    }

    public function test_two_bills_with_different_tins_for_one_property_and_period_stay_distinct(): void
    {
        $this->act('account.officer@test.lra');
        $this->postJson('/api/v1/property-bills', [
            'document_number' => '2026/DT-1',
            'property_id' => 'DT-P-1',
            'taxpayer_name' => 'First Taxpayer',
            'tin' => 'TIN-DT-A',
            'property_address' => '2 Different TIN Road, Monrovia',
            'tax_amount' => 1000.00,
            'tax_period' => '2026',
        ])->assertCreated();

        // A genuinely different taxpayer on the same property identifier is not
        // silently collapsed into the first record.
        $this->postJson('/api/v1/property-bills', [
            'document_number' => '2026/DT-2',
            'property_id' => 'DT-P-1',
            'taxpayer_name' => 'Second Taxpayer',
            'tin' => 'TIN-DT-B',
            'property_address' => '2 Different TIN Road, Monrovia',
            'tax_amount' => 2000.00,
            'tax_period' => '2026',
        ])->assertCreated();

        $this->assertSame(2, PropertyBill::where('property_id', 'DT-P-1')->count());
    }

    public function test_processing_a_tinless_discovery_updates_the_logged_bill_instead_of_duplicating_it(): void
    {
        $this->act('account.officer@test.lra');
        $this->postJson('/api/v1/property-bills', [
            'document_number' => '2026/KEEP-1',
            'property_id' => 'KEEP-P-1',
            'taxpayer_name' => 'Keep TIN Taxpayer',
            'tin' => 'TIN-KEEP',
            'property_address' => '3 Keep TIN Road, Monrovia',
            'tax_amount' => 2500.00,
            'tax_period' => '2026',
        ])->assertCreated();

        // A discovery for the same property with no TIN on record.
        $discovery = $this->postJson('/api/v1/discoveries', [
            'owner_name' => 'Keep TIN Taxpayer',
            'property_address' => '3 Keep TIN Road, Monrovia',
            'property_classification' => 'Residential',
            'property_type' => 'House',
            'occupancy_use' => 'Residential',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'discovery_date' => now()->toDateString(),
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/discoveries/{$discovery['id']}/submit")->assertOk();

        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery['id']}/acct-review", [
            'decision' => 'approve',
            'officer_id' => $this->user('account.officer@test.lra')->id,
        ])->assertOk();

        $this->act('account.officer@test.lra');
        $response = $this->postJson("/api/v1/discoveries/{$discovery['id']}/account-processing", [
            'property_id' => 'KEEP-P-1',
            'document_number' => '2026/KEEP-2',
            'property_tax_amount' => 2500.00,
            'tax_period' => '2026',
        ])->assertOk();

        $this->assertSame('updated', $response->json('data.bill_action'));
        $this->assertSame(
            1,
            PropertyBill::where('property_id', 'KEEP-P-1')->count(),
            'The TIN-less discovery must reconcile onto the bill already logged for the property.'
        );

        $bill = PropertyBill::where('property_id', 'KEEP-P-1')->firstOrFail();
        $this->assertSame('2026/KEEP-2', $bill->document_number);
        $this->assertSame('TIN-KEEP', $bill->tin, 'Processing must never erase the TIN the register already holds.');
    }
}
