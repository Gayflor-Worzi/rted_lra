<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 1 — permanent property identity (Source Reference ID).
 *
 * A property is discovered once and then assessed, registered and reassessed
 * many times. The section-specific identifiers (ND-…, VAL-…, source Property
 * ID, Document #) are not interchangeable and the source Property ID does not
 * exist until registration, so before this the in-flight discovery and its
 * assessment had no shared identifier at all.
 *
 * These tests pin the contract:
 *   - the identity is assigned once, at first recording, and never changes;
 *   - discovery, valuation and bill for one property carry the same identity;
 *   - the section identifiers are preserved alongside it, not replaced;
 *   - the reference is searchable and resolves the whole property;
 *   - a reference never grants access the caller did not already have;
 *   - similar addresses/owners do not merge two properties.
 */
class PropertyIdentityTest extends TestCase
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

    private function officer(): User
    {
        return $this->user('account.officer@test.lra');
    }

    private function manager(): User
    {
        return $this->user('account.manager@test.lra');
    }

    private function valManager(): User
    {
        return $this->user('val.manager@test.lra');
    }

    /** An own-scope officer with discovery.view who does not own this property. */
    private function unrelatedOfficer(): User
    {
        return $this->user('val.officer@test.lra');
    }

    private function createDiscovery(array $overrides = []): array
    {
        Sanctum::actingAs($this->officer());

        return $this->postJson('/api/v1/discoveries', array_merge([
            'owner_name' => 'Moses Kollie',
            'owner_contact' => '0777000111',
            'tin' => 'TIN-PRP-001',
            'property_address' => '14 Water Street, Monrovia',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'property_classification' => 'Residential',
            'property_type' => 'House',
            'occupancy_use' => 'Residential',
            'gps_coordinate' => '6.3130,-10.8074',
            'discovery_date' => now()->toDateString(),
        ], $overrides))->assertStatus(201)->json('data');
    }

    /** Submit, then route to an officer from the Valuation Manager desk. */
    private function routeToValuation(array $discovery): Valuation
    {
        Sanctum::actingAs($this->officer());
        $this->postJson("/api/v1/discoveries/{$discovery['id']}/submit")->assertStatus(200);

        Sanctum::actingAs($this->valManager());
        $this->postJson("/api/v1/discoveries/{$discovery['id']}/val-review", [
            'decision' => 'assign',
            'officer_id' => $this->unrelatedOfficer()->id,
        ])->assertStatus(200);

        return Valuation::where('discovery_id', $discovery['id'])->firstOrFail();
    }

    /** A property the classifier routes to the valuation desk rather than A&R. */
    private function valuationDiscovery(): array
    {
        return $this->createDiscovery([
            'property_address' => '9 Broad Street, Monrovia',
            'property_classification' => 'Commercial',
            'property_type' => 'Commercial',
            'occupancy_use' => 'Commercial',
            'value_declaration_type' => PropertyDiscovery::VALUE_DECLARATION_VALUATION,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Identity is created once and never changes */
    /* ------------------------------------------------------------------ */

    public function test_a_discovery_receives_a_permanent_source_reference_when_first_recorded(): void
    {
        $data = $this->createDiscovery();

        $this->assertMatchesRegularExpression(
            '/^PRP-\d{4}-\d{5}$/',
            (string) ($data['source_reference'] ?? ''),
            'A property must carry a PRP-YYYY-##### Source Reference ID from its first recording.'
        );

        // The section identifier is preserved next to it, not replaced by it.
        $this->assertMatchesRegularExpression('/^ND-\d{4}-\d{5}$/', (string) $data['discovery_reference']);
    }

    public function test_the_source_reference_survives_every_lifecycle_transition(): void
    {
        $discovery = $this->valuationDiscovery();
        $reference = $discovery['source_reference'];

        $this->routeToValuation($discovery);

        $this->assertSame(
            $reference,
            PropertyDiscovery::findOrFail($discovery['id'])->propertyRef->source_reference,
            'Routing to valuation must not change the property identity.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* One identity across discovery, valuation and bill */
    /* ------------------------------------------------------------------ */

    public function test_a_valuation_inherits_the_discoverys_identity(): void
    {
        $discovery = $this->valuationDiscovery();

        $valuation = $this->routeToValuation($discovery);

        $this->assertSame(
            PropertyDiscovery::findOrFail($discovery['id'])->property_ref_id,
            $valuation->property_ref_id,
            'A discovery-origin assessment belongs to the discovery property.'
        );

        // The valuation keeps its own identifier.
        $this->assertMatchesRegularExpression('/^VAL-\d{4}-\d{5}$/', $valuation->valuation_reference);
    }

    public function test_registration_stamps_the_source_property_id_on_the_one_identity(): void
    {
        // Approve the discovery for registration and hand it to the officer.
        $discovery = $this->createDiscovery();

        Sanctum::actingAs($this->officer());
        $this->postJson("/api/v1/discoveries/{$discovery['id']}/submit")->assertStatus(200);

        Sanctum::actingAs($this->manager());
        $this->postJson("/api/v1/discoveries/{$discovery['id']}/acct-review", [
            'decision' => 'approve',
            'officer_id' => $this->officer()->id,
        ])->assertStatus(200);

        Sanctum::actingAs($this->officer());
        $this->postJson("/api/v1/discoveries/{$discovery['id']}/account-processing", [
            'property_id' => 'SRC-PRP-1',
            'document_number' => 'DOC-PRP-1',
            'property_tax_amount' => 900,
            'tax_period' => '2026',
        ])->assertStatus(200);

        $row = PropertyDiscovery::findOrFail($discovery['id']);
        $bill = PropertyBill::where('document_number', 'DOC-PRP-1')->firstOrFail();

        $this->assertSame($row->property_ref_id, $bill->property_ref_id, 'The logged bill must belong to the same property as its discovery.');

        $property = Property::findOrFail($row->property_ref_id);
        $this->assertSame('SRC-PRP-1', $property->property_id, 'Registration is the moment the source Property ID becomes known.');

        $this->assertSame(
            $property->source_reference,
            $row->propertyRef->source_reference,
            'The permanent reference is unchanged by registration.'
        );
        $this->assertSame($discovery['source_reference'], $property->source_reference);
    }

    /* ------------------------------------------------------------------ */
    /* Search and access control */
    /* ------------------------------------------------------------------ */

    public function test_the_property_can_be_found_by_its_source_reference(): void
    {
        $discovery = $this->createDiscovery();
        $reference = $discovery['source_reference'];

        Sanctum::actingAs($this->manager());

        $profile = $this->getJson('/api/v1/property-profile?source_reference='.urlencode($reference))
            ->assertOk()
            ->json('data');

        $this->assertSame($reference, $profile['identity']['source_reference']);
        $this->assertSame($discovery['id'], $profile['record']['discovery_id']);

        // The same identity is searchable from the discovery list.
        $rows = $this->getJson('/api/v1/discoveries?q='.urlencode($reference))->assertOk()->json('data.data');
        $this->assertSame([$discovery['id']], array_column($rows, 'id'));
    }

    public function test_an_unknown_source_reference_is_not_found(): void
    {
        Sanctum::actingAs($this->manager());

        $this->getJson('/api/v1/property-profile?source_reference=PRP-2026-99999')
            ->assertStatus(404);
    }

    public function test_knowing_the_source_reference_does_not_bypass_data_scope(): void
    {
        $discovery = $this->createDiscovery();
        $reference = $discovery['source_reference'];

        // Own-scope officer who neither recorded the discovery nor was assigned it.
        Sanctum::actingAs($this->unrelatedOfficer());

        $this->getJson('/api/v1/property-profile?source_reference='.urlencode($reference))
            ->assertStatus(403);
    }

    /* ------------------------------------------------------------------ */
    /* Similar evidence never merges two properties */
    /* ------------------------------------------------------------------ */

    public function test_identical_address_and_owner_do_not_merge_two_properties(): void
    {
        $first = $this->createDiscovery();
        $second = $this->createDiscovery();

        $this->assertNotSame(
            $first['source_reference'],
            $second['source_reference'],
            'Two recordings must never collapse into one property on address or owner similarity alone.'
        );

        $this->assertNotSame(
            PropertyDiscovery::findOrFail($first['id'])->property_ref_id,
            PropertyDiscovery::findOrFail($second['id'])->property_ref_id,
        );
    }

    public function test_records_carrying_the_same_source_property_id_share_one_identity(): void
    {
        // The source system is the authority on what "the same property" means:
        // once it has issued a Property ID, records carrying it are one property.
        $bill = PropertyBill::create([
            'document_number' => 'DOC-SHARED-1',
            'property_id' => 'SRC-SHARED-1',
            'taxpayer_name' => 'Shared Owner',
            'property_address' => '1 Shared Road',
            'tax_amount' => 100,
            'tax_period' => '2026',
            'date_logged' => now()->toDateString(),
        ]);

        $valuation = Valuation::create([
            'valuation_type' => 'reassessment',
            'bill_id' => $bill->id,
            'property_id' => 'SRC-SHARED-1',
            'owner_name' => 'Shared Owner',
            'property_address' => '1 Shared Road',
            'assessment_date' => now()->toDateString(),
        ]);

        $this->assertSame($bill->property_ref_id, $valuation->property_ref_id);
        $this->assertSame('PRP-'.now()->format('Y').'-', substr((string) $bill->propertyRef->source_reference, 0, 9));
    }
}
