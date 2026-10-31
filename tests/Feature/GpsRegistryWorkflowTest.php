<?php

namespace Tests\Feature;

use App\Models\GpsLocation;
use App\Models\GpsLocationHistory;
use App\Models\PropertyBill;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\LocalDemoUsers;
use Tests\TestCase;

class GpsRegistryWorkflowTest extends TestCase
{
    use LocalDemoUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function createAssignedProperty(): array
    {
        $officer = User::where('email', 'enf.officer@test.lra')->firstOrFail();
        $bill = PropertyBill::create([
            'document_number' => 'GPS-WORKFLOW-1',
            'property_id' => 'GPS-WORKFLOW-PROP-1',
            'taxpayer_name' => 'GPS Workflow Taxpayer',
            'property_classification' => 'Residential',
            'property_address' => 'GPS Workflow Street',
            'tax_amount' => 100,
            'interest_charged' => 0,
            'penalty_charged' => 0,
            'total_tax_due' => 100,
            'tax_period' => '2026',
            'payment_status' => 'Unpaid',
            'delivery_status' => PropertyBill::DELIVERY_STAGES[0],
            'case_status' => 'Assigned',
            'date_logged' => now()->toDateString(),
            'assigned_enforcement_officer_id' => $officer->id,
        ]);
        $task = Task::create([
            'task_type' => Task::TYPE_BILL_DELIVERY,
            'section' => 'Enforcement',
            'reference_type' => 'property_bill',
            'reference_id' => $bill->id,
            'assigned_to' => $officer->id,
            'status' => 'Assigned',
        ]);

        return [$officer, $bill, $task];
    }

    private function submitCapture(array $task): GpsLocationHistory
    {
        $this->postJson('/api/v1/gps/certify', [
            'property_id' => 'GPS-WORKFLOW-PROP-1',
            'latitude' => 6.315611,
            'longitude' => -10.807407,
            'accuracy' => 167.35,
            'certification_decision' => 'yes',
            'source' => 'other',
            'remarks' => 'Blue gate near the main road.',
            'task_id' => $task['task']->id,
        ])->assertCreated();

        return GpsLocationHistory::where('property_id', 'GPS-WORKFLOW-PROP-1')->firstOrFail();
    }

    public function test_capture_context_is_bound_to_an_existing_property_and_returns_available_context(): void
    {
        [$officer, , $task] = $this->createAssignedProperty();
        Sanctum::actingAs($officer);

        $this->getJson('/api/v1/gps/capture-context/GPS-WORKFLOW-PROP-1')
            ->assertOk()
            ->assertJsonPath('data.property_id', 'GPS-WORKFLOW-PROP-1')
            ->assertJsonPath('data.property.address', 'GPS Workflow Street');

        $this->getJson('/api/v1/gps/capture-context/NOT-A-REAL-PROPERTY')
            ->assertForbidden();

        $this->assertSame('Assigned', $task->fresh()->status);
    }

    public function test_rejection_requires_separate_review_remarks_and_preserves_capture_remarks(): void
    {
        [$officer, , $task] = $this->createAssignedProperty();
        Sanctum::actingAs($officer);
        $history = $this->submitCapture(['task' => $task]);

        $reviewer = $this->localEnforcementSupervisor();
        Sanctum::actingAs($reviewer);

        $this->postJson("/api/v1/gps/reviews/{$history->id}/decision", ['decision' => 'reject'])
            ->assertUnprocessable();

        $this->postJson("/api/v1/gps/reviews/{$history->id}/decision", [
            'decision' => 'reject',
            'remarks' => 'Coordinate appears to be at the neighboring parcel; recapture at the entrance.',
        ])->assertOk()
            ->assertJsonPath('data.verification_status', 'rejected');

        $history->refresh();
        $this->assertSame('Blue gate near the main road.', $history->remarks);
        $this->assertSame('Coordinate appears to be at the neighboring parcel; recapture at the entrance.', $history->review_remarks);
        $this->assertSame($reviewer->id, $history->reviewed_by);
        $this->assertNotNull($history->reviewed_at);
    }

