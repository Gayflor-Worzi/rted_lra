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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Valuation Officer and Valuation Manager dashboards — counters, drill-downs, tabs.
 *
 * The rule under test: a counter is the size of a clearly defined population, clicking it
 * opens exactly that population, and every valuation sits in exactly one tab according to
 * its current status.
 *
 * These were three separate defects, all from the same cause — the stage each status
 * belongs to was written out again in every consumer, and the copies disagreed:
 *
 *  - the register's tabs disagreed with the query each tab ran, so valuations in
 *    `Registered`, `AC Returned` and `AC Rejected` were in no tab at all;
 *  - a deep link to a returned valuation opened the Completed tab;
 *  - the manager counted `Sent to Account & Record` and `Registered` as Completed while
 *    the officer counted only `Completed`, so the same record was open on one dashboard
 *    and finished on the other;
 *  - the officer's Due Today counter drilled to a bucket that did not exist, so it
 *    opened the whole register.
 */
class ValuationDashboardAccuracyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function officer(string $email = 'val.officer@test.lra'): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function manager(): User
    {
        return User::where('email', 'val.manager@test.lra')->firstOrFail();
    }

    private function secondOfficer(): User
    {
        return User::create([
            'staff_id' => 'VALOFF9',
            'full_name' => 'Second Valuation Officer',
            'email' => 'val.officer9@test.lra',
            'password' => bcrypt('password'),
            'role_id' => Role::where('name', 'Valuation Officer')->value('id'),
            'section_id' => Section::where('code', 'VAL')->value('id'),
            'is_active' => true,
            'must_reset_password' => false,
        ]);
    }

    /**
     * One valuation per status, so every stage has something in it.
     */
    private function seedEveryStatus(User $officer): void
    {
        foreach (Valuation::STATUSES as $i => $status) {
            Valuation::create([
                'valuation_type' => 'reassessment',
                'property_id' => 'P-ACC-'.($i + 1),
                'document_number' => 'DOC-ACC-'.($i + 1),
                'owner_name' => 'Owner '.($i + 1),
                'owner_contact' => '0770-000-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'tin' => 'TIN-ACC-'.($i + 1),
                'property_address' => 'Accuracy Way '.$i,
                'property_classification' => 'Residential',
                'gps_coordinate' => '6.315611, -10.807407',
                'assessment_date' => now()->toDateString(),
                'status' => $status,
                'photos' => 'proof://ACC-'.$i,
                'reassessed_value' => 10000 * ($i + 1),
                'applicable_tax_rate' => 1.5,
                'valuation_officer_id' => $officer->id,
            ]);
        }
    }

    private function officerWorkload(): array
    {
        return $this->getJson('/api/v1/dashboard/my')->assertOk()->json('data.valuation_work');
    }

    private function managerKpi(string $key): array
    {
        $kpis = collect($this->getJson('/api/v1/dashboard/manager/valuation')->assertOk()->json('data.kpis'));

        return (array) $kpis->firstWhere('key', $key);
    }

    private function registerRows(string $tab): array
    {
        return $this->getJson('/api/v1/valuations?status='.rawurlencode($tab).'&per_page=100')
            ->assertOk()->json('data.data');
    }

    /* ------------------------------------------------------------------ */
    /*  One authoritative interpretation */
    /* ------------------------------------------------------------------ */

    public function test_every_status_belongs_to_exactly_one_stage(): void
    {
        $assigned = [];

        foreach (Valuation::STAGE_TABS as $stage => $statuses) {
            foreach ($statuses as $status) {
                $this->assertArrayNotHasKey(
                    $status,
                    $assigned,
                    sprintf('Status "%s" appears in both "%s" and "%s".', $status, $assigned[$status] ?? '', $stage)
                );
                $assigned[$status] = $stage;
            }
        }

        // Exhaustive as well as exclusive: a status in no stage would belong to no tab and
        // so be invisible in the register.
        $this->assertEqualsCanonicalizing(
            Valuation::STATUSES,
            array_keys($assigned),
            'Every known status must resolve to a stage.'
        );

        // A single status resolves to the stage that holds it.
        $this->assertSame('Completed', Valuation::stageFor(Valuation::STATUS_COMPLETED));
        $this->assertSame(
            'Awaiting Account & Record',
            Valuation::stageFor(Valuation::STATUS_REGISTERED),
            'Registered is still outstanding work, not a finished assessment.'
        );
        $this->assertSame(
            'Returned for Correction',
            Valuation::stageFor(Valuation::STATUS_AC_REJECTED),
            'A rejected assessment is a returned assessment.'
        );
        $this->assertNull(Valuation::stageFor('Nonsense'));
    }

    /* ------------------------------------------------------------------ */
    /*  Tab placement: every valuation is reachable, exactly once */
    /* ------------------------------------------------------------------ */

    public function test_every_status_is_reachable_through_exactly_one_register_tab(): void
    {
        $officer = $this->officer();
        $this->seedEveryStatus($officer);

        Sanctum::actingAs($officer);

        $seen = [];
        foreach (array_keys(Valuation::STAGE_TABS) as $tab) {
            foreach ($this->registerRows($tab) as $row) {
                $this->assertArrayNotHasKey(
                    $row['id'],
                    $seen,
                    sprintf(
                        'Valuation %s appears in both "%s" and "%s".',
                        $row['valuation_reference'],
                        $seen[$row['id']] ?? '',
                        $tab
                    )
                );
                $seen[$row['id']] = $tab;
            }
        }

        $all = $this->getJson('/api/v1/valuations?per_page=100')->assertOk()->json('data.data');

        $missing = array_diff(array_column($all, 'id'), array_keys($seen));
        $this->assertSame(
            [],
            array_values($missing),
            'Every valuation must appear in some tab; these appear in none.'
        );
        $this->assertCount(count($all), $seen);

        // The three that were previously in no tab at all.
        foreach ([Valuation::STATUS_REGISTERED, Valuation::STATUS_AC_RETURNED, Valuation::STATUS_AC_REJECTED] as $status) {
            $this->assertContains(
                $status,
                array_column($all, 'status'),
                "A '{$status}' valuation must be retrievable."
            );
        }
    }

    public function test_a_deep_link_to_a_returned_valuation_opens_the_returned_tab(): void
    {
        $officer = $this->officer();
        $this->seedEveryStatus($officer);

        Sanctum::actingAs($officer);

        // The register resolves the tab from the status; these are the two that used to
        // fall through to Completed.
        $rows = $this->registerRows('Returned for Correction');
        $statuses = array_column($rows, 'status');

        $this->assertContains(Valuation::STATUS_RETURNED, $statuses);
        $this->assertContains(Valuation::STATUS_AC_RETURNED, $statuses);
        $this->assertContains(Valuation::STATUS_AC_REJECTED, $statuses);
        $this->assertNotContains(Valuation::STATUS_COMPLETED, $statuses);
    }

    /* ------------------------------------------------------------------ */
    /*  Counter ⇄ drill-down: same population */
    /* ------------------------------------------------------------------ */

    public function test_each_officer_counter_equals_the_records_its_drill_down_opens(): void
    {
        $officer = $this->officer();
        $this->seedEveryStatus($officer);

        Sanctum::actingAs($officer);
        $work = $this->officerWorkload();

        $counterToTab = [
            'my_assigned' => 'Assigned',
            'in_progress' => 'Assessment in Progress',
            'returned' => 'Returned for Correction',
            'submitted' => 'Awaiting Review',
            'completed' => 'Completed',
        ];

        foreach ($counterToTab as $counter => $tab) {
            $this->assertSame(
                $work['kpis'][$counter],
                count($this->registerRows($tab)),
                "Counter '{$counter}' does not match the '{$tab}' tab it opens."
            );
        }

        // Due Today has no workflow stage; it must still open exactly the records it
        // counted, and no others. It previously opened the entire register.
        $dueToday = collect($work['records'])->where('due_today', true)->count();
        $this->assertSame($work['kpis']['due_today'], $dueToday);
    }

    public function test_completed_counts_only_terminal_valuations(): void
    {
        $officer = $this->officer();
        $this->seedEveryStatus($officer);

        Sanctum::actingAs($officer);
        $work = $this->officerWorkload();

        $this->assertSame(1, $work['kpis']['completed']);

        // A registered valuation is outstanding work, so it is not Completed...
        $this->assertSame(0, collect($work['records'])
            ->where('status', Valuation::STATUS_REGISTERED)
            ->where('stage', 'Completed')
            ->count());

        // ...and it is still open, sitting in Account & Record. That stage holds three
        // distinct statuses, all of them outstanding: AC Approved, Sent to Account &
        // Record and Registered.
        $this->assertSame(3, $work['by_stage']['Awaiting Account & Record']);
    }

    public function test_a_valuation_appears_in_exactly_one_stage_and_the_counts_add_up(): void
    {
        $officer = $this->officer();
        $this->seedEveryStatus($officer);

        Sanctum::actingAs($officer);
        $work = $this->officerWorkload();

        $stageTotal = array_sum($work['by_stage']);
        $this->assertSame($work['total'], $stageTotal, 'Stages must partition the workload with nothing double counted.');

        $statusTotal = array_sum($work['by_status']);
        $this->assertSame($work['total'], $statusTotal);
    }

    public function test_due_today_ignores_a_finished_valuation_whose_task_is_still_open(): void
    {
        $officer = $this->officer();

        $valuation = Valuation::create([
            'valuation_type' => 'reassessment',
            'property_id' => 'P-DUE-1',
            'owner_name' => 'Done But Dated',
            'owner_contact' => '0770-000-900',
            'tin' => 'TIN-DUE',
            'property_address' => 'Done Way',
            'property_classification' => 'Residential',
            'gps_coordinate' => '6.315611, -10.807407',
            'status' => Valuation::STATUS_COMPLETED,
            'photos' => 'proof://DUE-1',
            'valuation_officer_id' => $officer->id,
        ]);

        // Completing a valuation closes the valuation but not its task, so a finished
        // assessment can still carry today's date on an open task.
        Task::create([
            'task_type' => Task::TYPE_VALUATION,
            'reference_type' => 'valuation',
            'reference_id' => $valuation->id,
            'title' => 'Valuation completed but task still open',
            'assigned_to' => $officer->id,
            'status' => 'Assigned',
            'due_date' => now()->toDateString(),
        ]);

        Sanctum::actingAs($officer);
        $work = $this->officerWorkload();

        $this->assertSame(0, $work['kpis']['due_today'], 'A completed valuation is not due today.');
        $this->assertTrue($work['records'][0]['due_today'], 'The underlying deadline flag is still reported for the row.');
        $this->assertTrue($work['records'][0]['terminal']);
    }

    public function test_due_today_counts_an_active_valuation_due_today(): void
    {
        $officer = $this->officer();

        $valuation = Valuation::create([
            'valuation_type' => 'reassessment',
            'property_id' => 'P-DUE-2',
            'owner_name' => 'Open And Dated',
            'owner_contact' => '0770-000-901',
            'tin' => 'TIN-DUE2',
            'property_address' => 'Open Way',
            'property_classification' => 'Residential',
            'gps_coordinate' => '6.315611, -10.807407',
            'status' => Valuation::STATUS_ASSESSMENT_IN_PROGRESS,
            'photos' => 'proof://DUE-2',
            'reassessed_value' => 50000,
            'applicable_tax_rate' => 1.5,
            'valuation_officer_id' => $officer->id,
        ]);

        Task::create([
            'task_type' => Task::TYPE_VALUATION,
            'reference_type' => 'valuation',
            'reference_id' => $valuation->id,
            'title' => 'Open valuation due today',
            'assigned_to' => $officer->id,
            'status' => 'Assigned',
            'due_date' => now()->toDateString(),
        ]);

        Sanctum::actingAs($officer);

        $this->assertSame(1, $this->officerWorkload()['kpis']['due_today']);
    }

    /* ------------------------------------------------------------------ */
    /*  Scope */
    /* ------------------------------------------------------------------ */

    public function test_the_officer_dashboard_shows_only_that_officers_own_work(): void
    {
        $officer = $this->officer();
        $other = $this->secondOfficer();

        $mine = Valuation::create([
            'valuation_type' => 'reassessment',
            'property_id' => 'P-SCOPE-1',
            'owner_name' => 'Mine',
            'owner_contact' => '0770-000-910',
            'tin' => 'TIN-S1',
            'property_address' => 'Mine Way',
            'property_classification' => 'Residential',
            'gps_coordinate' => '6.315611, -10.807407',
            'status' => Valuation::STATUS_ASSIGNED,
            'photos' => 'proof://S1',
            'valuation_officer_id' => $officer->id,
        ]);

        Valuation::create([
            'valuation_type' => 'reassessment',
            'property_id' => 'P-SCOPE-2',
            'owner_name' => 'Theirs',
            'owner_contact' => '0770-000-911',
            'tin' => 'TIN-S2',
            'property_address' => 'Theirs Way',
            'property_classification' => 'Residential',
            'gps_coordinate' => '6.315611, -10.807407',
            'status' => Valuation::STATUS_ASSIGNED,
            'photos' => 'proof://S2',
            'valuation_officer_id' => $other->id,
        ]);

        Sanctum::actingAs($officer);
        $work = $this->officerWorkload();

        $this->assertSame(1, $work['total'], 'The other officer\'s valuation must not be counted.');
        $this->assertSame(1, $work['kpis']['my_assigned']);
        $this->assertSame([$mine->id], array_column($work['records'], 'id'));

        $rows = $this->registerRows('Assigned');
        $this->assertSame([$mine->id], array_column($rows, 'id'));
    }

    /* ------------------------------------------------------------------ */
    /*  Manager dashboard */
    /* ------------------------------------------------------------------ */

    public function test_the_manager_counts_completed_as_terminal_only(): void
    {
        $officer = $this->officer();
        $this->seedEveryStatus($officer);

        Sanctum::actingAs($this->manager());

        // The manager previously counted Sent to Account & Record and Registered as
        // completed, which put three unfinished valuations in a finished total.
        $this->assertSame(1, $this->managerKpi('completed')['value']);
    }

    public function test_the_manager_returned_counter_covers_every_returned_status(): void
    {
        $officer = $this->officer();
        $this->seedEveryStatus($officer);

        Sanctum::actingAs($this->manager());

        // Returned for Correction, AC Returned and AC Rejected.
        $this->assertSame(3, $this->managerKpi('returned')['value']);
    }

    public function test_each_manager_counter_opens_the_register_filtered_to_its_own_stage(): void
    {
        $officer = $this->officer();
        $this->seedEveryStatus($officer);

        Sanctum::actingAs($this->manager());

        $expected = [
            'assigned_valuations' => 'Assigned',
            'in_progress' => 'Assessment in Progress',
            'manager_review' => 'Awaiting Review',
            'returned' => 'Returned for Correction',
            'awaiting_ac_approval' => 'Awaiting AC',
            'completed' => 'Completed',
        ];

        foreach ($expected as $key => $stage) {
            $kpi = $this->managerKpi($key);

            $this->assertSame(
                '/valuations?status='.rawurlencode($stage),
                $kpi['link'] ?? null,
                "The '{$key}' card must open the '{$stage}' tab."
            );

            $this->assertSame(
                $kpi['value'],
                count($this->registerRows($stage)),
                "The '{$key}' card shows {$kpi['value']} but its tab holds a different number of records."
            );
        }
    }

    public function test_a_manager_alert_with_no_matching_filter_is_not_link(): void
    {
        $officer = $this->officer();
        $this->seedEveryStatus($officer);

        Sanctum::actingAs($this->manager());
        $alerts = collect($this->getJson('/api/v1/dashboard/manager/valuation')->assertOk()->json('data.alerts'));

        $review = $alerts->firstWhere('label', 'Valuations awaiting Manager review');
        $this->assertSame('/valuations?status='.rawurlencode('Awaiting Review'), $review['link']);

        // Overdue and missing photos are task/quality figures with no stage to filter on.
        // They are shown, but pointing them at an unfiltered register would display
        // records the number does not describe.
        foreach (['Overdue valuations', 'Missing photos'] as $label) {
            $this->assertArrayNotHasKey('link', $alerts->firstWhere('label', $label), $label);
        }
    }

    public function test_the_manager_photo_completeness_credits_discovery_photographs(): void
    {
        $officer = $this->officer();

        $discovery = PropertyDiscovery::create([
            'discovered_by' => $officer->id,
            'owner_name' => 'Photographed On Site',
            'owner_contact' => '0770-000-920',
            'tin' => 'TIN-P1',
            'property_address' => 'Photo Way',
            'property_classification' => 'Residential',
            'property_type' => 'House',
            'county' => 'Montserrado',
            'gps_lat' => '6.315611',
            'gps_lng' => '-10.807407',
            'status' => PropertyDiscovery::STATUS_APPROVED,
        ]);

        PropertyDiscoveryEvidence::create([
            'discovery_id' => $discovery->id,
            'evidence_type' => PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO,
            'sequence_no' => 1,
            'original_name' => 'front.jpg',
            'file_path' => 'discovery-evidence/'.$discovery->discovery_reference.'/front.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 10,
            'uploaded_by' => $officer->id,
        ]);

        Valuation::create([
            'valuation_type' => 'reassessment',
            'property_id' => 'P-PHOTO-1',
            'owner_name' => 'Photographed On Site',
            'owner_contact' => '0770-000-920',
            'tin' => 'TIN-P1',
            'property_address' => 'Photo Way',
            'property_classification' => 'Residential',
            'gps_coordinate' => '6.315611, -10.807407',
            'status' => Valuation::STATUS_ASSIGNED,
            'discovery_id' => $discovery->id,
            'valuation_officer_id' => $officer->id,
        ]);

        Sanctum::actingAs($this->manager());
        $data = $this->getJson('/api/v1/dashboard/manager/valuation')->assertOk()->json('data');

        $this->assertSame(1, $this->kpiFrom($data['gps_evidence'], 'Photos Complete'));
        $this->assertSame(0, $this->kpiFrom($data['gps_evidence'], 'Photos Missing'));
    }

    private function kpiFrom(array $rows, string $label): int
    {
        return (int) collect($rows)->firstWhere('label', $label)['count'];
    }
}
