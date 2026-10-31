<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\Task;
use App\Models\User;
use App\Services\AssignmentEligibilityService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\LocalDemoUsers;
use Tests\Feature\Concerns\ApprovedEnforcementAssignments;
use Tests\TestCase;

/**
 * Account & Record Officer — "My Tasks" module (spec §1–§14, acceptance 1–10).
 *
 * The Account workspace must be fully independent of Enforcement: the officer
 * records + submits property discoveries, the Account & Record Manager approves
 * and assigns property registration, and the officer then sees only those
 * account-processing tasks under /account/my-tasks with an account_tasks badge.
 * An Enforcement Officer must be rejected outright (no enforcement dependency
 * leaks into the Account module, and no account module leaks into enforcement).
 */
class AccountTasksTest extends TestCase
{
    use LocalDemoUsers;
    use ApprovedEnforcementAssignments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * The officer picker must be filled from a lookup the officer can actually
     * call, and it must describe the role as a string.
     *
     * The picker used to be filled from /users, which is gated on staff.view.
     * An Account & Records Officer does not hold that permission, so the call
     * 403'd, the rejection was swallowed, and the dropdown rendered empty —
     * which is why approving a discovery reported "choose an officer" no matter
     * what was selected. /users also returns `role` as a string, so a client
     * filtering on `role.name` matched nobody even when the call succeeded.
     */
    public function test_the_assignable_lookup_is_open_to_officers_and_returns_the_role_as_a_string(): void
    {
        Sanctum::actingAs($this->officer());

        $rows = $this->getJson('/api/v1/lookup/assignable?task_type='.urlencode(Task::TYPE_ACCOUNT_PROCESSING))
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($rows, 'an Account & Records Officer must be assignable to account processing');

        $ids = array_column($rows, 'id');
        $this->assertContains($this->officer()->id, $ids);

        // Role must be a plain string, so a client can filter and display it.
        foreach ($rows as $row) {
            $this->assertIsString($row['role']);
            $this->assertNotSame('', $row['role']);
        }

        // Nothing sensitive is exposed to a caller without staff.view.
        $this->assertStringNotContainsString('email', (string) json_encode($rows));
    }

    /**
     * Only people the server would actually accept may be offered, so the
     * dropdown cannot hand the user a 422 after they have already chosen.
     */
    public function test_the_assignable_lookup_excludes_staff_who_cannot_run_the_task(): void
    {
        Sanctum::actingAs($this->manager());

        $rows = $this->getJson('/api/v1/lookup/assignable?task_type='.urlencode(Task::TYPE_ACCOUNT_PROCESSING))
            ->assertOk()
            ->json('data');

        $ids = array_column($rows, 'id');
        $this->assertNotContains(
            $this->enforcementOfficer()->id,
            $ids,
            'an Enforcement Officer cannot process account registration'
        );
    }

    public function test_the_assignable_lookup_rejects_an_unknown_task_type(): void
    {
        Sanctum::actingAs($this->manager());

        $this->getJson('/api/v1/lookup/assignable?task_type=Not+A+Real+Task')->assertStatus(422);
    }