    public function test_approval_updates_the_official_record_without_replacing_capture_remarks(): void
    {
        [$officer, , $task] = $this->createAssignedProperty();
        Sanctum::actingAs($officer);
        $history = $this->submitCapture(['task' => $task]);

        Sanctum::actingAs($this->localEnforcementSupervisor());
        $this->postJson("/api/v1/gps/reviews/{$history->id}/decision", [
            'decision' => 'approve',
        ])->assertOk()
            ->assertJsonPath('data.verification_status', 'approved')
            ->assertJsonPath('data.official.latitude', '6.3156110');

        $this->assertSame('Blue gate near the main road.', $history->fresh()->remarks);
        $this->assertNull($history->fresh()->review_remarks);
        $this->assertDatabaseHas('gps_locations', [
            'property_id' => 'GPS-WORKFLOW-PROP-1',
            'latitude' => '6.3156110',
            'longitude' => '-10.8074070',
        ]);
        $this->assertInstanceOf(GpsLocation::class, GpsLocation::where('property_id', 'GPS-WORKFLOW-PROP-1')->first());
    }

    public function test_registry_filters_are_scoped_and_field_user_can_read_own_submission_feedback(): void
    {
        [$officer, , $task] = $this->createAssignedProperty();
        Sanctum::actingAs($officer);
        $history = $this->submitCapture(['task' => $task]);

        $reviewer = $this->localEnforcementSupervisor();
        Sanctum::actingAs($reviewer);
        $this->postJson("/api/v1/gps/reviews/{$history->id}/decision", [
            'decision' => 'reject',
            'remarks' => 'Please capture at the property entrance.',
        ])->assertOk();

        $historyViewer = $this->localRoleUser('gps.history.viewer@test.lra', 'System Administrator', 'SYS');
        Sanctum::actingAs($historyViewer);
        $this->getJson('/api/v1/gps/registry?status=rejected&q=GPS-WORKFLOW-PROP-1')
            ->assertOk()
            ->assertJsonPath('data.records.data.0.verification_status', 'rejected')
            ->assertJsonPath('data.records.data.0.property.address', 'GPS Workflow Street')
            ->assertJsonPath('data.records.data.0.review_remarks', 'Please capture at the property entrance.')
            ->assertJsonPath('data.counts.rejected', 1);

        Sanctum::actingAs($officer);
        $this->getJson('/api/v1/gps/my-submissions?property_id=GPS-WORKFLOW-PROP-1')
            ->assertOk()
            ->assertJsonPath('data.data.0.verification_status', 'rejected')
            ->assertJsonPath('data.data.0.review_remarks', 'Please capture at the property entrance.');

        $this->postJson("/api/v1/gps/reviews/{$history->id}/decision", [
            'decision' => 'approve',
        ])->assertForbidden();
    }

    public function test_registry_without_history_permission_only_returns_official_locations(): void
    {
        [$officer, , $task] = $this->createAssignedProperty();
        Sanctum::actingAs($officer);
        $history = $this->submitCapture(['task' => $task]);

        Sanctum::actingAs($this->localEnforcementSupervisor());
        $this->postJson("/api/v1/gps/reviews/{$history->id}/decision", [
            'decision' => 'reject',
            'remarks' => 'Recapture required.',
        ])->assertOk();

        GpsLocation::create([
            'property_id' => 'GPS-WORKFLOW-PROP-1',
            'latitude' => 6.315611,
            'longitude' => -10.807407,
            'source' => 'other',
            'verification_status' => 'verified',
        ]);

        Sanctum::actingAs($this->localMeOfficer());
        $this->getJson('/api/v1/gps/registry')
            ->assertOk()
            ->assertJsonPath('data.history_access', false)
            ->assertJsonPath('data.records.total', 1)
            ->assertJsonPath('data.records.data.0.verification_status', 'approved')
            ->assertJsonPath('data.counts.rejected', 0);

        $this->getJson('/api/v1/gps/registry?status=rejected')
            ->assertForbidden();
    }
}
