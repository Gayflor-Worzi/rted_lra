<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reconciliation command for tasks left open by a stage that has since finished.
 *
 * A command that closes tasks is only safe if it closes strictly the tasks whose stage
 * is provably done and nothing else, and only when explicitly told to. These tests pin
 * both halves: the live work is never closed, and the finished work is.
 */
class CloseCompletedStageTasksCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function officer(): User
    {
        return User::where('email', 'val.officer@test.lra')->firstOrFail();
    }

    private function manager(): User
    {
        return User::where('email', 'val.manager@test.lra')->firstOrFail();
    }

    private function valuation(string $reference, string $status): Valuation
    {
        return Valuation::create([
            'valuation_reference' => $reference,
            'valuation_type' => 'regular',
            'status' => $status,
            'owner_name' => 'Stage Owner',
            'owner_contact' => '0770-000-111',
            'tin' => 'TIN-'.$reference,
            'property_address' => 'Stage Way',
            'property_classification' => 'Residential',
            'gps_coordinate' => '6.315611, -10.807407',
            'assessment_date' => now()->toDateString(),
            'photos' => 'proof://'.$reference,
            'reassessed_value' => 50000,
            'applicable_tax_rate' => 1.5,
            'valuation_officer_id' => $this->officer()->id,
        ]);
    }

    private function task(string $type, string $refType, int $refId, User $assignee): Task
    {
        return Task::create([
            'task_type' => $type,
            'section' => 'Valuation',
            'reference_type' => $refType,
            'reference_id' => $refId,
            'assigned_to' => $assignee->id,
            'assigned_by' => $this->manager()->id,
            'priority' => 'Normal',
            'status' => 'Assigned',
            'due_date' => now()->addDays(5)->toDateString(),
        ]);
    }

    public function test_it_reports_without_writing_by_default(): void
    {
        $valuation = $this->valuation('VAL-RECON-1', Valuation::STATUS_SUBMITTED_TO_MANAGER);
        $task = $this->task(Task::TYPE_VALUATION, 'valuation', $valuation->id, $this->officer());

        $this->artisan('lra:close-completed-stage-tasks')
            ->expectsOutputToContain($task->task_reference)
            ->assertSuccessful();

        // Untouched: report-only unless --execute.
        $this->assertSame('Assigned', $task->fresh()->status);
        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_it_closes_a_task_whose_stage_is_already_complete(): void
    {
        $valuation = $this->valuation('VAL-RECON-2', Valuation::STATUS_SUBMITTED_TO_MANAGER);
        $task = $this->task(Task::TYPE_VALUATION, 'valuation', $valuation->id, $this->officer());

        $this->artisan('lra:close-completed-stage-tasks --execute')->assertSuccessful();

        $task->refresh();
        $this->assertContains($task->status, Task::COMPLETED_STATUSES);
        $this->assertNotNull($task->completed_at);

        // Closed, not deleted, and auditable.
        $this->assertTrue(Task::whereKey($task->id)->exists());
        $this->assertTrue($task->history()->where('action', 'stage_reconciled')->exists());
        $this->assertTrue($task->engagements()->exists());
    }

    public function test_it_never_closes_work_that_is_still_owed(): void
    {
        $officerTask = null;
        $managerTask = null;

        // Officer still owes the assessment.
        $assigned = $this->valuation('VAL-RECON-3', Valuation::STATUS_ASSIGNED);
        $officerTask = $this->task(Task::TYPE_VALUATION, 'valuation', $assigned->id, $this->officer());

        // Manager still owes the review of an assessment sitting with the officer.
        $pending = $this->valuation('VAL-RECON-4', Valuation::STATUS_ASSIGNED);
        $managerTask = $this->task(Task::TYPE_VALUATION_REVIEW, 'valuation', $pending->id, $this->manager());

        // The review task the manager is genuinely working now.
        $live = $this->valuation('VAL-RECON-5', Valuation::STATUS_SUBMITTED_TO_MANAGER);
        $liveManagerTask = $this->task(Task::TYPE_VALUATION_REVIEW, 'valuation', $live->id, $this->manager());

        $this->artisan('lra:close-completed-stage-tasks --execute')->assertSuccessful();

        foreach ([$officerTask, $managerTask, $liveManagerTask] as $task) {
            $this->assertSame('Assigned', $task->fresh()->status, $task->task_reference.' is still owed and must stay open.');
        }
    }

    public function test_it_can_be_limited_to_one_task_reference(): void
    {
        $stale = $this->valuation('VAL-RECON-6', Valuation::STATUS_SUBMITTED_TO_MANAGER);
        $staleTask = $this->task(Task::TYPE_VALUATION, 'valuation', $stale->id, $this->officer());

        $other = $this->valuation('VAL-RECON-7', Valuation::STATUS_SUBMITTED_TO_MANAGER);
        $otherTask = $this->task(Task::TYPE_VALUATION, 'valuation', $other->id, $this->officer());

        $this->artisan('lra:close-completed-stage-tasks --execute --task='.$staleTask->task_reference)
            ->assertSuccessful();

        $this->assertContains($staleTask->fresh()->status, Task::COMPLETED_STATUSES);
        $this->assertSame('Assigned', $otherTask->fresh()->status, 'Out-of-scope tasks must be left alone.');
    }
}
