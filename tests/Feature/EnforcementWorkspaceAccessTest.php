<?php

namespace Tests\Feature;

use App\Models\PropertyBill;
use App\Models\EnforcementDistrict;
use App\Models\EnforcementTeam;
use App\Models\EnforcementTeamMembership;
use App\Models\EnforcementTaxRegion;
use App\Models\EnforcementTaxRegionSupervisorHistory;
use App\Models\Task;
use App\Models\User;
use App\Services\SectionWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\LocalDemoUsers;
use Tests\TestCase;

/**
 * UAT receipt: Enforcement Supervisors assign only unassigned bill-delivery
 * work in their team; Enforcement Managers and register viewers are read-only.
 *
 * enforcement.view_assignments (held by the Account Manager, M&E, Enforcement
 * supervisors/managers) grants browsing the enforcement task register. The
 * supervisor may assign from the shared unassigned queue only to their own
 * approved team; Enforcement Managers may view but not assign or reassign.
 */
class EnforcementWorkspaceAccessTest extends TestCase
{
    use LocalDemoUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $supervisor = $this->enforcementSupervisor();
        $manager = $this->enforcementManager();
        $officer = $this->enforcementOfficer();
        $region = EnforcementTaxRegion::create([
            'name' => 'Workspace Test Region',
            'code' => 'WORKSPACE-TEST',
            'classification' => 'Urban',
            'supervisor_id' => $supervisor->id,
            'created_by' => $manager->id,
        ]);
        EnforcementTaxRegionSupervisorHistory::create([
            'region_id' => $region->id,
            'supervisor_id' => $supervisor->id,
            'assigned_by' => $manager->id,
            'started_at' => now(),
        ]);
        $district = EnforcementDistrict::create(['name' => 'Workspace Test District', 'region_id' => $region->id]);
        $team = EnforcementTeam::create([
            'name' => 'Workspace Test Team',
            'manager_id' => $manager->id,
            'supervisor_id' => $supervisor->id,
        ]);
        $team->districts()->attach($district->id);
        EnforcementTeamMembership::create([
            'team_id' => $team->id,
            'officer_id' => $officer->id,
            'supervisor_id' => $supervisor->id,
            'primary_district_id' => $district->id,
            'prior_supervisor_id' => $supervisor->id,
            'is_team_lead' => true,
            'assigned_by' => $manager->id,
            'started_at' => now(),
        ]);
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function accountManager(): User
    {
        return $this->user('account.manager@test.lra');
    }

    private function accountOfficer(): User
    {
        return $this->user('account.officer@test.lra');
    }

    private function enforcementOfficer(): User
    {
        return $this->user('enf.officer@test.lra');
    }

    private function enforcementSupervisor(): User
    {
        return $this->localEnforcementSupervisor();
    }

    private function enforcementManager(): User
    {
        return $this->localRoleUser('enf.manager@test.lra', 'Enforcement Manager', 'ENF');
    }

    /** Log a bill as the Account & Records Officer, then use the Supervisor queue if requested. */
    private function logBill(string $document, ?User $officer = null): PropertyBill
    {
        Sanctum::actingAs($this->accountOfficer());

        $created = $this->postJson('/api/v1/property-bills', array_filter([
            'document_number' => $document,
            'property_id' => 'P-ENF-'.$document,
            'taxpayer_name' => "Taxpayer {$document}",
            'tin' => 'TIN-'.$document,
            'property_address' => "Address {$document}",
            'tax_amount' => 8000.00,
            'tax_period' => '2025',
        ]))->assertStatus(201)->json('data');

        $bill = PropertyBill::findOrFail($created['id']);
        if ($officer) {
            Sanctum::actingAs($this->enforcementSupervisor());
            $task = $bill->tasks()->firstOrFail();
            $this->postJson("/api/v1/me/tasks/{$task->id}/assign-walkin", ['officer_id' => $officer->id])
                ->assertOk();
            $bill->refresh();
        }

        return $bill;
    }

