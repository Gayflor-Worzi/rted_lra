<?php

namespace Tests\Feature;

use App\Models\PropertyDiscovery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The submission audit must describe the transition that actually happened.
 *
 * submit() classifies and routes in one step: a draft becomes either
 * `Awaiting Account Manager Review` or `Awaiting Valuation`. The audit event
 * recorded a generic `Submitted` status that the workflow never stores, so the
 * audit trail named a state the record was never in and omitted the state it was
 * moved to.
 */
class DiscoverySubmissionAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function draft(array $overrides): PropertyDiscovery
    {
        Sanctum::actingAs(User::where('email', 'account.officer@test.lra')->firstOrFail());

        $id = $this->postJson('/api/v1/discoveries', array_merge([
            'owner_name' => 'Owner Audit',
            'owner_contact' => '0770-100-150',
            'tin' => 'TIN-AU-1',
            'property_address' => '1 Audit Street, Monrovia',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'property_classification' => 'Residential',
            'property_type' => 'House',
            'occupancy_use' => 'Residential',
            'discovery_date' => now()->toDateString(),
            'gps_coordinate' => '6.3130,-10.8074',
        ], $overrides))->assertCreated()->json('data.id');

        return PropertyDiscovery::findOrFail($id);
    }

    /** @return array{old: array, new: array} */
    private function submittedAudit(PropertyDiscovery $discovery): array
    {
        $row = DB::table('audit_logs')
            ->where('auditable_type', $discovery->getMorphClass())
            ->where('auditable_id', $discovery->id)
            ->where('action', 'discovery.submitted')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row, 'Submission must be audited.');

        return [
            'old' => json_decode((string) $row->old_values, true) ?: [],
            'new' => json_decode((string) $row->new_values, true) ?: [],
        ];
    }

    public function test_a_residential_submission_audits_the_account_review_state(): void
    {
        $discovery = $this->draft([]);

        $this->postJson("/api/v1/discoveries/{$discovery->id}/submit")->assertOk();

        $fresh = $discovery->fresh();
        $this->assertSame(PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW, $fresh->status);

        $audit = $this->submittedAudit($fresh);
        $this->assertSame(PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW, $audit['new']['status']);
        $this->assertSame(PropertyDiscovery::STATUS_DRAFT, $audit['old']['status']);
        $this->assertSame('account', $audit['new']['decision_path']);
        $this->assertSame('Account & Record Manager review', $audit['new']['workflow_stage']);
    }

    public function test_a_commercial_submission_audits_the_valuation_state(): void
    {
        $discovery = $this->draft([
            'property_classification' => 'Commercial',
            'occupancy_use' => 'Commercial',
        ]);

        $this->postJson("/api/v1/discoveries/{$discovery->id}/submit")->assertOk();

        $fresh = $discovery->fresh();
        $this->assertSame(PropertyDiscovery::STATUS_AWAITING_VALUATION, $fresh->status);

        $audit = $this->submittedAudit($fresh);
        $this->assertSame(PropertyDiscovery::STATUS_AWAITING_VALUATION, $audit['new']['status']);
        $this->assertSame('valuation', $audit['new']['decision_path']);
        $this->assertSame('Valuation Manager review', $audit['new']['workflow_stage']);

        // The generic status the workflow never writes is gone from the trail.
        $this->assertNotContains(PropertyDiscovery::STATUS_SUBMITTED, $audit['new']);
    }
}
