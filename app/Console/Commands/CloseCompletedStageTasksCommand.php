<?php

namespace App\Console\Commands;

use App\Models\PropertyDiscovery;
use App\Models\Task;
use App\Models\Valuation;
use App\Services\TaskService;
use Illuminate\Console\Command;

/**
 * Close workflow tasks whose stage the record has already completed.
 *
 * A stage's task has to close when the work it asked for is done. While that was
 * missing, one property carried two open tasks at once — the finished stage and the
 * next one — so a single property lifecycle showed up as several pieces of work. The
 * Valuation Manager list then carried the same property twice: once as the discovery,
 * once as the assessment it had produced.
 *
 * The engine closes these as each transition happens now. This command exists only for
 * the rows created before that, where the stage finished while the task stayed open.
 *
 * Safety: it reports by default and only writes with --execute. Nothing is deleted —
 * the task keeps its row, gains a Closed history entry and a completion engagement,
 * so the stage stays auditable.
 */
class CloseCompletedStageTasksCommand extends Command
{
    protected $signature = 'lra:close-completed-stage-tasks
                            {--execute : Apply the closures. Without this flag nothing is written.}
                            {--task= : Limit to a single task reference, e.g. TASK-2026-00007}';

    protected $description = 'Close valuation/A&R workflow tasks whose stage the record has already completed (report-only unless --execute)';

    /**
     * For each task type: the record statuses that mean the work it asked for is done.
     *
     * A task is only ever closed when its record has demonstrably moved past the point
     * the task was raised at, so a task that is genuinely still owed is never touched.
     */
    private const STAGE_COMPLETE_WHEN = [
        Task::TYPE_VALUATION => [
            Valuation::STATUS_SUBMITTED_TO_MANAGER, Valuation::STATUS_UNDER_MANAGER_REVIEW,
            Valuation::STATUS_SUBMITTED_TO_AC, Valuation::STATUS_AWAITING_AC_APPROVAL,
            Valuation::STATUS_AC_APPROVED, Valuation::STATUS_AC_RETURNED, Valuation::STATUS_AC_REJECTED,
            Valuation::STATUS_SENT_TO_ACCT, Valuation::STATUS_REGISTERED, Valuation::STATUS_COMPLETED,
        ],
        Task::TYPE_VALUATION_REVIEW => [
            Valuation::STATUS_UNDER_MANAGER_REVIEW, Valuation::STATUS_SUBMITTED_TO_AC,
            Valuation::STATUS_AWAITING_AC_APPROVAL, Valuation::STATUS_AC_APPROVED,
            Valuation::STATUS_AC_RETURNED, Valuation::STATUS_AC_REJECTED, Valuation::STATUS_SENT_TO_ACCT,
            Valuation::STATUS_REGISTERED, Valuation::STATUS_COMPLETED, Valuation::STATUS_RETURNED,
        ],
        Task::TYPE_VALUATION_MANAGER_REVIEW => [PropertyDiscovery::STATUS_UNDER_VALUATION],
        // The AC's stage ends at the three outcomes decide() can record, not later on.
        // Listing only the statuses past Account & Record left an approved property
        // showing the Assistant Commissioner as still owing a decision they had made.
        Task::TYPE_AC_APPROVAL => [
            Valuation::STATUS_AC_APPROVED, Valuation::STATUS_AC_RETURNED, Valuation::STATUS_AC_REJECTED,
            Valuation::STATUS_SENT_TO_ACCT, Valuation::STATUS_REGISTERED, Valuation::STATUS_COMPLETED,
        ],
        // The A&R Manager's own review stage, on either arrival route. A discovery leaves
        // it once assigned for processing, referred on, returned or rejected; a valuation
        // leaves it once sent to Account & Record or referred back. 'Awaiting Account
        // Manager Review' and 'AC Approved' are deliberately absent — those are the states
        // where the review is still owed, so the task has to stay open.
        Task::TYPE_DISCOVERY_REVIEW => [
            PropertyDiscovery::STATUS_ASSIGNED_ACCT, PropertyDiscovery::STATUS_REFERRED_VALUATION,
            PropertyDiscovery::STATUS_RETURNED, PropertyDiscovery::STATUS_REJECTED,
            PropertyDiscovery::STATUS_REGISTERED, PropertyDiscovery::STATUS_COMPLETED,
            PropertyDiscovery::STATUS_CLOSED,
        ],
        Task::TYPE_ACCOUNT_MANAGER_REVIEW => [
            Valuation::STATUS_SENT_TO_ACCT, Valuation::STATUS_RETURNED,
            Valuation::STATUS_REGISTERED, Valuation::STATUS_COMPLETED,
        ],
    ];

    public function __construct(private readonly TaskService $tasks)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $only = $this->option('task');

        $candidates = Task::whereIn('task_type', array_keys(self::STAGE_COMPLETE_WHEN))
            ->whereNotIn('status', Task::COMPLETED_STATUSES)
            ->when($only, fn ($q) => $q->where('task_reference', $only))
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($candidates as $task) {
            $record = $this->recordFor($task);
            $status = $record?->status;

            if (! $status || ! in_array($status, self::STAGE_COMPLETE_WHEN[$task->task_type], true)) {
                continue;
            }

            $rows[] = [$task, $record, $status];
        }

        if ($rows === []) {
            $this->info($only
                ? "No open task matching {$only} has a completed stage. Nothing to do."
                : 'No open valuation/A&R task has a completed stage. Nothing to do.');

            return self::SUCCESS;
        }

        $this->table(
            ['Task', 'Type', 'Anchored on', 'Record', 'Record status', 'Task status', 'Assignee', 'Action'],
            array_map(fn ($r) => [
                $r[0]->task_reference,
                $r[0]->task_type,
                $r[0]->reference_type,
                $this->labelFor($r[1]),
                $r[2],
                $r[0]->status,
                $r[0]->assignedTo?->full_name ?? $r[0]->assigned_to,
                $execute ? 'close' : 'would close',
            ], $rows)
        );

        $this->newLine();
        $this->line(sprintf(
            '%d task(s) %s a stage that is already complete.',
            count($rows),
            $execute ? 'have' : 'would get'
        ));

        if (! $execute) {
            $this->comment('Report only — nothing was written. Re-run with --execute to apply.');

            return self::SUCCESS;
        }

        $closed = 0;
        foreach ($rows as [$task, , $status]) {
            $this->tasks->closeStageTasks(
                $task->task_type,
                $task->reference_type,
                $task->reference_id,
                'stage_reconciled',
                null,
                "Reconciled: {$this->labelFor($this->recordFor($task))} is already at \"{$status}\", so this stage is complete."
            );
            $closed++;
            $this->line("  closed {$task->task_reference}");
        }

        $this->info("Closed {$closed} task(s). Rows, history and engagements retained.");

        return self::SUCCESS;
    }

    private function recordFor(Task $task): Valuation|PropertyDiscovery|null
    {
        return $task->reference_type === 'valuation'
            ? Valuation::find($task->reference_id)
            : PropertyDiscovery::find($task->reference_id);
    }

    private function labelFor(Valuation|PropertyDiscovery|null $record): string
    {
        return $record?->valuation_reference ?? $record?->discovery_reference ?? '(record missing)';
    }
}
