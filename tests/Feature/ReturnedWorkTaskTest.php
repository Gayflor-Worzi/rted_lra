<?php

namespace Tests\Feature;

use App\Models\PropertyDiscovery;
use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A return is work, not just a message.
 *
 * Every desk could return a discovery for correction, and every desk only sent a
 * notification. The notification says something came back, but nothing the
 * officer owns says "you still have to fix this, and it is now overdue": the
 * returned record sat in no task list, aged silently, and the return reason
 * lived only in the notification body.
 *
 * These tests pin the correction task: it exists, it is assigned to the officer
 * who recorded the record, it carries a due date and the reason, returning the
 * same record again continues the same Task ID instead of stacking duplicates,
 * resubmitting finishes it, and a rejection (terminal by policy) raises none.
 */
class ReturnedWorkTaskTest extends TestCase
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

    private function act(string $email): void
    {
        Sanctum::actingAs($this->user($email));
    }

    private function payload(string $suffix, string $classification = 'Commercial'): array
    {
        return [
            'owner_name' => 'Owner '.$suffix,
            'owner_contact' => '0770-100-130',
            'tin' => 'TIN-RW-'.$suffix,
            'property_address' => 'Address '.$suffix.', Monrovia',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'property_classification' => $classification,
            'property_type' => 'House',
            'occupancy_use' => $classification,
            'discovery_date' => now()->toDateString(),
            'gps_coordinate' => '6.3130,-10.8074',
        ];
    }

    /** A submitted discovery waiting at the Account & Record Manager's desk. */
    private function awaitingAccountReview(string $suffix): PropertyDiscovery
    {
        $this->act('account.officer@test.lra');
        $id = $this->postJson('/api/v1/discoveries', $this->payload($suffix, 'Residential'))
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/discoveries/{$id}/submit")->assertOk();

        return PropertyDiscovery::findOrFail($id);
    }

    /** A submitted commercial discovery waiting at the Valuation Manager's desk. */
    private function awaitingValuation(string $suffix): PropertyDiscovery
    {
        $this->act('account.officer@test.lra');
        $id = $this->postJson('/api/v1/discoveries', $this->payload($suffix))
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/discoveries/{$id}/submit")->assertOk();

        return PropertyDiscovery::findOrFail($id);
    }

    /** Drive a commercial discovery all the way to the Assistant Commissioner. */
    private function awaitingAc(string $suffix): array
    {
        $discovery = $this->awaitingValuation($suffix);

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/val-review", [
            'decision' => 'assign',
            'officer_id' => $this->user('val.officer@test.lra')->id,
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
            'photos' => 'proof://VAL-RW-'.$suffix,
            'owner_contact' => '0770-100-130',
        ])->assertOk();
        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'assessed_value' => 1200000.00,
            'annual_tax' => 42000.00,
            'applicable_tax_rate' => 3.5,
        ])->assertOk();

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/review", [
            'decision' => 'confirm_ac',
            'remarks' => 'Assessment complete.',
        ])->assertOk();

        return [$discovery->fresh(), $valuation->fresh()];
    }

    /** The correction tasks belonging to one discovery, newest first. */
    private function correctionTasks(PropertyDiscovery $discovery)
    {
        return Task::where('task_type', Task::TYPE_OTHER)
            ->where('reference_type', 'property_discovery')
            ->where('reference_id', $discovery->id)
            ->orderBy('id')
            ->get();
    }

    public function test_an_account_and_record_return_raises_traceable_correction_work(): void
    {
        $discovery = $this->awaitingAccountReview('RW1');
        $recorder = $this->user('account.officer@test.lra');

        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/acct-review", [
            'decision' => 'return',
            'remarks' => 'Owner name does not match the deed.',
        ])->assertOk();

        $this->assertSame(PropertyDiscovery::STATUS_RETURNED, $discovery->fresh()->status);

        $tasks = $this->correctionTasks($discovery);
        $this->assertCount(1, $tasks);

        $task = $tasks->first();
        $this->assertSame($recorder->id, $task->assigned_to, 'The correction is the recorder\'s work.');
        $this->assertSame($this->user('account.manager@test.lra')->id, $task->assigned_by);
        $this->assertSame('Assigned', $task->status);
        $this->assertNull($task->completed_at);
        $this->assertNotNull($task->due_date, 'Correction work must be able to age.');
        $this->assertStringContainsString('Owner name does not match the deed.', (string) $task->remarks);
        $this->assertStringContainsString('Account & Record Manager', (string) $task->remarks);

        // The reason is also in the task's own trail, not only on the notification.
        $history = $task->history()->get();
        $returned = $history->firstWhere('action', 'returned_for_correction');
        $this->assertNotNull($returned, 'The return must be recorded on the task.');
        $this->assertStringContainsString('Owner name does not match the deed.', (string) $returned->remarks);
    }

    public function test_the_correction_task_is_continued_rather_than_duplicated(): void
    {
        $discovery = $this->awaitingAccountReview('RW2');
        $recorder = $this->user('account.officer@test.lra');
        $manager = $this->user('account.manager@test.lra');

        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/acct-review", [
            'decision' => 'return',
            'remarks' => 'First reason.',
        ])->assertOk();

        // A second return while the correction is still open continues the same
        // task: one piece of work, one Task ID, and both reasons in its history.
        $service = app(TaskService::class);
        $again = $service->createDiscoveryCorrectionTask($discovery->fresh(), $recorder, $manager, 'Second reason.');

        $tasks = $this->correctionTasks($discovery);
        $this->assertCount(1, $tasks, 'Returning twice must not stack duplicate tasks.');
        $this->assertSame($tasks->first()->id, $again->id);

        $actions = $tasks->first()->history()->where('action', 'returned_for_correction')->get();
        $this->assertCount(2, $actions);
        $this->assertStringContainsString('Second reason.', (string) $actions->last()->remarks);
    }

    public function test_resubmitting_finishes_the_correction_task(): void
    {
        $discovery = $this->awaitingAccountReview('RW3');

        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/acct-review", [
            'decision' => 'return',
            'remarks' => 'Add the correct TIN.',
        ])->assertOk();

        $task = $this->correctionTasks($discovery)->first();

        $this->act('account.officer@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/resubmit")->assertOk();

        $task->refresh();
        $this->assertSame('Closed', $task->status);
        $this->assertNotNull($task->completed_at, 'The work is finished, so it must leave the active list.');

        $closed = $task->history()->where('action', 'resubmitted_for_review')->first();
        $this->assertNotNull($closed, 'Closing the correction task must be recorded as its own event.');
        $this->assertSame('Assigned', $closed->from_status);
        $this->assertSame('Closed', $closed->to_status);

        $this->assertGreaterThan(
            0,
            $task->engagements()->count(),
            'A finished task records the engagement that closed it.'
        );

        // The record moved on, and no correction task is left open.
        $this->assertNotSame(PropertyDiscovery::STATUS_RETURNED, $discovery->fresh()->status);
        $this->assertSame(
            0,
            $this->correctionTasks($discovery)->whereNull('completed_at')->count()
        );
    }

    public function test_a_later_return_is_new_work_and_the_finished_task_is_kept(): void
    {
        $discovery = $this->awaitingAccountReview('RW4');

        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/acct-review", [
            'decision' => 'return',
            'remarks' => 'First correction.',
        ])->assertOk();
        $first = $this->correctionTasks($discovery)->first();

        $this->act('account.officer@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/resubmit")->assertOk();

        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/acct-review", [
            'decision' => 'return',
            'remarks' => 'Second correction.',
        ])->assertOk();

        $tasks = $this->correctionTasks($discovery);
        $this->assertCount(2, $tasks, 'A new correction cycle is new work, not an edit of the old task.');
        $this->assertNotSame($first->id, $tasks->last()->id);

        // Nothing is rewritten or deleted: the first cycle stays closed with its reason.
        $first->refresh();
        $this->assertSame('Closed', $first->status);
        $this->assertSame(
            1,
            $this->correctionTasks($discovery)->whereNull('completed_at')->count(),
            'Only the current correction cycle is open.'
        );
        $this->assertStringContainsString('First correction.', (string) $first->remarks);
    }

    public function test_a_valuation_manager_return_raises_the_same_correction_work(): void
    {
        $discovery = $this->awaitingValuation('RW5');

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/val-review", [
            'decision' => 'return',
            'remarks' => 'The address is incomplete.',
        ])->assertOk();

        $task = $this->correctionTasks($discovery)->first();

        $this->assertNotNull($task, 'A return from this desk is also real correction work.');
        $this->assertSame($this->user('account.officer@test.lra')->id, $task->assigned_to);
        $this->assertNull($task->completed_at);
        $this->assertStringContainsString('Valuation Manager', (string) $task->remarks);
        $this->assertStringContainsString('The address is incomplete.', (string) $task->remarks);
    }

    public function test_an_ac_return_raises_correction_work_but_a_reject_does_not(): void
    {
        // Return: the record goes back to the recorder, so there is work to do.
        [$returned] = $this->awaitingAc('RW6');

        $this->act('ac@test.lra');
        $this->postJson("/api/v1/valuations/{$returned->valuation_id}/decide", [
            'decision' => 'return',
            'remarks' => 'Re-check the floor area.',
        ])->assertOk();

        $tasks = $this->correctionTasks($returned);
        $this->assertCount(1, $tasks);
        $this->assertSame($this->user('account.officer@test.lra')->id, $tasks->first()->assigned_to);
        $this->assertStringContainsString('Assistant Commissioner', (string) $tasks->first()->remarks);

        // Reject: terminal by policy, so no correction task is invented for it.
        [$rejected] = $this->awaitingAc('RW7');

        $this->act('ac@test.lra');
        $this->postJson("/api/v1/valuations/{$rejected->valuation_id}/decide", [
            'decision' => 'reject',
            'remarks' => 'Not supportable.',
        ])->assertOk();

        $this->assertSame(PropertyDiscovery::STATUS_REJECTED, $rejected->fresh()->status);
        $this->assertCount(
            0,
            $this->correctionTasks($rejected),
            'A rejection is not a disguised return and must not create correction work.'
        );
    }

    public function test_the_recorder_can_reach_and_read_their_correction_task(): void
    {
        $discovery = $this->awaitingAccountReview('RW8');

        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/acct-review", [
            'decision' => 'return',
            'remarks' => 'Correct the owner name.',
        ])->assertOk();

        $task = $this->correctionTasks($discovery)->first();

        // The recorder's own task list (task engine, mine) shows the correction.
        $this->act('account.officer@test.lra');
        $listed = collect($this->getJson('/api/v1/tasks?view=mine&per_page=100')->assertOk()->json('data.data'))
            ->pluck('id');
        $this->assertTrue($listed->contains($task->id), 'The correction task must appear in the recorder\'s own list.');

        // And it can be opened directly, which is what a notification links to.
        $this->getJson("/api/v1/tasks/{$task->id}")->assertOk();
    }
}