    /**
     * The valuation desk assigns from the same picker, so the lookup has to
     * answer for valuation task types too rather than a fixed pair.
     */
    public function test_the_assignable_lookup_also_serves_the_valuation_assignment_picker(): void
    {
        Sanctum::actingAs($this->manager());

        $rows = $this->getJson('/api/v1/lookup/assignable?task_type='.urlencode(Task::TYPE_VALUATION))
            ->assertOk()
            ->json('data');

        $ids = array_column($rows, 'id');
        $this->assertContains(
            User::where('email', 'val.officer@test.lra')->value('id'),
            $ids,
            'a Valuation Officer must be assignable to a valuation'
        );
        $this->assertNotContains($this->officer()->id, $ids, 'an Account Officer cannot be assigned a valuation');
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function officer(): User
    {
        return User::where('email', 'account.officer@test.lra')->firstOrFail();
    }

    private function manager(): User
    {
        return User::where('email', 'account.manager@test.lra')->firstOrFail();
    }

    private function enforcementOfficer(): User
    {
        return User::where('email', 'enf.officer@test.lra')->firstOrFail();
    }

    private function enforcementSupervisor(): User
    {
        return $this->localEnforcementSupervisor();
    }

    private function discoveryPayload(string $classification, string $address): array
    {
        return [
            'owner_name' => "Owner {$classification}",
            'property_address' => $address,
            'property_classification' => $classification,
            'property_type' => 'House',
            'occupancy_use' => 'Residential',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'tin' => 'TIN-ACCT',
            'discovery_date' => now()->toDateString(),
        ];
    }

    /** POST /discoveries as the account officer -> created draft resource. */
    private function createDiscovery(string $classification, string $suffix = ''): array
    {
        Sanctum::actingAs($this->officer());

        return $this->postJson('/api/v1/discoveries',
            $this->discoveryPayload($classification, "Address {$classification}{$suffix}"))
            ->assertStatus(201)->json('data');
    }

    private function submitDiscovery(int $discoveryId): array
    {
        Sanctum::actingAs($this->officer());

        return $this->postJson("/api/v1/discoveries/{$discoveryId}/submit")
            ->assertStatus(200)->json('data');
    }

    /** Account & Record Manager approves the record and assigns property registration to $assignee. */
    private function approveForRegistration(int $discoveryId, User $assignee): array
    {
        Sanctum::actingAs($this->manager());

        return $this->postJson("/api/v1/discoveries/{$discoveryId}/acct-review", [
            'decision' => 'approve',
            'officer_id' => $assignee->id,
            'remarks' => 'Approved for property registration.',
        ])->assertStatus(200)->json('data');
    }

    /**
     * End-to-end proof that an officer offered by the assignable lookup is a
     * payload the approve endpoint actually accepts.
     *
     * The picker, the lookup and the mutation must agree: whatever
     * /lookup/assignable offers must be assignable, so a manager never picks a
     * name only to be rejected on submit.
     */
    public function test_every_offered_officer_is_accepted_by_the_approve_endpoint(): void
    {
        $discovery = $this->createDiscovery('Commercial');
        $this->submitDiscovery($discovery['id']);

        $offered = $this->getJson('/api/v1/lookup/assignable?task_type='.urlencode(Task::TYPE_ACCOUNT_PROCESSING))
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($offered);

        foreach ($offered as $candidate) {
            // Reset to the manager's desk so each candidate is exercised on a
            // record that is genuinely awaiting review.
            PropertyDiscovery::whereKey($discovery['id'])->update([
                'status' => PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW,
                'current_owner_id' => null,
            ]);

            $this->approveForRegistration($discovery['id'], User::findOrFail($candidate['id']));
        }
    }

    private function accountTasks(User $user, string $suffix = ''): array
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/v1/account/my-tasks'.$suffix)->assertOk()->json('data');
    }

    /* ------------------------------------------------------------------ */
    /*  Acceptance 1 — assigned registration task visible in My Tasks */
    /* ------------------------------------------------------------------ */

