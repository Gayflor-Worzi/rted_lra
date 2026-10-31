<?php

namespace Tests\Feature;

use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The approved assessment is the authority for the property bill.
 *
 * The bill register used to be written from the discovery as it stood when the
 * property was recorded, and the tax was whatever the officer typed at the
 * registration prompt. A valuation-side correction to the owner, address or
 * classification therefore never reached the register, and a tax figure with no
 * support in any approved record could be sent to Enforcement.
 *
 * These tests pin the reconciled behaviour for the discovery-linked path:
 * approved details reach the discovery and the bill, and a tax amount that
 * contradicts the approved assessment is rejected before anything is written.
 */
class ApprovedValuationBillConsistencyTest extends TestCase
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

    /**
     * A commercial discovery whose assessment corrects the owner, address and
     * classification, approved by the Assistant Commissioner.
     *
     * @return array{0: PropertyDiscovery, 1: Valuation}
     */
    private function approvedDiscovery(string $suffix = 'AVB'): array
    {
        $this->act('account.officer@test.lra');

        $discoveryId = $this->postJson('/api/v1/discoveries', [
            'owner_name' => 'Recorded Owner '.$suffix,
            'owner_contact' => '0770-100-150',
            'tin' => 'TIN-'.$suffix,
            'property_address' => 'Original Address, Monrovia',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'property_classification' => 'Commercial',
            'property_type' => 'House',
            'occupancy_use' => 'Commercial',
            'discovery_date' => now()->toDateString(),
            'gps_coordinate' => '6.3130,-10.8074',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/discoveries/{$discoveryId}/submit")->assertOk();

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discoveryId}/val-review", [
            'decision' => 'assign',
            'officer_id' => $this->user('val.officer@test.lra')->id,
        ])->assertOk();

        $valuation = Valuation::where('discovery_id', $discoveryId)->firstOrFail();

        $this->act('val.officer@test.lra');
        $this->putJson("/api/v1/valuations/{$valuation->id}", [
            'owner_name' => 'Approved Owner '.$suffix,
            'owner_contact' => '0770-100-151',
            'property_classification' => 'Industrial',
            'property_address' => 'Corrected Address, Monrovia',
        ])->assertOk();

        $this->postJson("/api/v1/valuations/{$valuation->id}/descriptions", [
            'descriptions' => [[
                'description' => 'Land',
                'level' => 'Land',
                'tar' => 5000.00,
                'quantity' => 1,
            ]],
        ])->assertOk();

        $this->putJson("/api/v1/valuations/{$valuation->id}", [
            'photos' => 'proof://VAL-'.$suffix,
        ])->assertOk();

        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'assessed_value' => 1500000.00,
            'annual_tax' => 52500.00,
            'applicable_tax_rate' => 3.5,
        ])->assertOk();

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/review", ['decision' => 'confirm_ac'])->assertOk();

        $this->act('ac@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/decide", ['decision' => 'approve'])->assertOk();

        return [PropertyDiscovery::findOrFail($discoveryId), $valuation->fresh()];
    }

    /** Send the approved assessment to the account officer for registration. */
    private function assignForProcessing(Valuation $valuation): void
    {
        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/account-review", [
            'decision' => 'approve',
            'officer_id' => $this->user('account.officer@test.lra')->id,
        ])->assertOk();
    }

    public function test_approved_details_reach_the_discovery_and_the_bill(): void
    {
        [$discovery, $valuation] = $this->approvedDiscovery();

        $discovery->refresh();
        $this->assertSame('Approved Owner AVB', $discovery->owner_name, 'The approved owner replaces the recorded one.');
        $this->assertSame('Corrected Address, Monrovia', $discovery->property_address);
        $this->assertSame('Industrial', $discovery->property_classification);
        $this->assertSame(1500000.00, (float) $discovery->official_assessed_value);

        $this->assignForProcessing($valuation);

        $this->act('account.officer@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/account-processing", [
            'property_id' => 'AVB-P-1',
            'document_number' => '2026/AVB-1',
            'property_tax_amount' => 52500.00,
            'tax_period' => '2026',
        ])->assertOk();

        $bill = PropertyBill::where('property_id', 'AVB-P-1')->firstOrFail();

        $this->assertSame('Approved Owner AVB', $bill->taxpayer_name);
        $this->assertSame('Corrected Address, Monrovia', $bill->property_address);
        $this->assertSame('Industrial', $bill->property_classification);
        $this->assertSame(1500000.00, (float) $bill->assessed_value);
        $this->assertSame(52500.00, (float) $bill->tax_amount, 'The bill carries the approved annual tax.');
        $this->assertSame(52500.00, (float) $bill->total_tax_due);
        $this->assertSame(52500.00, (float) $bill->outstanding_balance);
        $this->assertSame(1, PropertyBill::count());
    }

    public function test_a_tax_amount_that_contradicts_the_approved_assessment_is_rejected(): void
    {
        [$discovery, $valuation] = $this->approvedDiscovery('AVC');

        $this->assignForProcessing($valuation);

        $this->act('account.officer@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/account-processing", [
            'property_id' => 'AVC-P-1',
            'document_number' => '2026/AVC-1',
            'property_tax_amount' => 1000.00,
            'tax_period' => '2026',
        ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'does not match the approved valuation'));

        // Rejected before anything was written: no bill, no closed task, and the
        // discovery is still the officer's open processing work.
        $this->assertSame(0, PropertyBill::count());
        $discovery->refresh();
        $this->assertSame(PropertyDiscovery::STATUS_ASSIGNED_ACCT, $discovery->status);
        $this->assertSame(1, Task::where('task_type', Task::TYPE_ACCOUNT_PROCESSING)
            ->where('reference_type', 'property_discovery')
            ->where('reference_id', $discovery->id)
            ->whereNotIn('status', Task::COMPLETED_STATUSES)
            ->count());

        // The approved amount is accepted and reconciles onto the register.
        $this->postJson("/api/v1/discoveries/{$discovery->id}/account-processing", [
            'property_id' => 'AVC-P-1',
            'document_number' => '2026/AVC-1',
            'property_tax_amount' => 52500.00,
            'tax_period' => '2026',
        ])->assertOk();

        $this->assertSame(52500.00, (float) PropertyBill::where('property_id', 'AVC-P-1')->value('tax_amount'));
    }
}
