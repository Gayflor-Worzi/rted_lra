<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tables that hold a nullable task_id and must follow a merged task. */
    private const TASK_LINKED_TABLES = [
        'task_histories',
        'task_engagements',
        'enforcement_visits',
        'payment_verifications',
        'evidence_photos',
        'gps_locations',
        'gps_location_history',
    ];

    /**
     * Data-only cleanup. Repeated script/resubmit runs stacked multiple OPEN
     * tasks of the same type for the same reference (e.g. 3x Valuation Manager
     * Review on one discovery), and repeated events created exact-duplicate
     * notification rows. For each duplicate group the newest row wins and every
     * linked row (history, engagement, visits, evidence, GPS) follows it —
     * nothing is lost but the redundant rows. The app-layer guard in
     * TaskService::reuseOpenTask() prevents this from recurring.
     */
    public function up(): void
    {
        $this->dedupeOpenTasks();
        $this->dedupeNotifications();
    }

    public function down(): void
    {
        // Data cleanup — intentionally not reversible.
    }

    private function log(string $message): void
    {
        if (defined('STDOUT')) {
            fwrite(STDOUT, $message.PHP_EOL);
        }
    }

    private function dedupeOpenTasks(): void
    {
        if (! Schema::hasTable('tasks')) {
            return;
        }

        $groups = DB::table('tasks')
            ->whereNull('completed_at')
            ->selectRaw('reference_type, reference_id, task_type, MAX(id) as keep, COUNT(*) as c')
            ->groupBy('reference_type', 'reference_id', 'task_type')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $g) {
            $keep = (int) $g->keep;

            $dupeIds = DB::table('tasks')
                ->whereNull('completed_at')
                ->where('reference_type', $g->reference_type)
                ->where('reference_id', $g->reference_id)
                ->where('task_type', $g->task_type)
                ->where('id', '!=', $keep)
                ->orderByDesc('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (! $dupeIds) {
                continue;
            }

            foreach (self::TASK_LINKED_TABLES as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'task_id')) {
                    DB::table($table)->whereIn('task_id', $dupeIds)->update(['task_id' => $keep]);
                }
            }

            DB::table('tasks')->whereIn('id', $dupeIds)->delete();

            $this->log(sprintf(
                '[dedupe-open-tasks] Merged %d task(s) of type "%s" for %s/%s onto task id %d.',
                count($dupeIds),
                $g->task_type,
                $g->reference_type,
                $g->reference_id,
                $keep
            ));
        }
    }

    private function dedupeNotifications(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $groups = DB::table('notifications')
            ->selectRaw('user_id, title, message, type, MAX(id) as keep, COUNT(*) as c')
            ->groupBy('user_id', 'title', 'message', 'type')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $g) {
            DB::table('notifications')
                ->where('id', '!=', $g->keep)
                ->where('user_id', $g->user_id)
                ->where('title', $g->title)
                ->where('message', $g->message)
                ->where('type', $g->type)
                ->delete();

            $this->log(sprintf(
                '[dedupe-notifications] Kept notification id %d, removed %d exact duplicate(s).',
                $g->keep,
                (int) ($g->c - 1)
            ));
        }
    }
};
