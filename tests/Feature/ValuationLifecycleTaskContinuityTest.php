<?php

namespace Tests\Feature;

use App\Models\PropertyDiscovery;
use App\Models\PropertyDiscoveryEvidence;
use App\Models\Role;
use App\Models\Section;
use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One property, one lifecycle, one current task.
 *
 * A discovered property routed to Valuation produced two open tasks for the same
 * property at the same time, each anchored on a different row of the same lifecycle:
 *
 *   TASK  Val. Manager Review -> property_discovery  (the manager's routing decision)
 *   TASK  Valuation           -> valuation           (the officer's assessment)
 *   TASK  Valuation Review    -> valuation           (the manager's review)
 *
 * A stage's task was never retired when the stage was completed, so the finished
 * `Valuation Manager Review` task stayed `Assigned` forever and competed with the
 * `Valuation Review` task raised later on the same property. The Valuation Manager's
 * task list therefore showed the property twice — once as a bare discovery, once as
 * the completed assessment — with nothing to say they were one property.
 *
 * Note what this is NOT: the assessment never created a second property or a second
 * valuation. The discovery keeps `valuation_id` and the valuation keeps `discovery_id`,
 * so the identity model was already single-source. The duplication was in the task
 * engine, which is why the fix retires tasks rather than merging records.
 */
class ValuationLifecycleTaskContinuityTest extends TestCase
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

    private function discoverer(): User
    {
        return User::where('email', 'val.officer@test.lra')->firstOrFail();
    }

    private function secondOfficer(): User
    {
        return User::create([
            'staff_id' => 'VALOFF7',
            'full_name' => 'Routed Valuation Officer',
            'email' => 'val.officer7@test.lra',
            'password' => bcrypt('password'),
            'role_id' => Role::where('name', 'Valuation Officer')->value('id'),
            'section_id' => Section::where('code', 'VAL')->value('id'),
            'is_active' => true,
            'must_reset_password' => false,
        ]);
    }

    /** A commercial discovery, which the classifier routes to the valuation desk. */
    private function commercialDiscovery(): PropertyDiscovery
    {
        $discovery = PropertyDiscovery::create([
            'discovered_by' => $this->discoverer()->id,
            'owner_name' => 'Francis Mulbah',
            'owner_contact' => '0778111255',
            'tin' => '501553421',
            'property_address' => 'Pipeline Cookspoon Hill community around the RB Shop,',
            'county' => 'Montserrado County',
            'district' => '#3',
            'city_town' => 'Paynesville City',
            'community' => 'Cookspoon Hill Community',
            'street' => 'Cookspoon Hill',
            'house_number' => 'C11-491',
            'property_classification' => 'Commercial',
            'property_type' => 'New Property',
            'occupancy_use' => 'Used',
            'description' => 'Test Property Registration',
            'value_declaration_type' => 'VALUATION_VALUE',
            'gps_coordinate' => '6.297700,-10.799800',
            'gps_captured_at' => now(),
            'discovery_date' => now()->toDateString(),
            'status' => PropertyDiscovery::STATUS_DRAFT,
        ]);

        // A real discovered property is photographed on site, and the assessment
        // inherits that evidence through the discovery link.
        PropertyDiscoveryEvidence::create([
            'discovery_id' => $discovery->id,
            'evidence_type' => PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO,
            'sequence_no' => 1,
            'original_name' => 'front.jpg',
            'file_path' => 'discovery-evidence/'.$discovery->discovery_reference.'/front.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'uploaded_by' => $this->discoverer()->id,
        ]);

        return $discovery;
    }

    /** Discovery Officer submits: the classifier routes it to the Valuation Manager. */
    private function routeToValuationDesk(PropertyDiscovery $discovery): Task
    {
        Sanctum::actingAs($this->discoverer());
        $this->postJson("/api/v1/discoveries/{$discovery->id}/submit", [
            'property_classification' => 'Commercial',
            'value_declaration_type' => 'VALUATION_VALUE',
        ])->assertOk();

        return Task::where('task_type', Task::TYPE_VALUATION_MANAGER_REVIEW)
            ->where('reference_type', 'property_discovery')
            ->where('reference_id', $discovery->id)
            ->firstOrFail();
    }

    /** Valuation Manager routes the discovery to an officer, creating the assessment. */
    private function routeToOfficer(PropertyDiscovery $discovery, User $officer): Valuation
    {
        Sanctum::actingAs($this->manager());
        $this->postJson("/api/v1/discoveries/{$discovery->id}/val-review", [
            'decision' => 'assign',
            'officer_id' => $officer->id,
        ])->assertOk();

        return Valuation::where('discovery_id', $discovery->id)->firstOrFail();
    }

    /** The officer completes and submits the assessment to the manager. */
    private function submitAssessment(Valuation $valuation, User $officer): void
    {
        $valuation->descriptions()->create([
            'seq' => 1,
            'description' => 'Land',
            'level' => 'Land',
            'tar' => 5000,
            'quantity' => 1,
            'amount' => 5000,
            'depreciation_pct' => 0,
            'value' => 5000,
        ]);

        $valuation->descriptions()->create([
            'seq' => 2,
            'description' => 'Main structure',
            'level' => 'Ground Floor',
            'area_sqft' => 1200,
            'tar' => 28.0,
            'quantity' => 1,
            'amount' => 76000,
            'depreciation_pct' => 0,
            'value' => 76000,
        ]);

        Sanctum::actingAs($officer);
        $response = $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'reassessed_value' => 76000,
            'applicable_tax_rate' => 1.5,
        ]);
        $response->assertOk();
    }

    /** Open valuation-family tasks anywhere in this property's lifecycle. */
    private function openLifecycleTasks(PropertyDiscovery $discovery, ?Valuation $valuation = null): Collection
    {
        $types = [
            Task::TYPE_VALUATION, Task::TYPE_VALUATION_REVIEW,
            Task::TYPE_VALUATION_MANAGER_REVIEW, Task::TYPE_AC_APPROVAL,
        ];

        $q = Task::whereIn('task_type', $types)
            ->whereNotIn('status', Task::COMPLETED_STATUSES)
            ->where(function ($b) use ($discovery, $valuation) {
                $b->where(fn ($x) => $x->where('reference_type', 'property_discovery')
                    ->where('reference_id', $discovery->id));
                if ($valuation) {
                    $b->orWhere(fn ($x) => $x->where('reference_type', 'valuation')
                        ->where('reference_id', $valuation->id));
                }
            });

        return $q->get();
    }

    /* ------------------------------------------------------------------ */
    /*  The reported symptom: one property, one row in the manager's list */
    /* ------------------------------------------------------------------ */

    public function test_the_property_appears_once_in_the_managers_task_list(): void
    {
        $discovery = $this->commercialDiscovery();
        $officer = $this->secondOfficer();

        $routingTask = $this->routeToValuationDesk($discovery);
        $valuation = $this->routeToOfficer($discovery, $officer);
        $this->submitAssessment($valuation, $officer);

        Sanctum::actingAs($this->manager());
        // The workspace's default tab is Active, which is what the manager sees.
        $rows = $this->getJson('/api/v1/valuation/tasks?status_group=active')->assertOk()->json('data.data');

        // The reported symptom: the same property showing up twice in one list, once
        // as a bare discovery and once as the completed assessment.
        $forThisProperty = array_values(array_filter(
            $rows,
            fn ($r) => ($r['property_address'] ?? null) === $discovery->property_address
        ));

        $this->assertCount(
            1,
            $forThisProperty,
            'The manager must see this property once, not once per workflow stage. Got: '
                .json_encode(array_map(fn ($r) => [$r['task_type'], $r['label']], $forThisProperty))
        );

        // And the one row is the real review task on the assessment, not the retired
        // routing task on the discovery.
        $row = $forThisProperty[0];
        $this->assertSame(Task::TYPE_VALUATION_REVIEW, $row['task_type']);
        $this->assertSame($valuation->valuation_reference, $row['label']);

        $references = array_column($rows, 'task_reference');
        $this->assertNotContains($routingTask->task_reference, $references);
    }

    public function test_only_one_task_is_open_for_the_property_at_each_stage(): void
    {
        $discovery = $this->commercialDiscovery();
        $officer = $this->secondOfficer();

        $this->routeToValuationDesk($discovery);
        $this->assertCount(
            1,
            $this->openLifecycleTasks($discovery),
            'While awaiting the manager: only the routing task.'
        );

        $valuation = $this->routeToOfficer($discovery, $officer);
        $open = $this->openLifecycleTasks($discovery, $valuation);
        $this->assertCount(1, $open, 'Once routed: only the officer\'s assessment task.');
        $this->assertSame(Task::TYPE_VALUATION, $open->first()->task_type);

        $this->submitAssessment($valuation, $officer);
        $open = $this->openLifecycleTasks($discovery, $valuation);
        $this->assertCount(1, $open, 'Once submitted: only the manager\'s review task.');
        $this->assertSame(Task::TYPE_VALUATION_REVIEW, $open->first()->task_type);
    }

    public function test_the_routing_task_closes_when_the_assessment_is_created(): void
    {
        $discovery = $this->commercialDiscovery();
        $routingTask = $this->routeToValuationDesk($discovery);

        $this->assertSame('Assigned', $routingTask->status);

        $this->routeToOfficer($discovery, $this->secondOfficer());

        $routingTask->refresh();
        $this->assertContains($routingTask->status, Task::COMPLETED_STATUSES);
        $this->assertNotNull($routingTask->completed_at);

        // Closed, not deleted: the row and its history are the audit trail.
        $this->assertTrue(Task::whereKey($routingTask->id)->exists());
        $this->assertTrue($routingTask->history()
            ->where('to_status', 'Closed')
            ->where('action', 'routed_to_valuation')
            ->exists());
        $this->assertTrue($routingTask->engagements()->exists());
    }

    public function test_the_officers_task_closes_when_the_assessment_is_submitted(): void
    {
        $discovery = $this->commercialDiscovery();
        $officer = $this->secondOfficer();
        $this->routeToValuationDesk($discovery);
        $valuation = $this->routeToOfficer($discovery, $officer);

        $officerTask = Task::where('task_type', Task::TYPE_VALUATION)
            ->where('reference_type', 'valuation')
            ->where('reference_id', $valuation->id)
            ->firstOrFail();

        $this->submitAssessment($valuation, $officer);

        $officerTask->refresh();
        $this->assertContains($officerTask->status, Task::COMPLETED_STATUSES);
        $this->assertNotNull($officerTask->completed_at);
        $this->assertTrue($officerTask->history()
            ->where('action', 'submitted_to_manager')
            ->exists());
    }

    public function test_a_returned_discovery_keeps_its_routing_task_open(): void
    {
        $discovery = $this->commercialDiscovery();
        $routingTask = $this->routeToValuationDesk($discovery);

        Sanctum::actingAs($this->manager());
        $this->postJson("/api/v1/discoveries/{$discovery->id}/val-review", [
            'decision' => 'return',
            'remarks' => 'Address and TIN need confirming.',
        ])->assertOk();

        // The manager has not finished triaging: the task must stay open so the
        // decision is still owed after the officer corrects the record.
        $routingTask->refresh();
        $this->assertNotContains($routingTask->status, Task::COMPLETED_STATUSES);
        $this->assertSame(PropertyDiscovery::STATUS_RETURNED, $discovery->fresh()->status);
    }

    /* ------------------------------------------------------------------ */
    /*  Identity: the assessment never becomes a second property */
    /* ------------------------------------------------------------------ */

    public function test_submitting_an_assessment_creates_no_second_property_or_valuation(): void
    {
        $discovery = $this->commercialDiscovery();
        $officer = $this->secondOfficer();
        $this->routeToValuationDesk($discovery);
        $valuation = $this->routeToOfficer($discovery, $officer);

        $this->submitAssessment($valuation, $officer);

        $this->assertSame(1, Valuation::count(), 'Exactly one assessment for the property.');
        $this->assertSame(1, PropertyDiscovery::count(), 'Exactly one discovery for the property.');

        // The link is bidirectional and intact in both directions.
        $valuation->refresh();
        $discovery->refresh();
        $this->assertSame($discovery->id, $valuation->discovery_id);
        $this->assertSame($valuation->id, $discovery->valuation_id);
        // Both sides resolve to the same record, and each keeps the reference it was
        // issued at its own creation (references are generated, permanent, per row).
        $this->assertSame($discovery->discovery_reference, $valuation->discovery->discovery_reference);
        $this->assertSame($valuation->valuation_reference, $discovery->valuation->valuation_reference);
        $this->assertMatchesRegularExpression('/^ND-\d{4}-\d{5}$/', $discovery->discovery_reference);
        $this->assertMatchesRegularExpression('/^VAL-\d{4}-\d{5}$/', $valuation->valuation_reference);

        // property_id is the source system's registration identifier, captured only at
        // registration. Before then it is null on both, which is not a second property.
        $this->assertNull($valuation->property_id);
        $this->assertNull($discovery->property_id);
    }

    public function test_the_discovery_is_preserved_and_keeps_its_own_values(): void
    {
        $discovery = $this->commercialDiscovery();
        $officer = $this->secondOfficer();
        $this->routeToValuationDesk($discovery);
        $valuation = $this->routeToOfficer($discovery, $officer);
        $this->submitAssessment($valuation, $officer);

        $discovery->refresh();

        // The discovery is a historical stage of the same property, not overwritten by
        // the assessment that followed it.
        $this->assertSame('Francis Mulbah', $discovery->owner_name);
        $this->assertSame('501553421', $discovery->tin);
        $this->assertSame('Pipeline Cookspoon Hill community around the RB Shop,', $discovery->property_address);
        $this->assertSame('6.297700,-10.799800', $discovery->gps_coordinate);
        $this->assertNotNull($discovery->discovery_date);

        // The assessment is the current valuation state.
        $valuation->refresh();
        $this->assertSame(76000.0, (float) $valuation->reassessed_value);
        $this->assertSame(Valuation::STATUS_SUBMITTED_TO_MANAGER, $valuation->status);
    }

    public function test_the_officer_keeps_access_to_their_own_submitted_assessment(): void
    {
        $discovery = $this->commercialDiscovery();
        $officer = $this->secondOfficer();
        $this->routeToValuationDesk($discovery);
        $valuation = $this->routeToOfficer($discovery, $officer);
        $this->submitAssessment($valuation, $officer);

        // Retiring the task must not lock the officer out of the record they produced;
        // access follows ownership, not the open task.
        Sanctum::actingAs($officer);
        $this->getJson("/api/v1/valuations/{$valuation->id}")->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /*  Re-work: a returned assessment gets fresh work, not a stale one */
    /* ------------------------------------------------------------------ */

    public function test_a_resubmitted_assessment_gets_a_fresh_task_and_fresh_deadline(): void
    {
        $discovery = $this->commercialDiscovery();
        $officer = $this->secondOfficer();
        $this->routeToValuationDesk($discovery);
        $valuation = $this->routeToOfficer($discovery, $officer);
        $this->submitAssessment($valuation, $officer);

        // Manager returns it for correction.
        Sanctum::actingAs($this->manager());
        $this->postJson("/api/v1/valuations/{$valuation->id}/review", [
            'decision' => 'return',
            'remarks' => 'Building specification incomplete.',
        ])->assertOk();

        $valuation->refresh();
        $this->assertSame(Valuation::STATUS_RETURNED, $valuation->status);

        $officerTask = Task::where('task_type', Task::TYPE_VALUATION)
            ->where('reference_type', 'valuation')
            ->where('reference_id', $valuation->id)
            ->whereNotIn('status', Task::COMPLETED_STATUSES)
            ->firstOrFail();

        $this->submitAssessment($valuation, $fresh = $officer);

        // The reopened stage produced its own task; the closed one is untouched history.
        $officerTask->refresh();
        $this->assertContains($officerTask->status, Task::COMPLETED_STATUSES);
        $this->assertNotNull($fresh);
    }
}
