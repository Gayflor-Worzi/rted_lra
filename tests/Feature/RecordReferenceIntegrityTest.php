<?php

namespace Tests\Feature;

use App\Models\PropertyDiscovery;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

/**
 * Every discovered property and valuation carries a reference that is unique by
 * construction and permanent for the life of the record.
 *
 * The reference is the number the whole workflow is tracked by: it appears on task
 * labels, notifications, audit rows, exports and reports. A reference that could be
 * edited would put one record under two numbers and break its own history, and a
 * reference that could collide would make two properties indistinguishable. Both are
 * pinned here, along with the uniqueness the database enforces.
 */
class RecordReferenceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function act(string $email): void
    {
        Sanctum::actingAs(User::where('email', $email)->firstOrFail());
    }

    private function createDiscovery(string $suffix = 'A'): PropertyDiscovery
    {
        return PropertyDiscovery::create([
            'owner_name' => 'Ref Owner '.$suffix,
            'owner_contact' => '0770-000-'.substr(md5($suffix), 0, 3),
            'tin' => 'TIN-REF-'.substr(md5($suffix), 0, 5),
            'property_address' => 'Reference Way '.$suffix.', Monrovia',
            'property_classification' => 'Residential',
            'property_type' => 'House',
            'discovery_date' => now()->toDateString(),
            'gps_coordinate' => '6.3130,-10.8074',
        ]);
    }

    private function createValuation(string $suffix = 'A'): Valuation
    {
        return Valuation::create([
            'valuation_type' => 'regular',
            'owner_name' => 'Val Ref Owner '.$suffix,
            'owner_contact' => '0770-000-'.substr(md5($suffix), 0, 3),
            'tin' => 'TIN-VREF-'.substr(md5($suffix), 0, 5),
            'property_address' => 'Valuation Reference Way '.$suffix,
            'property_classification' => 'Commercial',
            'gps_coordinate' => '6.3130,-10.8074',
            'assessment_date' => now()->toDateString(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Generated, unique, and derived from the row id */
    /* ------------------------------------------------------------------ */

    public function test_every_record_gets_a_reference_in_the_canonical_form(): void
    {
        $discovery = $this->createDiscovery('A');
        $this->assertMatchesRegularExpression(
            '/^ND-\d{4}-\d{5}$/',
            $discovery->discovery_reference,
            'A discovery reference must be ND-YYYY-#####.'
        );

        $valuation = $this->createValuation('A');
        $this->assertMatchesRegularExpression(
            '/^VAL-\d{4}-\d{5}$/',
            $valuation->valuation_reference,
            'A valuation reference must be VAL-YYYY-#####.'
        );
    }

    public function test_the_reference_is_derived_from_the_row_id_so_two_records_can_never_collide(): void
    {
        $first = $this->createDiscovery('A');
        $second = $this->createDiscovery('B');

        $this->assertSame(sprintf('ND-%s-%05d', now()->format('Y'), $first->id), $first->discovery_reference);
        $this->assertSame(sprintf('ND-%s-%05d', now()->format('Y'), $second->id), $second->discovery_reference);
        $this->assertNotSame($first->discovery_reference, $second->discovery_reference);
    }

    public function test_references_are_unique_across_the_whole_table(): void
    {
        foreach (['A', 'B', 'C', 'D', 'E'] as $s) {
            $this->createDiscovery($s);
            $this->createValuation($s);
        }

        $this->assertSame(
            PropertyDiscovery::count(),
            PropertyDiscovery::distinct()->count('discovery_reference'),
            'Two discoveries must never share a reference.'
        );
        $this->assertSame(
            Valuation::count(),
            Valuation::distinct()->count('valuation_reference'),
            'Two valuations must never share a reference.'
        );
    }

    public function test_no_placeholder_reference_survives_creation(): void
    {
        $d = $this->createDiscovery('A');
        $v = $this->createValuation('A');

        // A placeholder is PREFIX-YYYY-XXXXXXXX (8 random chars); it is replaced with
        // the canonical number immediately after insert.
        $this->assertDoesNotMatchRegularExpression(
            '/^[A-Z]+-\d{4}-[A-Z0-9]{8}$/',
            $d->discovery_reference
        );
        $this->assertDoesNotMatchRegularExpression(
            '/^[A-Z]+-\d{4}-[A-Z0-9]{8}$/',
            $v->valuation_reference
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Permanent: the reference can never be changed */
    /* ------------------------------------------------------------------ */

    public function test_a_discovery_reference_cannot_be_changed(): void
    {
        $discovery = $this->createDiscovery('A');
        $original = $discovery->discovery_reference;

        // A direct attribute set bypasses mass-assignment protection entirely, so
        // this is the path the guard has to stop.
        $discovery->discovery_reference = 'ND-2026-99999';

        try {
            $discovery->save();
            $this->fail('Changing a discovery reference must be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('permanent', $e->getMessage());
        }

        $this->assertSame($original, $discovery->fresh()->discovery_reference);
    }

    public function test_a_valuation_reference_cannot_be_changed(): void
    {
        $valuation = $this->createValuation('A');
        $original = $valuation->valuation_reference;

        $valuation->valuation_reference = 'VAL-2026-99999';

        try {
            $valuation->save();
            $this->fail('Changing a valuation reference must be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('permanent', $e->getMessage());
        }

        $this->assertSame($original, $valuation->fresh()->valuation_reference);
    }

    public function test_a_reference_is_also_immune_to_mass_assignment(): void
    {
        $discovery = $this->createDiscovery('A');
        $original = $discovery->discovery_reference;

        // Belt and braces: fillable drops the key, so an update() that tries to set
        // it is ignored outright rather than refused.
        $discovery->update(['discovery_reference' => 'ND-2026-99999', 'owner_contact' => '0770-000-999']);
        $discovery->refresh();

        $this->assertSame($original, $discovery->discovery_reference);
        $this->assertSame('0770-000-999', $discovery->owner_contact, 'Other fields in the same update must still apply.');
    }

    public function test_a_reference_survives_ordinary_field_edits(): void
    {
        $discovery = $this->createDiscovery('A');
        $original = $discovery->discovery_reference;

        $discovery->update(['owner_contact' => '0771-111-222', 'property_type' => 'Apartment']);
        $discovery->refresh();

        $this->assertSame($original, $discovery->discovery_reference, 'Editing other fields must not disturb the reference.');
        $this->assertSame('0771-111-222', $discovery->owner_contact);
    }

    public function test_a_reference_survives_its_whole_workflow(): void
    {
        $this->act('account.officer@test.lra');
        $id = $this->postJson('/api/v1/discoveries', [
            'owner_name' => 'Workflow Ref Owner',
            'owner_contact' => '0770-555-666',
            'tin' => 'TIN-WF-0001',
            'property_address' => 'Workflow Reference Way, Monrovia',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'property_classification' => 'Commercial',
            'property_type' => 'Warehouse',
            'occupancy_use' => 'Storage',
            'discovery_date' => now()->toDateString(),
            'gps_coordinate' => '6.3130,-10.8074',
        ])->assertCreated()->json('data.id');

        $discovery = PropertyDiscovery::findOrFail($id);
        $atDiscovery = $discovery->discovery_reference;

        $this->postJson("/api/v1/discoveries/{$id}/submit")->assertOk();
        $this->assertSame($atDiscovery, $discovery->fresh()->discovery_reference, 'Submitting must not renumber the record.');

        // Carry it through to a valuation and confirm the discovery keeps its number.
        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$id}/val-review", [
            'decision' => 'assign',
            'officer_id' => User::where('email', 'val.officer@test.lra')->value('id'),
        ])->assertOk();

        $valuation = Valuation::findOrFail($discovery->fresh()->valuation_id);
        $atValuation = $valuation->valuation_reference;

        $this->assertSame($atDiscovery, $discovery->fresh()->discovery_reference);
        $this->assertMatchesRegularExpression('/^VAL-\d{4}-\d{5}$/', $atValuation);
    }

    public function test_the_api_cannot_set_a_reference_on_creation(): void
    {
        $this->act('account.officer@test.lra');
        $id = $this->postJson('/api/v1/discoveries', [
            'owner_name' => 'Injected Ref',
            'owner_contact' => '0770-777-888',
            'tin' => 'TIN-INJ-0001',
            'property_address' => 'Injection Way, Monrovia',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'property_classification' => 'Residential',
            'property_type' => 'House',
            'occupancy_use' => 'Residential',
            'discovery_date' => now()->toDateString(),
            'gps_coordinate' => '6.3130,-10.8074',
            // A caller trying to dictate its own number.
            'discovery_reference' => 'ND-1999-00001',
        ])->assertCreated()->json('data.id');

        $discovery = PropertyDiscovery::findOrFail($id);

        $this->assertNotSame('ND-1999-00001', $discovery->discovery_reference);
        $this->assertSame(sprintf('ND-%s-%05d', now()->format('Y'), $discovery->id), $discovery->discovery_reference);
    }

    public function test_the_api_cannot_rename_a_record(): void
    {
        $this->act('account.officer@test.lra');
        $id = $this->postJson('/api/v1/discoveries', [
            'owner_name' => 'Rename Attempt',
            'owner_contact' => '0770-999-000',
            'tin' => 'TIN-REN-0001',
            'property_address' => 'Rename Way, Monrovia',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'property_classification' => 'Residential',
            'property_type' => 'House',
            'occupancy_use' => 'Residential',
            'discovery_date' => now()->toDateString(),
            'gps_coordinate' => '6.3130,-10.8074',
        ])->assertCreated()->json('data.id');

        $before = PropertyDiscovery::findOrFail($id)->discovery_reference;

        // The update endpoint ignores an unknown/injected reference field entirely.
        $this->putJson("/api/v1/discoveries/{$id}", ['discovery_reference' => 'ND-1999-00002'])->assertOk();

        $this->assertSame($before, PropertyDiscovery::findOrFail($id)->discovery_reference);
    }

    public function test_the_reference_column_is_not_mass_assignable(): void
    {
        $this->assertNotContains('discovery_reference', (new PropertyDiscovery)->getFillable());
        $this->assertNotContains('valuation_reference', (new Valuation)->getFillable());
    }
}
