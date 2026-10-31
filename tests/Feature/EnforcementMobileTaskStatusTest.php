<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnforcementMobileTaskStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_my_assignments_supports_all_mobile_status_groups(): void
    {
        $officer = User::where('email', 'enf.officer@test.lra')->firstOrFail();
        Sanctum::actingAs($officer);

        $tasks = collect([
            ['status' => 'Assigned', 'due_date' => now()->addDay()->toDateString()],
            ['status' => 'Assigned', 'due_date' => now()->subDay()->toDateString()],
            ['status' => 'Escalated', 'due_date' => now()->addDay()->toDateString()],
            ['status' => 'Resolved', 'due_date' => now()->subDay()->toDateString()],
        ])->map(fn (array $task) => Task::create([
            'task_type' => Task::TYPE_BILL_DELIVERY,
            'section' => 'Enforcement',
            'status' => $task['status'],
            'assigned_to' => $officer->id,
            'due_date' => $task['due_date'],
        ]));

        $this->getJson('/api/v1/enforcement-assignments/my?status_group=all')
            ->assertOk()
            ->assertJsonCount(4, 'data.data');

        $this->getJson('/api/v1/enforcement-assignments/my?status_group=active')
            ->assertOk()
            ->assertJsonCount(3, 'data.data');

        $this->getJson('/api/v1/enforcement-assignments/my?status_group=pending')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');

        $this->getJson('/api/v1/enforcement-assignments/my?status_group=overdue')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.status', 'Assigned');

        $this->getJson('/api/v1/enforcement-assignments/my?status_group=escalated')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.status', 'Escalated');

        $this->getJson('/api/v1/enforcement-assignments/my?status_group=completed')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.status', 'Resolved');

        $this->assertSame(4, $tasks->count());
    }
}
