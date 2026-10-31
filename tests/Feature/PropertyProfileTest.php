<?php

namespace Tests\Feature;

use App\Models\EvidencePhoto;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\PropertyDiscoveryEvidence;
use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /property-profile is the canonical single-property aggregate that backs
 * the Property View, so it is the one endpoint that must resolve a property
 * regardless of which stage it has reached.
 */
class PropertyProfileTest extends TestCase
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

    /** Account & Records Officer: the narrow-scope officer who captures discoveries. */
    private function officer(): User
    {
        return $this->user('account.officer@test.lra');
    }

    /** Account & Record Manager: section scope, reviews the property lifecycle. */
    private function manager(): User
    {
        return $this->user('account.manager@test.lra');
    }

    /**
     * Own scope and holds discovery.view, but owns nothing on the property.
     * Used to prove the scope gate refuses rather than merely the permission gate.
     */
    private function unrelatedOfficer(): User
    {
        return $this->user('val.officer@test.lra');
    }

    private function discovery(array $overrides = []): PropertyDiscovery
    {
        return PropertyDiscovery::create(array_merge([
            'status' => PropertyDiscovery::STATUS_DRAFT,
            'owner_name' => 'Stephen Potter Sr.',
            'tin' => '123456789',
            'property_id' => '49661',
            'document_number' => '11467734',
            'property_address' => '12 Canal Road, Monrovia',
            'property_classification' => 'Commercial',
            'property_type' => 'Commercial',
            'value_declaration_type' => PropertyDiscovery::VALUE_DECLARATION_SELF_DECLARED,
            'declared_value' => 150000,
            'discovery_date' => '2026-09-12',
            'discovered_by' => $this->officer()->id,
        ], $overrides));
    }

    private function bill(array $overrides = []): PropertyBill
    {
        return PropertyBill::create(array_merge([
            'document_number' => '11467734',
            'property_id' => '49661',
            'taxpayer_name' => 'Stephen Potter Sr.',
            'tin' => '123456789',
            'property_address' => '12 Canal Road, Monrovia',
            'property_classification' => 'Commercial',
            'date_logged' => '2025-01-15',
            'tax_amount' => 1000,
            'total_tax_due' => 1000,
            'outstanding_balance' => 1000,
            'payment_status' => 'Unpaid',
            'case_status' => 'Logged',
        ], $overrides));
    }

    public function test_a_discovery_with_no_bill_yet_still_resolves(): void
    {
        // The regression this guards: identifiers were derived off the bill, so
        // an unregistered property - the common case - returned nothing.
        $this->discovery();

        Sanctum::actingAs($this->manager());

        $response = $this->getJson('/api/v1/property-profile?property_id=49661')->assertOk();

        $data = $response->json('data');

        $this->assertSame('49661', $data['identity']['property_id']);
        $this->assertSame('11467734', $data['identity']['document_number']);
        $this->assertSame('Stephen Potter Sr.', $data['identity']['owner_name']);
        $this->assertCount(1, $data['discoveries']);
        $this->assertSame([], $data['bills']);
        $this->assertSame('discovery', $data['record']['record_type']);
    }

    public function test_profile_spans_discovery_valuation_and_bill(): void
    {
        $bill = $this->bill();
        $discovery = $this->discovery([
            'status' => PropertyDiscovery::STATUS_COMPLETED,
        ]);

        $valuation = Valuation::create([
            'valuation_type' => 'new_property',
            'bill_id' => $bill->id,
            'discovery_id' => $discovery->id,
            'property_id' => '49661',
            'owner_name' => 'Stephen Potter Sr.',
            'assessed_value' => 175000,
            'annual_tax' => 2000,
            'status' => 'Approved',
        ]);

        Sanctum::actingAs($this->manager());

        $data = $this->getJson('/api/v1/property-profile?property_id=49661')->assertOk()->json('data');

        $this->assertCount(1, $data['bills']);
        $this->assertCount(1, $data['discoveries']);
        $this->assertCount(1, $data['valuations']);
        $this->assertSame($valuation->id, $data['valuations'][0]['id']);
        $this->assertSame(1, $data['related']['bills']);
        $this->assertSame(1, $data['related']['valuations']);
    }

    public function test_reassessment_is_reported_as_its_own_record_type(): void
    {
        $bill = $this->bill();

        Valuation::create([
            'valuation_type' => 'reassessment',
            'bill_id' => $bill->id,
            'property_id' => '49661',
            'owner_name' => 'Stephen Potter Sr.',
            'assessed_value' => 175000,
            'reassessed_value' => 175000,
            'status' => 'Under Review',
        ]);

        Sanctum::actingAs($this->manager());

        $data = $this->getJson('/api/v1/property-profile?property_id=49661')->assertOk()->json('data');

        $this->assertSame('reassessment', $data['record']['record_type']);
        $this->assertSame('reassessment', $data['valuations'][0]['record_type']);
        $this->assertSame(1, $data['related']['reassessments']);
    }

    public function test_values_are_reported_side_by_side_with_their_source(): void
    {
        $this->discovery([
            'value_declaration_type' => PropertyDiscovery::VALUE_DECLARATION_DOCUMENTARY,
            'declared_value' => 150000,
            'documentary_value' => 155000,
            'official_assessed_value' => 175000,
        ]);

        Sanctum::actingAs($this->manager());

        $value = $this->getJson('/api/v1/property-profile?property_id=49661')->assertOk()->json('data.value');

        // JSON numbers are untyped, so compare numerically rather than by identity
        $this->assertEquals(150000, $value['declared_value']);
        $this->assertEquals(155000, $value['documentary_value']);
        $this->assertEquals(175000, $value['official_assessed_value']);
        $this->assertSame('Value from Documentation', $value['declaration_label']);

        // a declared value is never overwritten by an assessed one
        $types = array_column($value['history'], 'type');
        $this->assertContains('Self Declared', $types);
        $this->assertContains('Documentary', $types);
    }

    public function test_candidate_gps_is_not_presented_as_official(): void
    {
        // gps_verified is a nullable string column (yes | no | null), not a boolean
        $this->discovery([
            'gps_coordinate' => '6.2908, -10.7607',
            'gps_accuracy' => 8.4,
            'gps_verified' => 'no',
        ]);

        Sanctum::actingAs($this->manager());

        $gps = $this->getJson('/api/v1/property-profile?property_id=49661')->assertOk()->json('data.gps');

        $this->assertSame('6.2908, -10.7607', $gps['coordinate']);
        $this->assertSame(8.4, $gps['accuracy']);
        $this->assertFalse($gps['certified'], "A rejected coordinate ('no') was reported as certified");
        $this->assertFalse($gps['is_official']);
        $this->assertSame('Official GPS Not Yet Certified', $gps['official_label']);
    }

    public function test_pending_gps_is_not_presented_as_official(): void
    {
        $this->discovery([
            'gps_coordinate' => '6.2908, -10.7607',
            'gps_verified' => null,
        ]);

        Sanctum::actingAs($this->manager());

        $gps = $this->getJson('/api/v1/property-profile?property_id=49661')->assertOk()->json('data.gps');

        $this->assertFalse($gps['is_official']);
    }

    public function test_certified_gps_is_reported_as_official(): void
    {
        $this->discovery([
            'gps_coordinate' => '6.2908, -10.7607',
            'gps_verified' => 'yes',
            'gps_certified_at' => '2026-09-20 10:00:00',
        ]);

        Sanctum::actingAs($this->manager());

        $gps = $this->getJson('/api/v1/property-profile?property_id=49661')->assertOk()->json('data.gps');

        $this->assertTrue($gps['is_official']);
        $this->assertSame('Official Property GPS', $gps['official_label']);
    }

    public function test_the_certified_location_of_record_wins_over_a_field_candidate(): void
    {
        $this->discovery([
            'property_id' => '49661',
            'gps_coordinate' => '6.2908, -10.7607',
            'gps_verified' => 'no',
        ]);

        DB::table('gps_locations')->insert([
            'property_id' => '49661',
            'latitude' => 6.3000000,
            'longitude' => -10.8000000,
            'accuracy' => 3.2,
            'source' => 'valuation',
            'verification_status' => 'verified',
            'certified_by' => $this->officer()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->manager());

        $gps = $this->getJson('/api/v1/property-profile?property_id=49661')->assertOk()->json('data.gps');

        $this->assertTrue($gps['is_official']);
        $this->assertSame('6.3, -10.8', $gps['coordinate']);
        $this->assertEquals(3.2, $gps['accuracy']);
    }

    public function test_an_unverified_gps_locations_row_is_not_treated_as_official(): void
    {
        $this->discovery(['property_id' => '49661']);

        DB::table('gps_locations')->insert([
            'property_id' => '49661',
            'latitude' => 6.3000000,
            'longitude' => -10.8000000,
            'source' => 'enforcement_visit',
            'verification_status' => 'pending_review',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->manager());

        $this->assertFalse(
            $this->getJson('/api/v1/property-profile?property_id=49661')->assertOk()->json('data.gps.is_official')
        );
    }

    public function test_photos_and_documents_expose_gated_urls_not_storage_paths(): void
    {
        $discovery = $this->discovery();

        foreach ([1, 2, 3] as $seq) {
            PropertyDiscoveryEvidence::create([
                'discovery_id' => $discovery->id,
                'property_id' => '49661',
                'evidence_type' => PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO,
                'sequence_no' => $seq,
                'file_path' => "private/discovery/{$discovery->id}/photo{$seq}.jpg",
                'original_name' => "photo{$seq}.jpg",
                'mime_type' => 'image/jpeg',
                'size_bytes' => 1024,
                'capture_source' => PropertyDiscoveryEvidence::SOURCE_CAMERA,
                'captured_at' => '2026-09-12 09:00:00',
            ]);
        }

        PropertyDiscoveryEvidence::create([
            'discovery_id' => $discovery->id,
            'property_id' => '49661',
            'evidence_type' => PropertyDiscoveryEvidence::TYPE_OWNERSHIP_DOCUMENT,
            'file_path' => "private/discovery/{$discovery->id}/deed.pdf",
            'original_name' => 'Deed_49661.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'capture_source' => PropertyDiscoveryEvidence::SOURCE_UPLOAD,
            'captured_at' => '2026-09-12 09:30:00',
        ]);

        Sanctum::actingAs($this->manager());

        $data = $this->getJson('/api/v1/property-profile?property_id=49661')->assertOk()->json('data');

        $this->assertCount(3, $data['photos']);
        $this->assertCount(1, $data['evidence']);
        $this->assertTrue($data['photos'][0]['is_primary']);

        $encoded = json_encode($data);

        // the storage path must never reach the client
        $this->assertStringNotContainsString('private/discovery', (string) $encoded);
        $this->assertStringNotContainsString('file_path', (string) $encoded);

        // the URL is the gated controller route, which re-checks scope
        $this->assertStringStartsWith(
            '/api/v1/discoveries/'.$discovery->id.'/evidence/',
            $data['photos'][0]['url']
        );
    }

    /**
     * The discovery record is what the Property View reads in record mode (the
     * account/enforcement queues). It reported "Field photos (0)" against three
     * PROPERTY_PHOTO rows because the count was taken from the unrelated legacy
     * evidence_photos store. These guard the record-mode split.
     */
    public function test_the_discovery_record_reports_its_own_photographs_and_documents(): void
    {
        $discovery = $this->discovery();

        foreach ([1, 2, 3] as $seq) {
            PropertyDiscoveryEvidence::create([
                'discovery_id' => $discovery->id,
                'property_id' => '49661',
                'evidence_type' => PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO,
                'sequence_no' => $seq,
                'file_path' => "private/discovery/{$discovery->id}/photo{$seq}.jpg",
                'original_name' => "photo{$seq}.jpg",
                'mime_type' => 'image/jpeg',
                'size_bytes' => 1024,
                'capture_source' => PropertyDiscoveryEvidence::SOURCE_CAMERA,
                'captured_at' => '2026-09-12 09:00:00',
                'captured_by' => $this->officer()->id,
                'gps_latitude' => 6.264642,
                'gps_longitude' => -10.704618,
            ]);
        }

        // A deed that happens to be a .jpg: the evidence_type column decides the
        // grouping, never the file extension.
        PropertyDiscoveryEvidence::create([
            'discovery_id' => $discovery->id,
            'property_id' => '49661',
            'evidence_type' => PropertyDiscoveryEvidence::TYPE_OWNERSHIP_DOCUMENT,
            'file_path' => "private/discovery/{$discovery->id}/deed.jpg",
            'original_name' => 'Deed_49661.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 2048,
            'capture_source' => PropertyDiscoveryEvidence::SOURCE_UPLOAD,
            'captured_at' => '2026-09-12 09:30:00',
        ]);

        Sanctum::actingAs($this->manager());

        $evidence = $this->getJson('/api/v1/discoveries/'.$discovery->id)
            ->assertOk()
            ->json('data.evidence');

        $photos = array_values(array_filter($evidence, fn ($e) => $e['evidence_type'] === 'PROPERTY_PHOTO'));
        $documents = array_values(array_filter($evidence, fn ($e) => $e['evidence_type'] !== 'PROPERTY_PHOTO'));

        $this->assertCount(4, $evidence);
        $this->assertCount(3, $photos, 'three property photographs must be counted from their own rows');
        $this->assertCount(1, $documents, 'the ownership deed is documentary evidence, not a photograph');

        // Photograph provenance the gallery renders.
        $this->assertSame(6.264642, $photos[0]['gps_latitude']);
        $this->assertSame(-10.704618, $photos[0]['gps_longitude']);
        $this->assertNotNull($photos[0]['captured_by_name']);
        $this->assertNotNull($photos[0]['captured_at']);

        // Every gated link must sit under the versioned API prefix or it 404s.
        foreach (array_merge($photos, $documents) as $row) {
            $this->assertStringStartsWith('/api/v1/discoveries/'.$discovery->id.'/evidence/', $row['url']);
        }

        $encoded = (string) json_encode($evidence);
        $this->assertStringNotContainsString('private/discovery', $encoded);
        $this->assertStringNotContainsString('file_path', $encoded);
    }

    /**
     * The whole discovery payload, not just the evidence slice, must not leak a
     * storage path. The legacy evidence_photos store is the valuation/visit
     * store and is surfaced as a clearly-named fallback, never as the
     * discovery's own property photographs.
     */
    public function test_the_discovery_payload_leaks_no_storage_path_and_separates_the_legacy_store(): void
    {
        $discovery = $this->discovery();

        // A row in the store that belongs to valuations and enforcement visits.
        $legacy = EvidencePhoto::create([
            'discovery_id' => $discovery->id,
            'photo_reference' => 'LEGACY-1',
            'photo_type' => 'front',
            'file_path' => "private/visits/{$discovery->id}/legacy.jpg",
            'mime' => 'image/jpeg',
            'captured_at' => '2026-09-01 08:00:00',
        ]);

        PropertyDiscoveryEvidence::create([
            'discovery_id' => $discovery->id,
            'property_id' => '49661',
            'evidence_type' => PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO,
            'sequence_no' => 1,
            'file_path' => "private/discovery/{$discovery->id}/photo1.jpg",
            'original_name' => 'photo1.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'capture_source' => PropertyDiscoveryEvidence::SOURCE_CAMERA,
            'captured_at' => '2026-09-12 09:00:00',
        ]);

        Sanctum::actingAs($this->manager());

        $data = $this->getJson('/api/v1/discoveries/'.$discovery->id)->assertOk()->json('data');

        // Not a single storage path anywhere in the payload.
        $this->assertStringNotContainsString('private/', (string) json_encode($data));
        $this->assertStringNotContainsString('file_path', (string) json_encode($data));

        // The two stores are distinguishable, so a client cannot mistake the
        // valuation/visit photos for the discovery's property photographs.
        $this->assertArrayHasKey('property_photo_count', $data);
        $this->assertSame(1, $data['property_photo_count']);

        $this->assertArrayHasKey('legacy_photos', $data);
        $this->assertCount(1, $data['legacy_photos']);
        $this->assertSame($legacy->id, $data['legacy_photos'][0]['id']);
        $this->assertStringStartsWith('/api/v1/evidence/photos/', $data['legacy_photos'][0]['url']);

        // The removed key must not linger and invite the wrong join.
        $this->assertArrayNotHasKey('photos', $data);
    }

    /**
     * A discovery is not a property bill. It has no property_id, no registry
     * document number and no bill until the source system registers it, so the
     * aggregate lookup must refuse rather than invent a match.
     */
    public function test_a_discovery_without_registration_identifiers_is_not_addressable_as_a_bill(): void
    {
        $discovery = $this->discovery(['property_id' => null, 'document_number' => null]);

        Sanctum::actingAs($this->manager());

        // The discovery itself is fully readable.
        $this->getJson('/api/v1/discoveries/'.$discovery->id)->assertOk();

        // But it cannot be resolved through the registered-property lookup, and
        // TIN is deliberately not an accepted substitute key.
        $this->getJson('/api/v1/property-profile?tin='.$discovery->tin)->assertStatus(422);
        $this->getJson('/api/v1/property-profile')->assertStatus(422);
    }

    /**
     * An unassessed property must stay unassessed in the payload. A null is the
     * difference between "nobody has valued this yet" and a real zero, and the
     * view relies on that distinction to print "Not yet assessed".
     */
    public function test_an_unassessed_property_reports_a_null_official_value_not_zero(): void
    {
        $this->discovery(['official_assessed_value' => null]);

        Sanctum::actingAs($this->manager());

        $value = $this->getJson('/api/v1/property-profile?property_id=49661')
            ->assertOk()
            ->json('data.value');

        $this->assertArrayHasKey('official_assessed_value', $value);
        $this->assertNull($value['official_assessed_value']);

        // The declaration is still reported, and no official assessment row is
        // invented for a property nobody has valued.
        $types = array_column($value['history'], 'type');
        $this->assertContains('Self Declared', $types);
        $this->assertNotContains('Valuation', $types);
        $this->assertNotContains('Reassessment', $types);
        $this->assertSame('150000', (string) $value['declared_value']);
    }

    public function test_a_genuine_zero_assessment_is_preserved_as_zero(): void
    {
        // The mirror of the test above: a deliberate zero must survive as 0, so
        // the view never turns a real valuation into "Not yet assessed".
        $this->discovery(['official_assessed_value' => 0]);

        Sanctum::actingAs($this->manager());

        $value = $this->getJson('/api/v1/property-profile?property_id=49661')
            ->assertOk()
            ->json('data.value');

        $this->assertNotNull($value['official_assessed_value']);
        $this->assertEquals(0, $value['official_assessed_value']);
    }

    public function test_current_task_is_surfaced(): void
    {
        $discovery = $this->discovery();
        $officer = $this->officer();

        Task::create([
            'task_type' => Task::TYPE_ACCOUNT_PROCESSING,
            'section' => 'Account & Records',
            'reference_type' => 'property_discovery',
            'reference_id' => $discovery->id,
            'assigned_to' => $officer->id,
            'status' => 'Logged',
            'due_date' => '2026-09-18',
        ]);

        Sanctum::actingAs($this->manager());

        $task = $this->getJson('/api/v1/property-profile?property_id=49661')->assertOk()->json('data.current_task');

        $this->assertNotNull($task);
        $this->assertSame(Task::TYPE_ACCOUNT_PROCESSING, $task['task_type']);
        $this->assertSame('property_discovery', $task['reference_type']);
        $this->assertSame('2026-09-18', $task['due_date']);
    }

    public function test_no_active_task_reports_null(): void
    {
        $this->discovery();

        Sanctum::actingAs($this->manager());

        $this->assertNull(
            $this->getJson('/api/v1/property-profile?property_id=49661')->assertOk()->json('data.current_task')
        );
    }

    /**
     * The scope gate previously compared bill identifiers against a list of
     * discovery identifiers, so the two keyspaces were cross-compared.
     */
    public function test_out_of_scope_property_is_refused(): void
    {
        $other = $this->discovery([
            'property_id' => '99999',
            'discovered_by' => $this->officer()->id,
        ]);

        // a property the caller legitimately owns, so the refusal below cannot
        // be explained by a missing lookup key
        $this->discovery();

        Sanctum::actingAs($this->unrelatedOfficer());

        $this->getJson('/api/v1/property-profile?property_id=99999')
            ->assertForbidden();

        $this->assertSame('99999', $other->property_id);
    }

    public function test_reaching_a_property_does_not_expose_records_outside_scope(): void
    {
        $mine = $this->discovery([
            'property_id' => '49661',
            'discovered_by' => $this->officer()->id,
        ]);

        $theirs = $this->discovery([
            'property_id' => '49661',
            'discovered_by' => $this->unrelatedOfficer()->id,
        ]);

        Sanctum::actingAs($this->officer());

        $data = $this->getJson('/api/v1/property-profile?property_id=49661')->assertOk()->json('data');

        $ids = array_column($data['discoveries'], 'id');

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids, 'A record outside the caller scope leaked into the aggregate');
    }

    public function test_a_profile_requires_at_least_one_lookup_key(): void
    {
        Sanctum::actingAs($this->manager());

        $this->getJson('/api/v1/property-profile')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Provide property_id, document_number, bill_id, or source_reference.']);
    }

    public function test_unknown_property_yields_an_empty_aggregate(): void
    {
        Sanctum::actingAs($this->manager());

        $data = $this->getJson('/api/v1/property-profile?property_id=NOPE')->assertOk()->json('data');

        $this->assertSame([], $data['discoveries']);
        $this->assertSame([], $data['valuations']);
        $this->assertSame([], $data['bills']);
        $this->assertSame('unknown', $data['record']['record_type']);
    }

    public function test_resolves_by_document_number_and_by_bill_id(): void
    {
        $bill = $this->bill();
        $this->discovery();

        Sanctum::actingAs($this->manager());

        $byDocument = $this->getJson('/api/v1/property-profile?document_number=11467734')->assertOk()->json('data');
        $byBill = $this->getJson('/api/v1/property-profile?bill_id='.$bill->id)->assertOk()->json('data');

        $this->assertCount(1, $byDocument['discoveries']);
        $this->assertCount(1, $byDocument['bills']);
        $this->assertCount(1, $byBill['discoveries']);
        $this->assertCount(1, $byBill['bills']);
    }

    public function test_a_bill_only_property_still_yields_a_usable_aggregate(): void
    {
        // The bill drawer is opened precisely to view a bill, so a property whose
        // discovery and valuation rows are absent must still resolve - the view
        // renders from the aggregate alone in that case.
        $bill = $this->bill();

        Sanctum::actingAs($this->manager());

        $data = $this->getJson('/api/v1/property-profile?bill_id='.$bill->id)->assertOk()->json('data');

        $this->assertCount(1, $data['bills']);
        $this->assertSame([], $data['discoveries']);
        $this->assertSame([], $data['valuations']);
        $this->assertSame('bill', $data['record']['record_type']);
        $this->assertSame('Logged', $data['record']['status']);

        // Identity still resolves off the bill, so the header and owner block
        // are populated rather than blank.
        $this->assertSame('49661', $data['identity']['property_id']);
        $this->assertSame('Stephen Potter Sr.', $data['identity']['owner_name']);
        $this->assertSame('12 Canal Road, Monrovia', $data['identity']['property_address']);

        $this->assertSame(1, $data['related']['bills']);
        $this->assertSame(0, $data['related']['discoveries']);
    }

    public function test_a_bill_only_property_reports_no_declared_or_assessed_value(): void
    {
        // Bill money columns are tax amounts, not property values. Reading them
        // as a declaration or an assessment would invent a valuation the system
        // never recorded, so the value history stays empty.
        $this->bill();

        Sanctum::actingAs($this->manager());

        $data = $this->getJson('/api/v1/property-profile?property_id=49661')->assertOk()->json('data');

        $this->assertSame([], $data['value']['history']);
        $this->assertNull($data['value']['declared_value']);
        $this->assertNull($data['value']['official_assessed_value']);
        $this->assertNull($data['value']['assessed_value']);
    }
}
