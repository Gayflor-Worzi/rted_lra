<?php

namespace Tests\Feature;

use App\Models\PropertyDiscovery;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dashboard counters must name states the workflow actually writes.
 *
 * Three counters were built on the old discovery vocabulary rather than on the
 * states submit()/review()/decide() write:
 *
 *  - "New Discoveries" asked for `Draft` or `Submitted`, but a discovery is only
 *    ever Draft before routing and is never `Submitted`; a scope clause then
 *    excluded the drafts, so the stage read zero however much new work existed.
 *  - "Awaiting Classification" counted `Classified`, a status the workflow stopped
 *    writing when classification moved into submit(). The stage could only be zero.
 *  - The division review-queue panel split "submitted" from "manager review" using
 *    `Under Manager Review`, which no code writes, so the queue's own metric read
 *    zero while the queue was full.
 *
 * These tests pin each counter to the population its card claims.
 */
class DashboardStatusMappingTest extends TestCase
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

    private function payload(string $suffix): array
    {
        return [
            'owner_name' => 'Owner '.$suffix,
            'owner_contact' => '0770-100-140',
            'tin' => 'TIN-DS-'.$suffix,
            'property_address' => 'Address '.$suffix.', Monrovia',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'property_classification' => 'Commercial',
            'property_type' => 'House',
            'occupancy_use' => 'Commercial',
            'discovery_date' => now()->toDateString(),
            'gps_coordinate' => '6.3130,-10.8074',
        ];
    }

    private function draft(string $suffix): PropertyDiscovery
    {
        $this->act('account.officer@test.lra');

        $id = $this->postJson('/api/v1/discoveries', $this->payload($suffix))
            ->assertCreated()->json('data.id');

        return PropertyDiscovery::findOrFail($id);
    }

    /**
     * A commercial discovery with an assessment submitted to the Valuation Manager.
     *
     * @return array{0: PropertyDiscovery, 1: Valuation}
     */
    private function submittedValuation(string $suffix): array
    {
        $discovery = $this->draft($suffix);

        $this->postJson("/api/v1/discoveries/{$discovery->id}/submit")->assertOk();

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/val-review", [
            'decision' => 'assign',
            'officer_id' => User::where('email', 'val.officer@test.lra')->firstOrFail()->id,
        ])->assertOk();

        $valuation = Valuation::where('discovery_id', $discovery->id)->firstOrFail();

        $this->act('val.officer@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/descriptions", [
            'descriptions' => [[
                'description' => 'Land',
                'level' => 'Land',
                'tar' => 5000.00,
                'quantity' => 1,
            ], [
                'description' => 'Ground-floor commercial floor.',
                'level' => 'Ground Floor',
                'area_sqft' => 4500,
                'tar' => 28.0,
                'quantity' => 1,
                'amount' => 126000.00,
                'depreciation_pct' => 0,
            ]],
        ])->assertOk();
        $this->putJson("/api/v1/valuations/{$valuation->id}", [
            'photos' => 'proof://VAL-DS-'.$suffix,
            'owner_contact' => '0770-100-140',
        ])->assertOk();
        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'assessed_value' => 1200000.00,
            'annual_tax' => 42000.00,
            'applicable_tax_rate' => 3.5,
        ])->assertOk();

        return [$discovery->fresh(), $valuation->fresh()];
    }

    /** @return array<string, int> label => count */
    private function valuationPipelineStages(): array
    {
        $this->act('val.manager@test.lra');

        $stages = $this->getJson('/api/v1/dashboard/manager/valuation')
            ->assertOk()
            ->json('data.pipeline.stages');

        return collect($stages)->mapWithKeys(fn ($s) => [$s['label'] => $s['count']])->all();
    }

    public function test_new_discoveries_counts_the_draft_state_the_workflow_writes(): void
    {
        $this->draft('DS1');

        $stages = $this->valuationPipelineStages();

        $this->assertSame(
            1,
            $stages['New Discoveries'] ?? null,
            'A newly recorded, unrouted discovery is a Draft and must be counted as new work.'
        );
    }

    public function test_the_classification_stage_is_gone_because_no_record_can_ever_be_in_it(): void
    {
        $this->draft('DS2');

        $stages = $this->valuationPipelineStages();

        $this->assertArrayNotHasKey(
            'Awaiting Classification',
            $stages,
            'Classification happens inside submit(); a stage built on a status the workflow never writes is not a stage.'
        );
    }

    public function test_the_returned_stage_counts_every_return_status(): void
    {
        [$discovery, $valuation] = $this->submittedValuation('DS3');

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/review", [
            'decision' => 'confirm_ac',
            'remarks' => 'Assessment complete.',
        ])->assertOk();

        $this->act('ac@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/decide", [
            'decision' => 'return',
            'remarks' => 'Re-check the floor area.',
        ])->assertOk();

        $this->assertSame(Valuation::STATUS_AC_RETURNED, $valuation->fresh()->status);
        $this->assertSame(PropertyDiscovery::STATUS_RETURNED, $discovery->fresh()->status);

        $stages = $this->valuationPipelineStages();

        // One returned assessment plus the discovery the return put back in the
        // recorder's queue. The assessment's status is `AC Returned`, which the old
        // counter (Valuation::STATUS_RETURNED only) silently dropped.
        $this->assertSame(2, $stages['Returned'] ?? null);
    }

    public function test_the_division_review_queue_metric_counts_the_records_waiting_in_it(): void
    {
        [, $valuation] = $this->submittedValuation('DS4');

        $this->assertSame(Valuation::STATUS_SUBMITTED_TO_MANAGER, $valuation->status);

        $this->act('ac@test.lra');
        $data = $this->getJson('/api/v1/dashboard/division')->assertOk()->json('data');

        $panel = collect($data['focus']['panels'])->firstWhere('key', 'valuation_review');
        $this->assertNotNull($panel);

        $this->assertSame(
            1,
            $panel['metrics']['manager_review'],
            'The review-queue metric must count the assessments actually waiting for manager review.'
        );

        // The stage is one metric, not two names for the same queue.
        $this->assertArrayNotHasKey('submitted', $panel['metrics']);
    }

    public function test_the_division_pipeline_reports_no_classification_counter(): void
    {
        $this->draft('DS5');

        $this->act('ac@test.lra');
        $pipeline = $this->getJson('/api/v1/dashboard/division')->assertOk()->json('data.discovery_pipeline');

        $this->assertArrayNotHasKey('pending_classification', $pipeline);
        $this->assertSame(1, $pipeline['draft']);
    }
}
