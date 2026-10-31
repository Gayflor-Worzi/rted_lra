<?php

namespace Tests\Feature;

use App\Models\PropertyDiscovery;
use App\Models\PropertyDiscoveryEvidence;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use App\Models\Valuation;
use App\Services\PropertyRecordBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A Valuation Officer must be able to submit a completed assessment to the Valuation
 * Manager.
 *
 * The submission endpoint demanded `assessed_value` and `annual_tax`, but the Valuation
 * Assessment form has never written either: it records the officer's figure as
 * `reassessed_value` alongside an `applicable_tax_rate`, and the tax is a function of
 * the two. So a fully completed assessment was rejected with "Complete the assessment
 * values on the valuation form before submitting." while the form plainly held every
 * value it asks for.
 *
 * The endpoint now works from the figure the form actually records and derives the tax
 * from it, which is the same rule rollupTotals() already applied. The requirement is
 * relocated, not dropped - a valuation with no figure and no tax is still refused.
 */
class ValuationSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function valOfficer(): User
    {
        return User::where('email', 'val.officer@test.lra')->firstOrFail();
    }

    private function valManager(): User
    {
        return User::where('email', 'val.manager@test.lra')->firstOrFail();
    }

    private function secondValOfficer(): User
    {
        return User::create([
            'staff_id' => 'VALOFF2',
            'full_name' => 'Second Valuation Officer',
            'email' => 'val.officer2@test.lra',
            'password' => bcrypt('password'),
            'role_id' => Role::where('name', 'Valuation Officer')->value('id'),
            'section_id' => Section::where('code', 'VAL')->value('id'),
            'is_active' => true,
            'must_reset_password' => false,
        ]);
    }

    /**
     * A valuation as the assessment form leaves it: the officer's figure and rate are
     * saved, and neither assessed_value nor annual_tax has ever been written.
     */
    private function assessedByTheForm(): Valuation
    {
        $officer = $this->valOfficer();

        $valuation = Valuation::create([
            'valuation_type' => 'regular',
            'status' => Valuation::STATUS_ASSIGNED,
            'owner_name' => 'Officer Completed',
            'owner_contact' => '0770-000-777',
            'tin' => 'TIN-SUBMIT',
            'property_address' => 'Assessment Way',
            'property_classification' => 'Residential',
            'gps_coordinate' => '6.315611, -10.807407',
            'assessment_date' => now()->toDateString(),
            'photos' => 'proof://VAL-TEST-0001',
            'reassessed_value' => 76000,
            'applicable_tax_rate' => 1.5,
            'valuation_officer_id' => $officer->id,
        ]);

        $valuation->descriptions()->create([
            'seq' => 1,
            'description' => 'Land',
            'level' => 'Land',
            'tar' => 5000,
            'quantity' => 1,
            'amount' => 5000,
            'depreciation_pct' => 0,
            'value' => 5000,
        ]);

        $valuation->descriptions()->create([
            'seq' => 2,
            'description' => 'Main structure',
            'level' => 'Ground Floor',
            'area_sqft' => 1200,
            'tar' => 28.0,
            'quantity' => 1,
            'amount' => 76000,
            'depreciation_pct' => 0,
            'value' => 76000,
        ]);

        return $valuation;
    }

    public function test_a_completed_assessment_submits_on_the_fields_the_form_records(): void
    {
        $valuation = $this->assessedByTheForm();

        // The precondition that used to fail: the form's fields are present, and the
        // two fields the endpoint used to demand are not.
        $this->assertNotNull($valuation->reassessed_value);
        $this->assertNull($valuation->assessed_value);
        $this->assertNull($valuation->annual_tax);

        Sanctum::actingAs($this->valOfficer());
        $res = $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'reassessed_value' => 76000,
            'applicable_tax_rate' => 1.5,
        ])->assertOk()->json('data');

        $this->assertSame(Valuation::STATUS_SUBMITTED_TO_MANAGER, $res['status']);
        $this->assertEqualsWithDelta(76000, (float) $res['assessed_value'], 0.01);
        // 76,000 at 1.5% is 1,140.00 — derived, exactly as the form's own preview shows.
        $this->assertEqualsWithDelta(1140.00, (float) $res['annual_tax'], 0.01);
        $this->assertEqualsWithDelta(1140.00, (float) $res['total_tax_payable'], 0.01);
    }

    public function test_the_submission_reaches_the_valuation_manager_review_queue(): void
    {
        $valuation = $this->assessedByTheForm();

        Sanctum::actingAs($this->valOfficer());
        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'reassessed_value' => 76000,
            'applicable_tax_rate' => 1.5,
        ])->assertOk();

        // The manager can now act on it, which is only possible if the task reached them.
        Sanctum::actingAs($this->valManager());
        $this->postJson("/api/v1/valuations/{$valuation->id}/review", [
            'decision' => 'confirm_ac',
        ])->assertOk()->assertJsonPath('data.status', Valuation::STATUS_SUBMITTED_TO_AC);
    }

    public function test_the_submission_is_not_available_a_second_time(): void
    {
        $valuation = $this->assessedByTheForm();

        Sanctum::actingAs($this->valOfficer());
        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'reassessed_value' => 76000,
            'applicable_tax_rate' => 1.5,
        ])->assertOk();

        // Same cycle, same officer: the stage has moved on, so it is refused.
        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'reassessed_value' => 76000,
            'applicable_tax_rate' => 1.5,
        ])->assertStatus(422);
    }

    public function test_an_assessment_with_no_figure_is_still_refused(): void
    {
        $officer = $this->valOfficer();

        $valuation = Valuation::create([
            'valuation_type' => 'regular',
            'status' => Valuation::STATUS_ASSIGNED,
            'owner_name' => 'Nothing Assessed',
            'owner_contact' => '0770-000-778',
            'tin' => 'TIN-EMPTY',
            'property_address' => 'Assessment Way',
            'property_classification' => 'Residential',
            'gps_coordinate' => '6.315611, -10.807407',
            'assessment_date' => now()->toDateString(),
            'photos' => 'proof://VAL-TEST-0002',
            'valuation_officer_id' => $officer->id,
        ]);

        $valuation->descriptions()->create([
            'seq' => 1,
            'description' => 'Land',
            'level' => 'Land',
            'tar' => 5000,
            'quantity' => 1,
            'amount' => 5000,
            'depreciation_pct' => 0,
            'value' => 5000,
        ]);

        $valuation->descriptions()->create([
            'seq' => 2,
            'description' => 'Main structure',
            'quantity' => 1,
            'amount' => 50000,
            'depreciation_pct' => 0,
            'value' => 50000,
        ]);

        Sanctum::actingAs($officer);
        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [])
            ->assertStatus(422)
            ->assertJsonFragment(['Complete the assessment values on the valuation form before submitting.']);
    }

    public function test_merely_being_able_to_view_the_valuation_grants_no_submission(): void
    {
        $valuation = $this->assessedByTheForm();

        // A different officer can see the record but does not own the assessment.
        Sanctum::actingAs($this->secondValOfficer());
        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'reassessed_value' => 76000,
        ])->assertStatus(403);

        $this->assertSame(
            Valuation::STATUS_ASSIGNED,
            (string) $valuation->fresh()->status,
            'A refused submission must leave the valuation exactly where it was.'
        );
    }

    public function test_an_explicitly_supplied_assessed_value_still_takes_precedence(): void
    {
        // The bulk submit on the valuations list sends assessed_value and annual_tax
        // directly. That path must keep behaving as it did: an explicit tax is honoured
        // rather than recomputed from the rate.
        $valuation = $this->assessedByTheForm();

        Sanctum::actingAs($this->valOfficer());
        $res = $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'assessed_value' => 95000,
            'annual_tax' => 2850.00,
            'applicable_tax_rate' => 3.0,
        ])->assertOk()->json('data');

        $this->assertEqualsWithDelta(95000, (float) $res['assessed_value'], 0.01);
        $this->assertEqualsWithDelta(2850.00, (float) $res['annual_tax'], 0.01);
        $this->assertEqualsWithDelta(76000, (float) $res['reassessed_value'], 0.01);
    }

    /**
     * A valuation whose only photographs are the ones taken on the discovery visit.
     *
     * The valuation officer carries no camera and the application never writes a
     * property photograph against a valuation, so `valuation.photos` is NULL and
     * `EvidencePhoto WHERE valuation_id` is empty. The photographs are real, and they
     * are on the discovery this assessment was raised from.
     */
    private function photographedOnTheDiscovery(): Valuation
    {
        $valuation = $this->assessedByTheForm();
        $valuation->forceFill(['photos' => null, 'discovery_id' => null])->save();

        $discovery = PropertyDiscovery::create([
            'discovered_by' => $this->valOfficer()->id,
            'owner_name' => 'Officer Completed',
            'owner_contact' => '0770-000-777',
            'tin' => 'TIN-SUBMIT',
            'property_address' => 'Assessment Way',
            'property_classification' => 'Residential',
            'property_type' => 'House',
            'county' => 'Montserrado',
            'gps_lat' => '6.315611',
            'gps_lng' => '-10.807407',
            'status' => PropertyDiscovery::STATUS_APPROVED,
        ]);

        foreach ([1, 2, 3] as $seq) {
            $path = "discovery-evidence/{$discovery->discovery_reference}/photo-{$seq}.jpg";
            Storage::disk('local')->put($path, 'jpeg-bytes-'.$seq);

            PropertyDiscoveryEvidence::create([
                'discovery_id' => $discovery->id,
                'evidence_type' => PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO,
                'sequence_no' => $seq,
                'original_name' => "Property photograph {$seq}.jpg",
                'file_path' => $path,
                'mime_type' => 'image/jpeg',
                'file_size' => 11,
                'uploaded_by' => $this->valOfficer()->id,
            ]);
        }

        $valuation->forceFill(['discovery_id' => $discovery->id])->save();

        return $valuation->fresh();
    }

    public function test_the_source_discoverys_photographs_satisfy_the_property_photo_requirement(): void
    {
        $valuation = $this->photographedOnTheDiscovery();

        // The precondition that used to fail: nothing on the valuation itself.
        $this->assertNull($valuation->photos);
        $this->assertSame(0, $valuation->evidencePhotos()->count());
        $this->assertCount(3, $valuation->discoveryPropertyPhotographs());

        Sanctum::actingAs($this->valOfficer());
        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'reassessed_value' => 76000,
            'applicable_tax_rate' => 1.5,
        ])->assertOk()->assertJsonPath('data.status', Valuation::STATUS_SUBMITTED_TO_MANAGER);
    }

    public function test_a_valuation_with_no_photograph_anywhere_is_still_refused(): void
    {
        $valuation = $this->assessedByTheForm();
        $valuation->forceFill(['photos' => null, 'discovery_id' => null])->save();

        $this->assertSame(0, $valuation->evidencePhotos()->count());
        $this->assertCount(0, $valuation->discoveryPropertyPhotographs());

        $this->assertRefusedForWantOfAPropertyPhoto($valuation->id);

        $this->assertSame(Valuation::STATUS_ASSIGNED, (string) $valuation->fresh()->status);
    }

    /**
     * Assert the submission is refused for the one stated reason, and for no other.
     */
    private function assertRefusedForWantOfAPropertyPhoto(int $valuationId): void
    {
        Sanctum::actingAs($this->valOfficer());

        $res = $this->postJson("/api/v1/valuations/{$valuationId}/submit", [
            'reassessed_value' => 76000,
        ])->assertStatus(422);

        $message = (string) $res->json('message');

        $this->assertStringContainsString('a Property Photo', $message);
        $this->assertStringNotContainsString('Owner Contact', $message);
        $this->assertStringNotContainsString('TIN', $message);
        $this->assertStringNotContainsString('GPS', $message);
    }

    public function test_discovery_evidence_that_is_not_a_property_photo_does_not_satisfy_the_gate(): void
    {
        // A deed is documentary evidence, not a photograph of the property.
        $valuation = $this->assessedByTheForm();
        $valuation->forceFill(['photos' => null])->save();

        $discovery = PropertyDiscovery::create([
            'discovered_by' => $this->valOfficer()->id,
            'owner_name' => 'Officer Completed',
            'owner_contact' => '0770-000-777',
            'tin' => 'TIN-SUBMIT',
            'property_address' => 'Assessment Way',
            'property_classification' => 'Residential',
            'property_type' => 'House',
            'county' => 'Montserrado',
            'gps_lat' => '6.315611',
            'gps_lng' => '-10.807407',
            'status' => PropertyDiscovery::STATUS_APPROVED,
        ]);

        PropertyDiscoveryEvidence::create([
            'discovery_id' => $discovery->id,
            'evidence_type' => PropertyDiscoveryEvidence::TYPE_OWNERSHIP_DOCUMENT,
            'sequence_no' => 1,
            'original_name' => 'deed.pdf',
            'file_path' => "discovery-evidence/{$discovery->discovery_reference}/deed.pdf",
            'mime_type' => 'application/pdf',
            'file_size' => 4,
            'uploaded_by' => $this->valOfficer()->id,
        ]);

        $valuation->forceFill(['discovery_id' => $discovery->id])->save();

        $this->assertRefusedForWantOfAPropertyPhoto($valuation->id);
    }

    public function test_the_submission_check_and_the_record_sheet_resolve_the_same_photographs(): void
    {
        $valuation = $this->photographedOnTheDiscovery();

        // The defect was a disagreement between two readers of the same record: the
        // sheet printed three photographs while the submission check claimed there were
        // none. One rule now answers for both.
        $onFile = $valuation->discoveryPropertyPhotographs();
        $printed = $this->recordSheetPhotos($valuation);

        $this->assertCount($onFile->count(), $printed);
        $this->assertCount(3, $printed);

        // Each photograph names the discovery it came from, so the sheet is not read as
        // though the assessment itself took the pictures.
        foreach ($printed as $photo) {
            $this->assertStringContainsString(
                'from discovery '.$valuation->sourceDiscovery()->discovery_reference,
                (string) ($photo['caption'] ?? '')
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>> the photographs the printed sheet carries
     */
    private function recordSheetPhotos(Valuation $valuation): array
    {
        $this->valOfficer()->role->grant('property.export');

        $record = app(PropertyRecordBuilder::class)->build('valuation', $valuation->id, $this->valOfficer());

        return $record['photos'] ?? [];
    }
}
