<?php

namespace Tests\Feature;

use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;
use App\Services\TaskService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2 — one property, one task per workflow step.
 *
 * A Source Reference ID identifies the property; a Task ID identifies one piece
 * of work on it. Routing, reassigning, returning and resubmitting continue that
 * work, so they must move the SAME task forward instead of minting a new one.
 * A genuinely different step (the assessment after the routing decision) is its
 * own task — separate tasks are correct, duplicates of the same task are not.
 */
class TaskIdentityContinuityTest extends TestCase
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

    private function discoverer(): User
    {
        return $this->user('val.officer@test.lra');
    }

    private function manager(): User
    {
        return $this->user('val.manager@test.lra');
    }

    private function officer(): User
    {
        return $this->user('account.officer@test.lra');
    }

    private function commercialDiscovery(): PropertyDiscovery
    {
        return PropertyDiscovery::create([
            'discovered_by' => $this->discoverer()->id,
            'owner_name' => 'Task Continuity Owner',
            'owner_contact' => '0777000222',
            'tin' => 'TIN-TASK-1',
            'property_address' => '3 Continuity Road, Monrovia',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'property_classification' => 'Commercial',
            'property_type' => 'Commercial',
            'occupancy_use' => 'Commercial',
            'value_declaration_type' => PropertyDiscovery::VALUE_DECLARATION_VALUATION,
            'gps_coordinate' => '6.3130,-10.8074',
            'discovery_date' => now()->toDateString(),
        ]);
    }

    private function submit(PropertyDiscovery $discovery): void
    {
        Sanctum::actingAs($this->discoverer());
        $this->postJson("/api/v1/discoveries/{$discovery->id}/submit")->assertStatus(200);
    }

    private function openTasksFor(PropertyDiscovery $discovery, ?Valuation $valuation = null)
    {
        return Task::query()
            ->whereNull('completed_at')
            ->where(function ($q) use ($discovery, $valuation) {
                $q->where(fn ($x) => $x->where('reference_type', 'property_discovery')
                    ->where('reference_id', $discovery->id));

                if ($valuation) {
                    $q->orWhere(fn ($x) => $x->where('reference_type', 'valuation')
                        ->where('reference_id', $valuation->id));
                }
            })
            ->get();
    }

    /* ------------------------------------------------------------------ */
    /* Continuing a task keeps its identifier */
    /* ------------------------------------------------------------------ */

    public function test_repeating_a_desk_task_returns_the_same_task_instead_of_a_second_one(): void
    {
        $discovery = $this->commercialDiscovery();
        $service = app(TaskService::class);

        $first = $service->createValuationManagerReviewTask($discovery, $this->manager(), $this->discoverer());
        $second = $service->createValuationManagerReviewTask($discovery, $this->manager(), $this->discoverer());

        $this->assertSame($first->id, $second->id, 'The same workflow step must keep one Task ID.');
        $this->assertSame($first->task_reference, $second->task_reference);

        $this->assertSame(
            1,
            Task::where('task_type', Task::TYPE_VALUATION_MANAGER_REVIEW)
                ->where('reference_type', 'property_discovery')
                ->where('reference_id', $discovery->id)
                ->count()
        );
    }

    public function test_returning_and_resubmitting_continues_the_same_review_task(): void
    {
        $discovery = $this->commercialDiscovery();
        $this->submit($discovery);

        $review = Task::where('task_type', Task::TYPE_VALUATION_MANAGER_REVIEW)
            ->where('reference_type', 'property_discovery')
            ->where('reference_id', $discovery->id)
            ->firstOrFail();

        // The Valuation Manager sends it back for correction.
        Sanctum::actingAs($this->manager());
        $this->postJson("/api/v1/discoveries/{$discovery->id}/val-review", [
            'decision' => 'return',
            'remarks' => 'GPS coordinate is missing for the parcel.',
        ])->assertStatus(200);

        // The officer corrects and resubmits.
        Sanctum::actingAs($this->discoverer());
        $this->postJson("/api/v1/discoveries/{$discovery->id}/resubmit")->assertStatus(200);

        $after = Task::where('task_type', Task::TYPE_VALUATION_MANAGER_REVIEW)
            ->where('reference_type', 'property_discovery')
            ->where('reference_id', $discovery->id)
            ->whereNull('completed_at')
            ->get();

        $this->assertCount(1, $after, 'Resubmission must not raise a second review task.');
        $this->assertSame($review->id, $after->first()->id, 'The Task ID must survive the return/resubmit cycle.');
    }

    public function test_continuation_reassigns_the_same_task_and_records_the_move(): void
    {
        $discovery = $this->commercialDiscovery();
        $service = app(TaskService::class);

        $task = $service->createValuationManagerReviewTask($discovery, null, $this->discoverer());
        $this->assertNull($task->assigned_to);

        $continued = $service->createValuationManagerReviewTask($discovery, $this->manager(), $this->discoverer());

        $this->assertSame($task->id, $continued->id, 'Routing to a desk continues the task, it does not replace it.');
        $this->assertSame($this->manager()->id, $continued->fresh()->assigned_to);

        $this->assertTrue(
            $continued->history()->where('action', 'reassign')->exists(),
            'The continuation must appear in the task history.'
        );
    }

    public function test_a_bill_that_is_logged_again_keeps_one_delivery_task(): void
    {
        $bill = PropertyBill::create([
            'document_number' => 'DOC-TASK-1',
            'property_id' => 'SRC-TASK-1',
            'taxpayer_name' => 'Task Continuity Owner',
            'property_address' => '3 Continuity Road, Monrovia',
            'tax_amount' => 500,
            'tax_period' => '2026',
            'date_logged' => now()->toDateString(),
        ]);

        $service = app(TaskService::class);
        $first = $service->createTaskFromBill($bill);
        $second = $service->createTaskFromBill($bill);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            1,
            Task::where('task_type', Task::TYPE_BILL_DELIVERY)
                ->where('reference_type', 'property_bill')
                ->where('reference_id', $bill->id)
                ->count()
        );
    }

    /* ------------------------------------------------------------------ */
    /* The backstop is in the data, not only in the UI */
    /* ------------------------------------------------------------------ */

    public function test_the_database_refuses_a_second_open_task_for_the_same_workflow_step(): void
    {
        $discovery = $this->commercialDiscovery();

        $service = app(TaskService::class);
        $service->createValuationManagerReviewTask($discovery, $this->manager(), $this->discoverer());

        $thrown = null;

        try {
            DB::table('tasks')->insert([
                'task_reference' => 'TASK-2026-99999',
                'task_type' => Task::TYPE_VALUATION_MANAGER_REVIEW,
                'section' => 'VAL',
                'reference_type' => 'property_discovery',
                'reference_id' => $discovery->id,
                'status' => 'Awaiting Assignment',
                'priority' => 'Normal',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'A second open task for the same workflow step must violate the open-task index.');
        $this->assertSame('23000', (string) $thrown->getCode());
    }

    public function test_a_closed_task_does_not_block_the_next_round_of_the_same_step(): void
    {
        $discovery = $this->commercialDiscovery();
        $service = app(TaskService::class);

        $first = $service->createValuationManagerReviewTask($discovery, $this->manager(), $this->discoverer());
        $service->closeStageTasks(
            Task::TYPE_VALUATION_MANAGER_REVIEW,
            'property_discovery',
            $discovery->id,
            'routed_to_valuation',
            $this->manager(),
        );
        $first->refresh();
        $this->assertNotNull($first->completed_at);

        // A later, genuinely new round of the same step gets its own task.
        $second = $service->createValuationManagerReviewTask($discovery, $this->manager(), $this->discoverer());

        $this->assertNotSame($first->id, $second->id);
        $this->assertNull($second->completed_at);
    }

    /* ------------------------------------------------------------------ */
    /* Legitimate separate tasks remain separate */
    /* ------------------------------------------------------------------ */

    public function test_one_property_keeps_distinct_tasks_for_distinct_steps(): void
    {
        $discovery = $this->commercialDiscovery();
        $this->submit($discovery);

        Sanctum::actingAs($this->manager());
        $this->postJson("/api/v1/discoveries/{$discovery->id}/val-review", [
            'decision' => 'assign',
            'officer_id' => $this->discoverer()->id,
        ])->assertStatus(200);

        $valuation = Valuation::where('discovery_id', $discovery->id)->firstOrFail();

        $review = Task::where('task_type', Task::TYPE_VALUATION_MANAGER_REVIEW)
            ->where('reference_type', 'property_discovery')
            ->where('reference_id', $discovery->id)
            ->firstOrFail();
        $assessment = Task::where('task_type', Task::TYPE_VALUATION)
            ->where('reference_type', 'valuation')
            ->where('reference_id', $valuation->id)
            ->firstOrFail();

        $this->assertNotSame($review->id, $assessment->id, 'A different workflow step is a different task.');

        // Both still point at one property: the assessment was created from the
        // discovery, and both records share its permanent identity.
        $this->assertSame($discovery->property_ref_id, $valuation->property_ref_id);
        $this->assertSame($discovery->id, $valuation->discovery_id);

        // Only the step that is actually outstanding stays open.
        $open = $this->openTasksFor($discovery, $valuation);
        $this->assertSame([$assessment->id], $open->pluck('id')->all());
    }
}
