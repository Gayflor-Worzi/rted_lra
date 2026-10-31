<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One open task per workflow step.
 *
 * A task identifies one piece of work: (task_type, reference_type, reference_id).
 * When that work is continued — routed to another officer, returned for
 * correction, resubmitted, approved — the SAME task row is reused and its
 * status/history move forward. It is not a new task.
 *
 * TaskService::reuseOpenTask() enforces that in the application, but two
 * concurrent requests can both look, both find nothing, and both insert. The
 * cost is exactly the defect this phase corrects: one property appears twice in
 * a desk queue, and closing "the" task leaves its twin open and overdue.
 *
 * This adds the same style of database backstop already used for property
 * bills: a stored, generated open_task_key that is non-NULL only for OPEN tasks
 * with a concrete reference, so closed and unreferenced tasks are unaffected and
 * history is never constrained.
 */
return new class extends Migration
{
    private const TABLE = 'tasks';

    private const KEY_COLUMN = 'open_task_key';

    private const INDEX_NAME = 'tasks_open_task_key_unique';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->closePreExistingDuplicates();
        $this->addBackstop();
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropUnique(self::INDEX_NAME);
        });

        if (Schema::hasColumn(self::TABLE, self::KEY_COLUMN)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropColumn(self::KEY_COLUMN);
            });
        }
    }

    private function log(string $message): void
    {
        if (defined('STDOUT')) {
            fwrite(STDOUT, '[one-open-task] '.$message.PHP_EOL);
        }
    }

    /**
     * A database that already carries duplicate open tasks cannot take the
     * unique index. Rather than delete history, the newest row keeps the work
     * and the older duplicates are CLOSED with a history entry that names the
     * surviving task: nothing is removed, every identifier still resolves, and
     * the duplicate stops competing in active/overdue queues.
     */
    private function closePreExistingDuplicates(): void
    {
        $groups = DB::table(self::TABLE)
            ->whereNull('completed_at')
            ->whereNotNull('reference_type')
            ->whereNotNull('reference_id')
            ->selectRaw('task_type, reference_type, reference_id, MAX(id) as keep, COUNT(*) as c')
            ->groupBy('task_type', 'reference_type', 'reference_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $keep = (int) $group->keep;

            $dupeIds = DB::table(self::TABLE)
                ->whereNull('completed_at')
                ->where('task_type', $group->task_type)
                ->where('reference_type', $group->reference_type)
                ->where('reference_id', $group->reference_id)
                ->where('id', '!=', $keep)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (! $dupeIds) {
                continue;
            }

            $keptReference = DB::table(self::TABLE)->where('id', $keep)->value('task_reference');

            foreach ($dupeIds as $dupeId) {
                $fromStatus = DB::table(self::TABLE)->where('id', $dupeId)->value('status');

                DB::table(self::TABLE)->where('id', $dupeId)->update([
                    'status' => 'Closed',
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('task_histories')->insert([
                    'task_id' => $dupeId,
                    'from_status' => $fromStatus,
                    'to_status' => 'Closed',
                    'action' => 'duplicate_work_merged',
                    'performed_by' => null,
                    'remarks' => "Duplicate open task for the same workflow step; work continues on {$keptReference}.",
                    'created_at' => now(),
                ]);
            }

            $this->log(sprintf(
                'Closed %d duplicate open %s task(s) for %s/%s; work continues on task %s.',
                count($dupeIds),
                $group->task_type,
                $group->reference_type,
                $group->reference_id,
                $keptReference
            ));
        }
    }

    private function addBackstop(): void
    {
        if (Schema::hasColumn(self::TABLE, self::KEY_COLUMN)) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        $type = "TRIM(COALESCE(task_type, ''))";
        $refType = "TRIM(COALESCE(reference_type, ''))";
        $refId = 'reference_id';

        $expr = match ($driver) {
            'sqlite' => "UPPER(LENGTH($type) || '|' || $type || '|' || LENGTH($refType) || '|' || $refType || '|' || $refId)",
            default => "UPPER(CONCAT_WS('|', LENGTH($type), $type, LENGTH($refType), $refType, $refId))",
        };

        $case = "CASE WHEN completed_at IS NULL AND reference_id IS NOT NULL AND $refType <> '' THEN $expr ELSE NULL END";

        DB::statement(match ($driver) {
            'sqlite' => 'ALTER TABLE '.self::TABLE.' ADD COLUMN '.self::KEY_COLUMN." TEXT GENERATED ALWAYS AS ($case) STORED",
            default => 'ALTER TABLE '.self::TABLE.' ADD COLUMN '.self::KEY_COLUMN." VARCHAR(255) GENERATED ALWAYS AS ($case) STORED",
        });

        DB::statement('CREATE UNIQUE INDEX '.self::INDEX_NAME.' ON '.self::TABLE.' ('.self::KEY_COLUMN.')');
    }
};
