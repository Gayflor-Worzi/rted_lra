<?php

namespace App\Console\Commands;

use App\Models\DataQualityFlag;
use App\Models\Task;
use App\Services\SlaDeadlineService;
use App\Services\TaskService;
use App\Support\BusinessCalendar;
use App\Support\BusinessClock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * §42.8 — Backfill any active task missing both `due_date` and `due_at` with
 * the correct SLA-based deadline so the overdue/due-today engine never skips it.
 *
 * Produces a DataQualityFlag for each affected bill so the M&E register is
 * transparent about the automated remediation.
 */
class NormalizeTaskDeadlinesCommand extends Command
{
    protected $signature = 'lra:normalize-task-deadlines {--dry-run}';

    protected $description = 'Backfill missing task deadlines from SLA config and raise data-quality flags';

    public function __construct(private readonly SlaDeadlineService $sla)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $tasks = Task::whereNull('due_date')
            ->whereNull('due_at')
            ->whereNotIn('status', Task::COMPLETED_STATUSES)
            ->get();

        if ($tasks->isEmpty()) {
            $this->info('No tasks missing both deadlines. Nothing to do.');

            return self::SUCCESS;
        }

        $backfilled = 0;
        $flagsCreated = 0;

        DB::transaction(function () use ($tasks, $dryRun, &$backfilled, &$flagsCreated) {
            foreach ($tasks as $task) {
                [$days, $code] = $this->slaFor($task);
                $start = BusinessClock::now();
                $dueDate = $this->sla->calculateDeadline($start, $days, 'day', BusinessCalendar::CALENDAR_DAYS);

                $updates = [
                    'due_date' => $dueDate->toDateString(),
                    'deadline_type' => BusinessClock::DEADLINE_DATE,
                    'sla_code' => $code,
                    'sla_version' => '1',
                    'sla_unit' => 'day',
                    'sla_value' => $days,
                    'sla_calendar_type' => BusinessCalendar::CALENDAR_DAYS,
                    'sla_started_at' => $start,
                    'sla_due_at' => $dueDate,
                ];

                if (! $dryRun) {
                    $task->update($updates);
                }

                $backfilled++;

                // §42.8 transparency flag — only when a bill is linked.
                if ($task->reference_type === 'property_bill' && $task->reference_id) {
                    $existingFlag = DataQualityFlag::where('bill_id', $task->reference_id)
                        ->where('status', '!=', DataQualityFlag::STATUSES[2])
                        ->where('issue', 'like', '%no deadline%')
                        ->exists();

                    if (! $existingFlag) {
                        if (! $dryRun) {
                            DataQualityFlag::create([
                                'bill_id' => $task->reference_id,
                                'issue' => "Task {$task->task_reference} had no deadline; auto-assigned due {$dueDate->toDateString()} via {$code} v1.",
                                'severity' => 'Moderate',
                                'status' => 'Open',
                            ]);
                        }
                        $flagsCreated++;
                    }
                }
            }
        });

        $mode = $dryRun ? ' (dry run)' : '';
        $this->info("Normalize task deadlines{$mode}: {$backfilled} task(s) backfilled, {$flagsCreated} data-quality flag(s) raised.");

        return self::SUCCESS;
    }

    private function slaFor(Task $task): array
    {
        return match ($task->task_type) {
            Task::TYPE_BILL_DELIVERY => [14, TaskService::SLA_BILL_DELIVERY],
            Task::TYPE_PAYMENT_FOLLOWUP, Task::TYPE_ENFORCEMENT_VISIT => [14, TaskService::SLA_BILL_DELIVERY],
            Task::TYPE_VALUATION_MANAGER_REVIEW => [3, TaskService::SLA_MANAGER_REVIEW],
            default => [7, TaskService::SLA_PROCESSING],
        };
    }
}