    public function test_account_manager_browses_the_enforcement_register_read_only(): void
    {
        $direct = $this->logBill('3001', $this->enforcementOfficer());
        $walkIn = $this->logBill('3002');

        Sanctum::actingAs($this->accountManager());

        $this->getJson('/api/v1/tasks?workspace=enforcement&view=all&per_page=25')
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.total', 2);

        $ids = collect($this->getJson('/api/v1/tasks?workspace=enforcement&view=all&per_page=25')
            ->json('data.data'))->pluck('id')->all();
        $this->assertContains($direct->tasks()->value('id'), $ids, 'assigned case is visible');
        $this->assertContains($walkIn->tasks()->value('id'), $ids, 'walk-in queue case is visible');

        $this->getJson('/api/v1/tasks?workspace=enforcement&view=unassigned&per_page=25')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_enforcement_unassigned_view_lists_only_bills_actually_awaiting_assignment(): void
    {
        $queued = $this->logBill('3020');
        $notQueued = $this->logBill('3021');
        $notQueued->update(['case_status' => 'Assigned']);

        Sanctum::actingAs($this->enforcementManager());

        $response = $this->getJson('/api/v1/tasks?workspace=enforcement&view=unassigned&per_page=25')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->assertSame(
            $queued->id,
            $response->json('data.data.0.bill.id'),
            'The read-only manager queue contains only bills whose task and bill are awaiting assignment.'
        );
    }

    public function test_account_manager_cannot_assign_or_reassign_enforcement_tasks(): void
    {
        $this->logBill('3003', $this->enforcementOfficer());
        $waiting = $this->logBill('3004');
        $unassignedTask = $waiting->tasks()->firstOrFail();

        Sanctum::actingAs($this->accountManager());

        $assignResponse = $this->postJson("/api/v1/tasks/{$unassignedTask->id}/assign", ['assigned_to' => $this->enforcementOfficer()->id]);
        $this->assertSame(403, $assignResponse->status(), 'Account Manager cannot assign a Bill Delivery task.');

        $enforcementTask = $this->logBill('3005', $this->enforcementOfficer())->tasks()->firstOrFail();
        Sanctum::actingAs($this->accountManager());
        $reassignResponse = $this->postJson("/api/v1/me/tasks/{$enforcementTask->id}/reassign", ['officer_id' => $this->enforcementOfficer()->id]);
        $this->assertSame(403, $reassignResponse->status(), 'Account Manager cannot reassign a Bill Delivery task.');

        $this->assertNull($unassignedTask->fresh()->assigned_to, 'account manager must never move an unassigned case');
    }

    public function test_account_officer_cannot_assign_an_officer_when_logging_a_bill(): void
    {
        Sanctum::actingAs($this->accountOfficer());
        $this->postJson('/api/v1/property-bills', [
            'document_number' => '3014',
            'property_id' => 'P-ENF-3014',
            'taxpayer_name' => 'Unauthorized direct assignment',
            'tin' => 'TIN-3014',
            'property_address' => 'Address 3014',
            'tax_amount' => 100,
            'tax_period' => '2025',
            'assigned_enforcement_officer_id' => $this->enforcementOfficer()->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('property_bills', ['document_number' => '3014']);
    }

    public function test_assistant_commissioner_stays_out_of_the_enforcement_register(): void
    {
        $ac = $this->user('ac@test.lra');

        $this->assertTrue($ac->canPermission('enforcement.view_assignments'), 'AC shares the register permission');
        $this->assertFalse(SectionWorkspaceService::isWorkspaceViewer($ac, 'enforcement'));

        Sanctum::actingAs($ac);
        $this->getJson('/api/v1/tasks?workspace=enforcement&view=all')
            ->assertStatus(403);
    }

    public function test_supervisor_assigns_only_unassigned_bills_and_manager_is_read_only(): void
    {
        $walkIn = $this->logBill('3006');
        $task = $walkIn->tasks()->firstOrFail();
        $payload = ['officer_id' => $this->enforcementOfficer()->id];

        Sanctum::actingAs($this->enforcementSupervisor());
        $this->getJson('/api/v1/tasks?workspace=enforcement&view=unassigned')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.task_type', Task::TYPE_BILL_DELIVERY);
        $this->getJson('/api/v1/property-bills?case_status=Awaiting Assignment&per_page=25')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $walkIn->id);
        $this->postJson("/api/v1/me/tasks/{$task->id}/assign-walkin", $payload)->assertOk();

        $walkInTwo = $this->logBill('3007');
        $taskTwo = $walkInTwo->tasks()->firstOrFail();
        Sanctum::actingAs($this->enforcementManager());
        $this->assertFalse($this->enforcementManager()->canPermission('bills.assign'));
        $this->assertFalse($this->enforcementManager()->canPermission('tasks.assign'));
        $this->getJson('/api/v1/property-bills?case_status=Awaiting Assignment&per_page=25')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
        $this->postJson("/api/v1/me/tasks/{$taskTwo->id}/assign-walkin", $payload)->assertForbidden();
        $this->postJson("/api/v1/property-bills/{$walkInTwo->id}/assign", $payload)->assertForbidden();
        $this->postJson("/api/v1/tasks/{$taskTwo->id}/assign", ['assigned_to' => $this->enforcementOfficer()->id])
            ->assertForbidden();
        $this->postJson("/api/v1/me/tasks/{$taskTwo->id}/reassign", $payload)->assertForbidden();

        $assignedBill = $this->logBill('3015', $this->enforcementOfficer());
        Sanctum::actingAs($this->enforcementSupervisor());
        $this->postJson("/api/v1/property-bills/{$assignedBill->id}/assign", $payload)->assertStatus(422);
        $this->postJson("/api/v1/me/tasks/{$assignedBill->tasks()->firstOrFail()->id}/reassign", $payload)
            ->assertStatus(422);

        $walkInThree = $this->logBill('3008');
        $taskThree = $walkInThree->tasks()->firstOrFail();
        Sanctum::actingAs($this->accountOfficer());
        $this->postJson("/api/v1/me/tasks/{$taskThree->id}/assign-walkin", $payload)->assertStatus(403);

        $walkInFour = $this->logBill('3009');
        $taskFour = $walkInFour->tasks()->firstOrFail();
        Sanctum::actingAs($this->enforcementOfficer());
        $this->postJson("/api/v1/me/tasks/{$taskFour->id}/assign-walkin", $payload)->assertStatus(403);
    }

    public function test_reassigning_a_queued_bill_syncs_the_bill_case_status(): void
    {
        $walkIn = $this->logBill('3010');
        $task = $walkIn->tasks()->firstOrFail();
        $this->assertSame('Awaiting Assignment', $walkIn->case_status);

        Sanctum::actingAs($this->enforcementSupervisor());
        $this->postJson("/api/v1/me/tasks/{$task->id}/reassign", ['officer_id' => $this->enforcementOfficer()->id])
            ->assertOk();

        $walkIn->refresh();
        $this->assertSame('Assigned', $walkIn->case_status, 'reassigning the delivery task must advance the queued bill');
        $this->assertSame($this->enforcementOfficer()->id, $walkIn->assigned_enforcement_officer_id);
        $this->assertNotNull($walkIn->primary_tax_district_id, 'explicit assignment snapshots the officer primary district');
        $this->assertSame($this->enforcementOfficer()->id, $task->fresh()->assigned_to);
    }

    public function test_task_assignment_history_records_the_real_previous_status(): void
    {
        $bill = $this->logBill('3012');
        $task = $bill->tasks()->firstOrFail();
        $this->assertSame('Awaiting Assignment', $task->status);

        Sanctum::actingAs($this->localRoleUser('task.history.admin@test.lra', 'System Administrator', 'SYS'));
        $this->postJson("/api/v1/tasks/{$task->id}/assign", ['assigned_to' => $this->enforcementOfficer()->id])
            ->assertOk();

        $history = $task->fresh()->history()->latest('id')->firstOrFail();
        $this->assertSame('Awaiting Assignment', $history->from_status);
        $this->assertSame('Assigned', $history->to_status);
    }

    public function test_team_workspace_view_includes_direct_reports_without_exposing_other_staff_or_workspaces(): void
    {
        $bill = $this->logBill('3013', $this->enforcementOfficer());
        $otherOfficer = $this->localRoleUser('enf.other.officer@test.lra', 'Enforcement Officer', 'ENF');
        $otherTask = Task::create([
            'task_type' => Task::TYPE_BILL_DELIVERY,
            'section' => 'Enforcement',
            'reference_type' => 'property_bill',
            'reference_id' => 999,
            'assigned_to' => $otherOfficer->id,
            'status' => 'Assigned',
        ]);
        $outsideWorkspaceTask = Task::create([
            'task_type' => Task::TYPE_ACCOUNT_PROCESSING,
            'section' => 'Account & Records',
            'reference_type' => 'property_bill',
            'reference_id' => 1000,
            'assigned_to' => $this->enforcementOfficer()->id,
            'status' => 'Assigned',
        ]);

        Sanctum::actingAs($this->enforcementSupervisor());
        $response = $this->getJson('/api/v1/tasks?workspace=enforcement&view=team&per_page=200')
            ->assertOk();
        $rows = collect($response->json('data.data'));

        $this->assertTrue($rows->contains('id', $bill->tasks()->value('id')));
        $this->assertFalse($rows->contains('id', $otherTask->id));
        $this->assertFalse($rows->contains('id', $outsideWorkspaceTask->id));
    }

    public function test_a_delivered_bill_task_cannot_be_reassigned(): void
    {
        $bill = $this->logBill('3011', $this->enforcementOfficer());
        $task = $bill->tasks()->firstOrFail();
        $task->update(['status' => 'Delivered']);
        $bill->update(['case_status' => 'Delivered']);

        Sanctum::actingAs($this->enforcementSupervisor());
        $this->postJson("/api/v1/me/tasks/{$task->id}/reassign", ['officer_id' => $this->enforcementOfficer()->id])
            ->assertStatus(422);

        $this->assertSame('Delivered', $task->fresh()->status, 'a delivered delivery task keeps its terminal status');
        $this->assertSame('Delivered', $bill->fresh()->case_status, 'a delivered bill keeps its stage');
    }
}
