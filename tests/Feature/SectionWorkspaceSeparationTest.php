<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\Task;
use App\Models\User;
use App\Services\SectionWorkspaceService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\LocalDemoUsers;
use Tests\Feature\Concerns\ApprovedEnforcementAssignments;
use Tests\TestCase;

/**
 * Direct Section Task Separation (spec §23–§24, acceptance AC/ARM/VM/ENF).
 *
 * Every section — Assistant Commissioner, Account & Record, Valuation,
 * Enforcement — processes its OWN desk work through its OWN workspace. A task
 * is stamped with its section and assigned to its section user, so it can never
 * surface inside (or be negotiated toward) another section's workspace. The
 * enforcement surface is isolated to enforcement task types, and permission
 * shortcuts (e.g. 'enforcement.view_assignments') grant no enforcement-screen
 * access.
 */
class SectionWorkspaceSeparationTest extends TestCase
{
    use LocalDemoUsers;
    use ApprovedEnforcementAssignments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function ac(): User
    {
        return User::where('email', 'ac@test.lra')->firstOrFail();
    }

    private function accountManager(): User
    {
        return User::where('email', 'account.manager@test.lra')->firstOrFail();
    }

    private function accountOfficer(): User
    {
        return User::where('email', 'account.officer@test.lra')->firstOrFail();
    }

    private function valuationManager(): User
    {
        return User::where('email', 'val.manager@test.lra')->firstOrFail();
    }

    private function valuationOfficer(): User
    {
        return User::where('email', 'val.officer@test.lra')->firstOrFail();
    }

    private function enforcementOfficer(): User
    {
        return User::where('email', 'enf.officer@test.lra')->firstOrFail();
    }

    private function enforcementSupervisor(): User
    {
        return $this->localEnforcementSupervisor();
    }

    private function taskService(): TaskService
    {
        return app(TaskService::class);
    }

    private function discoveryPayload(string $classification, string $suffix = ''): array
    {
        return [
            'owner_name' => "Owner {$classification}",
            'property_address' => "Separation address {$classification}{$suffix}",
            'property_classification' => $classification,
            'property_type' => 'House',
            'occupancy_use' => 'Residential',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'tin' => 'TIN-SEP',
            'discovery_date' => now()->toDateString(),
        ];
    }

    /** Officer discovery lifecycle for a given classification. */
    private function discoveryFlow(string $classification, string $suffix = ''): array
    {
        Sanctum::actingAs($this->accountOfficer());

        $discovery = $this->postJson('/api/v1/discoveries', $this->discoveryPayload($classification, $suffix))
            ->assertStatus(201)->json('data');

        $this->postJson("/api/v1/discoveries/{$discovery['id']}/submit")->assertOk();

        return $discovery;
    }

    /* ------------------------------------------------------------------ */
    /*  AC-01 — the Assistant Commissioner's own approvals on their desk */
    /* ------------------------------------------------------------------ */

