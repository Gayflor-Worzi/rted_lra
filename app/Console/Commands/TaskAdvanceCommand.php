<?php

namespace App\Console\Commands;

use App\Services\EscalationService;
use App\Services\TaskWorkflowService;
use Illuminate\Console\Command;

/**
 * Auto-runs the date-gated workflow ladder over every bill-linked task and logs
 * the resulting engagement (reminder / demand / final enforcement / closure).
 * Schedule on cron; also callable by hand: php artisan tasks:advance.
 */
class TaskAdvanceCommand extends Command
{
    protected $signature = 'tasks:advance {--dry-run : Report eligibility without mutating}';

    protected $description = 'Auto-advance bill-linked tasks through the workflow ladder';

    public function handle(TaskWorkflowService $workflow, EscalationService $escalation): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $eligible = 0;

            $bills = \App\Models\PropertyBill::query()
                ->whereNotNull('delivery_date')
                ->whereNotIn('case_status', ['Under Verification', 'Resolved', 'Closed'])
                ->where('payment_status', '!=', 'Paid')
                ->get();

            foreach ($bills as $bill) {
                $result = $escalation->evaluate($bill);

                if ($result['eligible_action']) {
                    $eligible++;
                    $this->line("  [DRY-RUN] Bill {$bill->document_number}: {$result['eligible_action']} ({$result['reason']})");
                }
            }

            // A dry run also reports tasks that are merely awaiting their window.
            $awaiting = \App\Models\Task::query()
                ->where('reference_type', 'property_bill')
                ->whereNotIn('status', ['Resolved', 'Closed', 'Paid', 'Logged'])
                ->with('bill')
                ->get()
                ->filter(fn ($task) => $task->bill && ! $task->bill->delivery_date)
                ->count();

            $this->info("Dry-run complete. {$eligible} bill(s) eligible to advance; {$awaiting} awaiting delivery.");

            return self::SUCCESS;
        }

        $moved = $workflow->advanceDue();

        $this->info("Workflow pass complete — {$moved} task(s) advanced.");

        return self::SUCCESS;
    }
}