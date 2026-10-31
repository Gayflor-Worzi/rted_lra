<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\LocalDemoUsers;
use Tests\TestCase;

class EnforcementTaskStatusGroupsTest extends TestCase
{
    use LocalDemoUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_task_status_groups_include_the_correct_enforcement_workflow_stages(): void
    {
        $officer = User::where('email', 'enf.officer@test.lra')->firstOrFail();
        Sanctum::actingAs($officer);

        $tasks = collect(Task::STATUSES)->mapWithKeys(function (string $status) use ($officer) {
            $task = Task::create([
                'task_type' => Task::TYPE_BILL_DELIVERY,
                'section' => 'ENF',
                'assigned_to' => $officer->id,
                'status' => $status,
                'due_date' => now()->addDays(30)->toDateString(),
            ]);

            return [$status => $task->id];
        });

        $fetchIds = fn (string $group) => collect($this->getJson(
            "/api/v1/tasks?workspace=enforcement&view=mine&status_group={$group}&per_page=100"
        )->assertOk()->json('data.data'))->pluck('id')->all();

        $completed = collect(Task::COMPLETED_STATUSES)
            ->map(fn (string $status) => $tasks[$status])
            ->values()
            ->all();
        $pending = collect(Task::STATUSES)
            ->reject(fn (string $status) => in_array($status, [...Task::COMPLETED_STATUSES, 'Escalated'], true))
            ->map(fn (string $status) => $tasks[$status])
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing($pending, $fetchIds('pending'));
        $this->assertEqualsCanonicalizing([$tasks['Escalated']], $fetchIds('escalated'));
        $this->assertEqualsCanonicalizing($completed, $fetchIds('completed'));
    }
}