    public function test_ac_sees_only_their_own_approval_tasks_and_badge_round_trips(): void
    {
        $ac = $this->ac();

        // A manager-submitted valuation places an AC Approval desk task directly
        // with the Assistant Commissioner via the section registry (§23).
        $this->taskService()->createDeskTask(
            Task::TYPE_AC_APPROVAL,
            'MGT',
            'valuation',
            999,
            'Valuation pending approval',
            $ac,
        );

        Sanctum::actingAs($ac);

        $rows = $this->getJson('/api/v1/ac/tasks')->assertOk()->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame(Task::TYPE_AC_APPROVAL, $rows[0]['task_type']);
        $this->assertSame('MGT', $rows[0]['section']);

        $summary = $this->getJson('/api/v1/ac/tasks/summary')->assertOk()->json('data');
        $this->assertSame(1, $summary['personal']);
        $this->assertSame(0, $summary['queue']);

        $this->getJson('/api/v1/ac/tasks/badge')->assertOk()->assertJsonPath('data.unread', 1);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $ac->id,
            'type' => Notification::TYPE_AC_TASKS,
            'action_url' => '/ac/tasks',
        ]);

        $this->postJson('/api/v1/ac/tasks/read')
            ->assertOk()
            ->assertJsonPath('message', 'Assignment alerts acknowledged.');
        $this->getJson('/api/v1/ac/tasks/badge')->assertJsonPath('data.unread', 0);
    }

    /* ------------------------------------------------------------------ */
    /*  AC-02 — no other section's work is visible, and enforcement is off */
    /*  limits to the Assistant Commissioner on the enforcement surface */
    /* ------------------------------------------------------------------ */

    public function test_ac_never_sees_non_approval_tasks_and_cannot_enter_enforcement(): void
    {
        $ac = $this->ac();

        $this->taskService()->createDeskTask(Task::TYPE_AC_APPROVAL, 'MGT', 'valuation', 1, 'Valuation MGT-1', $ac);

        // Foreign-type work force-assigned to the AC: a Bill Delivery task.
        Task::create([
            'task_type' => Task::TYPE_BILL_DELIVERY,
            'section' => 'ENF',
            'reference_type' => 'property_bill',
            'reference_id' => 1,
            'assigned_to' => $ac->id,
            'assigned_by' => $ac->id,
            'priority' => 'Normal',
            'status' => 'Assigned',
            'due_date' => now()->addDays(7)->toDateString(),
            'remarks' => 'Must never reach the AC workspace.',
        ]);

        Sanctum::actingAs($ac);

        // The AC workspace lists only AC approval work.
        $rows = $this->getJson('/api/v1/ac/tasks')->assertOk()->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame(Task::TYPE_AC_APPROVAL, $rows[0]['task_type']);

        // The engine list scoped to the AC workspace behaves identically.
        $engine = $this->getJson('/api/v1/tasks?workspace=ac')->assertOk()->json('data.data');
        $this->assertCount(1, $engine);
        $this->assertSame(Task::TYPE_AC_APPROVAL, $engine[0]['task_type']);

        // Enforcement is out of scope for the AC regardless of their old
        // 'enforcement.view_assignments' shortcut.
        $this->assertFalse(SectionWorkspaceService::canAccess($ac, 'enforcement'));
        $this->getJson('/api/v1/tasks?workspace=enforcement')->assertStatus(403);
    }

    /* ------------------------------------------------------------------ */
    /*  AC-03 — cross-section users are refused every workspace surface */
    /* ------------------------------------------------------------------ */

    public function test_non_ac_roles_cannot_reach_the_ac_workspace(): void
    {
        Sanctum::actingAs($this->valuationManager());
        $this->getJson('/api/v1/ac/tasks')->assertStatus(403);
        $this->getJson('/api/v1/tasks?workspace=ac')->assertStatus(403);

        Sanctum::actingAs($this->enforcementOfficer());
        $this->getJson('/api/v1/ac/tasks')->assertStatus(403);

        Sanctum::actingAs($this->accountManager());
        $this->getJson('/api/v1/tasks?workspace=ac')->assertStatus(403);
    }

    /* ------------------------------------------------------------------ */
    /*  VM-01 — Valuation staff see valuation work routed to their desk */
    /* ------------------------------------------------------------------ */

    public function test_valuation_manager_gets_the_manager_review_and_only_valuation_work(): void
    {
        // Commercial discovery submit -> Valuation Manager Review assigned to
        // the Valuation Manager (§23 routing, engine-verified).
        $this->discoveryFlow('Commercial', ' #VM1');

        $vm = $this->valuationManager();

        // A second piece of valuation work: an officer's submitted assessment.
        $this->taskService()->createDeskTask(
            Task::TYPE_VALUATION_REVIEW,
            'VAL',
            'valuation',
            2,
            'Valuation V-2',
            $vm,
        );

        Sanctum::actingAs($vm);

        $rows = $this->getJson('/api/v1/valuation/tasks')->assertOk()->json('data.data');
        $this->assertCount(2, $rows);

        $types = collect($rows)->pluck('task_type')->unique()->values()->all();
        $this->assertContains(Task::TYPE_VALUATION_MANAGER_REVIEW, $types);
        $this->assertContains(Task::TYPE_VALUATION_REVIEW, $types);

        foreach ($rows as $row) {
            $this->assertSame('VAL', $row['section']);
            $this->assertNotSame(0, $row['id']);

            // The task drawer renders these fields directly as React children, so
            // every one of them has to be a scalar. `next_action` and
            // `deadline_status` are descriptor objects; rendering either raw threw
            // "Objects are not valid as a React child" and took down the screen.
            $this->assertIsString($row['task_type']);
            $this->assertIsString($row['section']);
            $this->assertIsArray($row['next_action']);
            $this->assertIsArray($row['deadline_status']);
            $this->assertArrayHasKey('verb', $row['next_action']);
            $this->assertArrayHasKey('notes', $row['next_action']);
            // presentDeadline() exposes flags, not a `label`. The drawer used to
            // read deadline_status.label, so its Deadline field always showed "—".
            $this->assertArrayHasKey('is_overdue', $row['deadline_status']);
            $this->assertArrayHasKey('overdue_days', $row['deadline_status']);
            $this->assertArrayHasKey('deadline_type', $row['deadline_status']);
            $this->assertArrayNotHasKey('label', $row['deadline_status']);
            $this->assertTrue($row['assignee'] === null || is_string($row['assignee']));
            $this->assertTrue($row['stage'] === null || is_string($row['stage']));
        }

        $summary = $this->getJson('/api/v1/valuation/tasks/summary')->assertOk()->json('data');
        $this->assertSame(2, $summary['personal']);

        // The manager-review task arrived with a routed, workspace-local alert.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $vm->id,
            'type' => Notification::TYPE_VALUATION_TASKS,
            'action_url' => '/valuation/tasks',
        ]);
        $this->getJson('/api/v1/valuation/tasks/badge')->assertJsonPath('data.unread', 2);

        $this->postJson('/api/v1/valuation/tasks/read')->assertOk();
        $this->getJson('/api/v1/valuation/tasks/badge')->assertJsonPath('data.unread', 0);
    }

    /* ------------------------------------------------------------------ */
    /*  VM-02 — Valuation workspace is role-guarded and type-isolated */
    /* ------------------------------------------------------------------ */

    public function test_valuation_workspace_is_guarded_and_isolated(): void
    {
        $vm = $this->valuationManager();
        $ac = $this->ac();

        foreach ([
            'O VOT' => [$this->valuationOfficer(), '/api/v1/tasks?workspace=ac', 403],
            'AC to valuation' => [$ac, '/api/v1/valuation/tasks', 403],
            'VM to AC' => [$vm, '/api/v1/tasks?workspace=ac', 403],
            'Officer to AC' => [$this->enforcementOfficer(), '/api/v1/valuation/tasks', 403],
            'VM to enforcement' => [$vm, '/api/v1/tasks?workspace=enforcement', 403],
            'VM to bogus' => [$vm, '/api/v1/tasks?workspace=spooky', 422],
        ] as $label => [$user, $url, $code]) {
            Sanctum::actingAs($user);
            $this->getJson($url)->assertStatus($code, "{$label} expected {$code}.");
        }

        $this->assertFalse(SectionWorkspaceService::canAccess($ac, 'valuation'));
        $this->assertFalse(SectionWorkspaceService::canAccess($vm, 'ac'));
        $this->assertFalse(SectionWorkspaceService::canAccess($vm, 'enforcement'));
        $this->assertTrue(SectionWorkspaceService::canAccess($vm, 'valuation'));
    }

    /* ------------------------------------------------------------------ */
    /*  ARM-01 — the Account & Record Manager owns their account desk, with */
    /*  only read-only reach into the enforcement register (never assign) */
    /* ------------------------------------------------------------------ */

    public function test_account_manager_sees_their_review_desk_independently_of_enforcement(): void
    {
        // Self-declared discovery -> Discovery Review delivered to the Manager.
        $this->discoveryFlow('Residential', ' #ARM1');

        $manager = $this->accountManager();

        // enforcement.view_assignments is the Account Manager's read-level right
        // to the enforcement register — browse only, never assign. The Assistant
        // Commissioner holds the same permission but stays excluded (see AC-02).
        $this->assertTrue($manager->canPermission('enforcement.view_assignments'));
        $this->assertFalse(SectionWorkspaceService::canAccess($manager, 'enforcement'));
        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/tasks?workspace=enforcement')->assertOk();

        // But the Account workspace is theirs, fully.
        $rows = $this->getJson('/api/v1/account/my-tasks')->assertOk()->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame(Task::TYPE_DISCOVERY_REVIEW, $rows[0]['task_type']);

        $this->getJson('/api/v1/account/my-tasks/summary')->assertOk()->assertJsonPath('data.assigned', 1);
        $this->getJson('/api/v1/account/my-tasks/badge')->assertOk()->assertJsonPath('data.unread', 1);
        $this->postJson('/api/v1/account/my-tasks/read')->assertOk()->assertJsonPath('message', 'Assignment alerts acknowledged.');
        $this->getJson('/api/v1/account/my-tasks/badge')->assertJsonPath('data.unread', 0);
    }

    /* ------------------------------------------------------------------ */
    /*  ARM-02 — non-account roles are bounced out of the Account workspace */
    /* ------------------------------------------------------------------ */

    public function test_non_account_staff_cannot_reach_the_account_workspace(): void
    {
        Sanctum::actingAs($this->valuationManager());
        $this->getJson('/api/v1/account/my-tasks/summary')->assertStatus(403);
        $this->getJson('/api/v1/account/my-tasks')->assertStatus(403);

        Sanctum::actingAs($this->enforcementSupervisor());
        $this->getJson('/api/v1/account/my-tasks')->assertStatus(403);

        // The engine list agrees.
        Sanctum::actingAs($this->enforcementSupervisor());
        $this->getJson('/api/v1/tasks?workspace=account')->assertStatus(403);
    }

    /* ------------------------------------------------------------------ */
    /*  ENF-01 — the Enforcement workspace presents enforcement types only */
    /* ------------------------------------------------------------------ */

    public function test_enforcement_workspace_lists_only_enforcement_types(): void
    {
        $supervisor = $this->enforcementSupervisor();
        $ac = $this->ac();
        $manager = $this->accountManager();

        $this->taskService()->createDeskTask(Task::TYPE_BILL_DELIVERY, 'ENF', 'property_bill', 3, 'Bill B-3', $supervisor);
        $this->taskService()->createDeskTask(Task::TYPE_PAYMENT_FOLLOWUP, 'ENF', 'property_bill', 4, 'Bill B-4', $supervisor);

        // A full cross-section spread of foreign work, some already assigned.
        $this->taskService()->createDeskTask(Task::TYPE_AC_APPROVAL, 'MGT', 'valuation', 5, 'Valuation MGT-5', $ac);
        $this->taskService()->createDeskTask(Task::TYPE_VALUATION_REVIEW, 'VAL', 'valuation', 6, 'Valuation V-6', $this->valuationManager());
        $this->taskService()->createDeskTask(Task::TYPE_DISCOVERY_REVIEW, 'ACCT', 'property_discovery', 7, 'Discovery D-7', $manager);

        // The officer-level surface is a mirror of the enforcement section's
        // personal work — foreign sections can never drift in.
        Sanctum::actingAs($supervisor);

        $rows = $this->getJson('/api/v1/tasks?workspace=enforcement')->assertOk()->json('data.data');
        $this->assertCount(2, $rows);

        $types = collect($rows)->pluck('task_type')->unique()->values()->all();
        $this->assertContains(Task::TYPE_BILL_DELIVERY, $types);
        $this->assertContains(Task::TYPE_PAYMENT_FOLLOWUP, $types);
        $this->assertTrue(
            collect($types)->every(fn ($t) => in_array($t, SectionWorkspaceService::types('enforcement'), true)),
            'Every row in the Enforcement workspace must be an enforcement type.'
        );
    }

    /* ------------------------------------------------------------------ */
    /*  ENF-02 — the mobile field queue is enforcement-typed only */
    /* ------------------------------------------------------------------ */

    public function test_field_queue_is_enforcement_typed_only(): void
    {
        $officer = $this->enforcementOfficer();
        $this->approveEnforcementAssignment($officer);

        // A live bill logged by the Account & Records officer, then issued as a
        // Bill Delivery task for the enforcement officer (§2 desk assignment).
        Sanctum::actingAs($this->accountOfficer());
        $logged = $this->postJson('/api/v1/property-bills', [
            'document_number' => '2026/9001',
            'property_id' => 'P-SEP-1',
            'taxpayer_name' => 'Taxpayer P-SEP-1',
            'tin' => 'TIN-0009',
            'property_address' => 'Separation address bill',
            'tax_amount' => 5000.00,
            'tax_period' => '2025',
        ])->assertStatus(201)->json('data');

        app(TaskService::class)->createTaskFromBill(PropertyBill::findOrFail($logged['id']), $officer);

        // Foreign work forced onto the officer — must be invisible in the queue.
        Task::create([
            'task_type' => Task::TYPE_AC_APPROVAL,
            'section' => 'MGT',
            'reference_type' => 'valuation',
            'reference_id' => 8,
            'assigned_to' => $officer->id,
            'assigned_by' => $officer->id,
            'priority' => 'Normal',
            'status' => 'Assigned',
            'due_date' => now()->addDays(7)->toDateString(),
            'remarks' => 'Foreign type force-assignment.',
        ]);

        Sanctum::actingAs($officer);
        $rows = $this->getJson('/api/v1/enforcement-assignments/my')->assertOk()->json('data.data');

        foreach ($rows as $row) {
            $this->assertContains($row['task_type'], SectionWorkspaceService::types('enforcement'));
        }
        $this->assertContains(Task::TYPE_BILL_DELIVERY, collect($rows)->pluck('task_type')->all());
        $this->assertNotContains(Task::TYPE_AC_APPROVAL, collect($rows)->pluck('task_type')->all());
    }

    /* ------------------------------------------------------------------ */
    /*  ENF-03 — unknown workspaces are rejected, not silently widened */
    /* ------------------------------------------------------------------ */

    public function test_unknown_workspace_parameter_is_rejected(): void
    {
        Sanctum::actingAs($this->enforcementSupervisor());

        $this->getJson('/api/v1/tasks?workspace=spooky')->assertStatus(422)
            ->assertJsonPath('message', 'Unknown workspace.');

        $this->getJson('/api/v1/tasks?workspace=ac')->assertStatus(403);
    }

    /**
     * The Valuation Manager opens discoveries and valuations through the same
     * Property View as every other desk, and the view offers buttons from the
     * signed-in user's permissions. This pins that contract to the server: the
     * actions the Valuation desk is offered are the ones it may actually call,
     * and the neighbouring desks' actions stay closed to it.
     */
    public function test_the_valuation_desk_property_view_offers_only_actions_it_is_authorised_for(): void
    {
        $vm = $this->valuationManager();

        // The panel derives its buttons from these permissions, so assert the
        // grant/deny pairs that decide which buttons render at all.
        $this->assertTrue($vm->canPermission('discovery.route_to_valuation'), 'routes a discovery to valuation');
        $this->assertTrue($vm->canPermission('valuation.review'), 'reviews a valuation');
        $this->assertTrue($vm->canPermission('valuation.forward_ac'), 'forwards a valuation to AC');

        // The Account Manager's desk actions must not leak into this view.
        $this->assertFalse($vm->canPermission('discovery.aarm_review'));
        $this->assertFalse($vm->canPermission('discovery.aarm_approve'));
        $this->assertFalse($vm->canPermission('discovery.refer_to_valuation'));
        $this->assertFalse($vm->canPermission('discovery.property_registration'));
        $this->assertFalse($vm->canPermission('valuation.approve'));
        $this->assertFalse($vm->canPermission('valuation.account_review'));

        // And the server enforces the same split, not just the button rendering.
        $discovery = $this->discoveryFlow('Residential', ' vm-view');
        $id = $discovery['id'];

        Sanctum::actingAs($vm);
        $this->postJson("/api/v1/discoveries/{$id}/acct-review", ['decision' => 'approve', 'officer_id' => $this->accountOfficer()->id])
            ->assertStatus(403);
    }

    /**
     * The terminal account-desk step (completing a Registered discovery) belongs
     * to the Account & Records desk. The Enforcement Supervisor holds
     * discovery.review, but that must not open the account step to enforcement.
     */
    public function test_the_enforcement_supervisor_cannot_complete_a_registered_property(): void
    {
        $supervisor = $this->localEnforcementSupervisor();

        $discovery = PropertyDiscovery::create([
            'owner_name' => 'Completion Guard Owner',
            'owner_contact' => '0770-000-000',
            'tin' => 'TIN-COMPLETION-GUARD',
            'property_address' => 'Completion Guard Way, Monrovia',
            'property_classification' => 'Residential',
            'property_type' => 'House',
            'discovery_date' => now()->toDateString(),
            'gps_coordinate' => '6.3130,-10.8074',
        ]);
        $discovery->update(['status' => PropertyDiscovery::STATUS_REGISTERED]);

        $this->assertTrue($supervisor->canPermission('discovery.review'));
        $this->assertFalse($supervisor->canPermission('discovery.property_registration'));
        $this->assertFalse($supervisor->canPermission('discovery.aarm_review'));

        Sanctum::actingAs($supervisor);
        $this->postJson("/api/v1/discoveries/{$discovery->id}/complete")->assertStatus(403);
        $this->assertSame(PropertyDiscovery::STATUS_REGISTERED, $discovery->fresh()->status);

        // The holding desk still completes it.
        Sanctum::actingAs($this->accountManager());
        $this->postJson("/api/v1/discoveries/{$discovery->id}/complete")->assertOk();
        $this->assertSame(PropertyDiscovery::STATUS_COMPLETED, $discovery->fresh()->status);
    }
}