    public function test_account_officer_sees_their_assigned_registration_task(): void
    {
        $discovery = $this->createDiscovery('Residential');
        $this->submitDiscovery($discovery['id']);
        $approved = $this->approveForRegistration($discovery['id'], $this->officer());

        $this->assertSame(PropertyDiscovery::STATUS_ASSIGNED_ACCT, $approved['status']);
        $this->assertSame($this->officer()->id, $approved['current_owner_id']);

        $data = $this->accountTasks($this->officer());

        $this->assertCount(1, $data['data']);
        $this->assertSame(Task::TYPE_ACCOUNT_PROCESSING, $data['data'][0]['task_type']);
        $this->assertSame($discovery['discovery_reference'], $data['data'][0]['label']);
        $this->assertSame('Assigned', $data['data'][0]['status']);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->officer()->id,
            'type' => Notification::TYPE_ACCOUNT_TASKS,
            'action_url' => '/account/my-tasks',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Acceptance 2 — Account officer works with zero enforcement access */
    /* ------------------------------------------------------------------ */

    public function test_account_officer_works_fully_without_enforcement_permissions(): void
    {
        $officer = $this->officer();

        foreach (['enforcement.bill_delivery', 'enforcement.visit', 'enforcement.payment_followup', 'me.reassign', 'me.revise'] as $p) {
            $this->assertFalse($officer->canPermission($p), "Account officer must not hold {$p}.");
        }

        $this->createDiscovery('Residential');

        Sanctum::actingAs($officer);
        $this->getJson('/api/v1/account/my-tasks/summary')->assertOk();
        $this->getJson('/api/v1/account/my-tasks')->assertOk();
        $this->getJson('/api/v1/account/my-tasks/discoveries')->assertOk();
        $this->getJson('/api/v1/account/my-tasks/badge')->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /*  Acceptance 3 — Enforcement Officer rejected from the Account module */
    /* ------------------------------------------------------------------ */

    public function test_enforcement_officer_cannot_access_the_account_workspace(): void
    {
        Sanctum::actingAs($this->enforcementOfficer());

        $this->getJson('/api/v1/account/my-tasks/summary')->assertStatus(403);
        $this->getJson('/api/v1/account/my-tasks')->assertStatus(403);
        $this->getJson('/api/v1/account/my-tasks/discoveries')->assertStatus(403);
        $this->getJson('/api/v1/account/my-tasks/badge')->assertStatus(403);
    }

    /* ------------------------------------------------------------------ */
    /*  Acceptance 4 — non-account task types can never be forced on the */
    /*  Account officer (engine + endpoint) */
    /* ------------------------------------------------------------------ */

    public function test_bill_delivery_assignment_to_account_officer_is_rejected(): void
    {
        $officer = $this->officer();
        [, $enforcementSupervisor] = $this->approveEnforcementAssignment($this->enforcementOfficer());
        $this->assertFalse(AssignmentEligibilityService::canExecuteTaskType($officer, Task::TYPE_BILL_DELIVERY));
        $this->assertTrue(AssignmentEligibilityService::canExecuteTaskType($officer, Task::TYPE_ACCOUNT_PROCESSING));

        // Build a live bill-delivery task already assigned to an enforcement officer.
        Sanctum::actingAs($officer);
        $logged = $this->postJson('/api/v1/property-bills', [
            'document_number' => '2026/2001',
            'property_id' => 'P-ACCT-1',
            'taxpayer_name' => 'Taxpayer P-ACCT-1',
            'tin' => 'TIN-0001',
            'property_address' => 'Address P-ACCT-1',
            'tax_amount' => 5000.00,
            'tax_period' => '2025',
        ])->assertStatus(201)->json('data');

        $bill = PropertyBill::findOrFail($logged['id']);
        $task = app(TaskService::class)->createTaskFromBill($bill, $this->enforcementOfficer());

        // Enforcement Supervisor (has tasks.assign) tries to force the account
        // officer onto a Bill Delivery task.
        Sanctum::actingAs($enforcementSupervisor);
        $this->postJson("/api/v1/tasks/{$task->id}/assign", ['assigned_to' => $officer->id])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'does not have permission to perform Bill Delivery'));

        $this->assertSame($task->id, $bill->tasks()->orderByDesc('id')->first()->id);
        $this->assertSame($this->enforcementOfficer()->id, $bill->tasks()->orderByDesc('id')->first()->assigned_to);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $officer->id,
            'type' => Notification::TYPE_ACCOUNT_TASKS,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Acceptance 5 — My Discoveries = records I recorded */
    /* ------------------------------------------------------------------ */

    public function test_my_discoveries_lists_only_records_the_officer_recorded(): void
    {
        $first = $this->createDiscovery('Residential');
        $second = $this->createDiscovery('Commercial');
        $this->submitDiscovery($first['id']);

        // Another officer records one too — must not appear in mine.
        Sanctum::actingAs($this->manager());
        $this->postJson('/api/v1/discoveries', $this->discoveryPayload('Residential', 'Manager discovery'))
            ->assertStatus(201);

        Sanctum::actingAs($this->officer());
        $rows = $this->getJson('/api/v1/account/my-tasks/discoveries')->assertOk()->json('data.data');

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertContains($row['discovery_reference'], [$first['discovery_reference'], $second['discovery_reference']]);
            $this->assertSame($this->officer()->full_name, $row['discoverer']);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Acceptance 6 — valuation-required classification routes to valuation */
    /* ------------------------------------------------------------------ */

    public function test_valuation_required_classification_routes_to_valuation_manager(): void
    {
        foreach (['Commercial', 'Industrial', 'Mixed Use', 'Institutional'] as $classification) {
            $discovery = $this->createDiscovery($classification, '-#'.$classification);
            $submitted = $this->submitDiscovery($discovery['id']);

            $this->assertSame('valuation', $submitted['route']);
            $this->assertSame('valuation_manager', $submitted['queue']);
            $this->assertSame(PropertyDiscovery::STATUS_AWAITING_VALUATION, $submitted['status']);

            $task = Task::where('task_type', Task::TYPE_VALUATION_MANAGER_REVIEW)
                ->where('reference_type', 'property_discovery')
                ->where('reference_id', $discovery['id'])
                ->first();
            $this->assertNotNull($task, "Missing Valuation Manager review task for {$classification}.");
        }

        $this->assertFalse(
            AssignmentEligibilityService::canExecuteTaskType($this->officer(), Task::TYPE_VALUATION),
            'Account officers must not be able to execute Valuation tasks.'
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Acceptance 7 — self-declared classification routes to Account & */
    /*  Record Manager */
    /* ------------------------------------------------------------------ */

    public function test_self_declared_classification_routes_to_account_manager(): void
    {
        foreach (['Residential', 'Vacant Land', 'Agricultural', 'Government', 'Community'] as $classification) {
            $discovery = $this->createDiscovery($classification, '-#'.$classification);
            $submitted = $this->submitDiscovery($discovery['id']);

            $this->assertSame('account', $submitted['route']);
            $this->assertSame('acct_manager', $submitted['queue']);
            $this->assertSame(PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW, $submitted['status']);

            $this->assertDatabaseHas('tasks', [
                'task_type' => Task::TYPE_DISCOVERY_REVIEW,
                'reference_type' => 'property_discovery',
                'reference_id' => $discovery['id'],
                'assigned_to' => $this->manager()->id,
            ]);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Acceptance 8 — badge increments, then read acknowledges without */
    /*  deleting the trail */
    /* ------------------------------------------------------------------ */

    public function test_badge_increments_and_read_clears_it_but_keeps_history(): void
    {
        $discovery = $this->createDiscovery('Residential');
        $this->submitDiscovery($discovery['id']);
        $this->approveForRegistration($discovery['id'], $this->officer());

        // A re-run of the same reference re-notifies without stacking a task
        // (the second alert experiments the badge to 2).
        app(TaskService::class)->createAccountProcessingTask(
            'property_discovery',
            $discovery['id'],
            "Discovery {$discovery['discovery_reference']}",
            $this->officer(),
        );

        Sanctum::actingAs($this->officer());
        $this->getJson('/api/v1/account/my-tasks/badge')
            ->assertOk()
            ->assertJsonPath('data.unread', 2);

        $this->assertSame(2, Notification::where('user_id', $this->officer()->id)
            ->where('type', Notification::TYPE_ACCOUNT_TASKS)->count());
        $this->assertSame(2, Notification::where('user_id', $this->officer()->id)
            ->where('type', Notification::TYPE_ACCOUNT_TASKS)
            ->whereNull('read_at')->count());

        $this->postJson('/api/v1/account/my-tasks/read')
            ->assertOk()
            ->assertJsonPath('message', 'Assignment alerts acknowledged.');

        $this->getJson('/api/v1/account/my-tasks/badge')->assertJsonPath('data.unread', 0);

        // History is preserved — both rows remain, now acknowledged.
        $this->assertSame(2, Notification::where('user_id', $this->officer()->id)
            ->where('type', Notification::TYPE_ACCOUNT_TASKS)->count());
        $this->assertSame(0, Notification::where('user_id', $this->officer()->id)
            ->where('type', Notification::TYPE_ACCOUNT_TASKS)
            ->whereNull('read_at')->count());
        $this->assertNotNull(Notification::where('user_id', $this->officer()->id)
            ->where('type', Notification::TYPE_ACCOUNT_TASKS)
            ->latest()->first()->read_at);
    }

    /* ------------------------------------------------------------------ */
    /*  Acceptance 9 — every assignment is listed; Payment Verification is */
    /*  excluded from the account task set */
    /* ------------------------------------------------------------------ */

    public function test_my_tasks_lists_all_assignments_and_excludes_payment_verification(): void
    {
        $officer = $this->officer();

        for ($i = 1; $i <= 3; $i++) {
            $discovery = $this->createDiscovery('Residential', " #{$i}");
            $this->submitDiscovery($discovery['id']);
            $this->approveForRegistration($discovery['id'], $officer);
        }

        // Bypass: payment verification stays in its own module, not "My Tasks".
        Task::create([
            'task_type' => Task::TYPE_PAYMENT_VERIFICATION,
            'section' => 'ACCT',
            'reference_type' => 'property_bill',
            'reference_id' => null,
            'assigned_to' => $officer->id,
            'assigned_by' => $officer->id,
            'priority' => 'Normal',
            'status' => 'Assigned',
            'due_date' => now()->addDays(7)->toDateString(),
            'remarks' => 'Payment verification (own module).',
        ]);

        $data = $this->accountTasks($officer, '?per_page=50');

        $this->assertCount(3, $data['data']);
        $this->assertSame(3, $data['total']);

        foreach ($data['data'] as $row) {
            $this->assertSame(Task::TYPE_ACCOUNT_PROCESSING, $row['task_type']);
        }

        Sanctum::actingAs($officer);
        $this->postJson('/api/v1/account/my-tasks/read');

        $summary = $this->getJson('/api/v1/account/my-tasks/summary')->assertOk()->json('data');
        $this->assertSame(3, $summary['assigned']);
        $this->assertSame(3, $summary['discoveries']);
        $this->assertSame(0, $summary['unread']);
    }

    /* ------------------------------------------------------------------ */
    /*  Acceptance 10 — reusing a reference never stacks a second open task */
    /* ------------------------------------------------------------------ */

    public function test_reusing_a_reference_does_not_stack_a_second_open_task(): void
    {
        $discovery = $this->createDiscovery('Residential');
        $this->submitDiscovery($discovery['id']);

        $app = app(TaskService::class);
        $officer = $this->officer();

        $first = $app->createAccountProcessingTask('property_discovery', $discovery['id'], "Discovery {$discovery['discovery_reference']}", $officer);
        $second = $app->createAccountProcessingTask('property_discovery', $discovery['id'], "Discovery {$discovery['discovery_reference']}", $officer);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Task::where('task_type', Task::TYPE_ACCOUNT_PROCESSING)
            ->where('reference_type', 'property_discovery')
            ->where('reference_id', $discovery['id'])
            ->whereNull('completed_at')->count());

        // Both runs still alert the officer (badge re-increments on re-notify),
        // but the open task count stays at one.
        $data = $this->accountTasks($officer);
        $this->assertCount(1, $data['data']);
    }
}
