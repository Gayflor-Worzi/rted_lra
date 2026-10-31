<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'property_bills';

    private const KEY_COLUMN = 'assessment_key';

    private const INDEX_NAME = 'property_bills_assessment_unique';

    /** Dependent tables that reference a bill and must follow a merge. */
    private const DEPENDENT_TABLES = [
        'enforcement_visits',
        'payment_verifications',
        'payments',
        'valuations',
        'me_queries',
        'appeals',
        'valuation_property_descriptions',
        'evidence_photos',
        'property_discoveries',
        'task_engagements',
        'bill_amendments',
        'receipt_fingerprints',
    ];

    /**
     * One assessment may be logged only once: the normalized Property ID + TIN
     * + Tax Period triple is unique while the document is live. This cleans up
     * duplicate log rows that exist in the seeded/test data (earliest log wins,
     * dependents follow the survivor, duplicates are soft-deleted) and then adds
     * a database-level backstop. The API layer is the primary guard and returns
     * the friendly 422 + Amend hint; the index just makes concurrent
     * double-logging impossible.
     *
     * The backstop is NOT a plain 3-column unique — soft-deleted duplicates
     * (and NULLs) would break it. Instead a stored, generated assessment_key is
     * computed only for live rows with real IDs and TINs, so past dedup rows
     * stay harmless.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->mergeDuplicateAssessments();
        $this->addUniqueBackstop();
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropUnique(self::INDEX_NAME);

            if (Schema::hasColumn(self::TABLE, self::KEY_COLUMN)) {
                $table->dropColumn(self::KEY_COLUMN);
            }
        });
    }

    private function normalized(string $column): string
    {
        return "UPPER(TRIM(COALESCE($column, '')))";
    }

    private function mergeDuplicateAssessments(): void
    {
        $p = $this->normalized('property_id');
        $t = $this->normalized('tin');
        $per = $this->normalized('tax_period');

        $groups = DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->selectRaw("$p as p, $t as t, $per as per, MIN(id) as keeper, COUNT(*) as cnt")
            ->groupByRaw("$p, $t, $per")
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $propertyId = trim((string) $group->p);
            $tin = trim((string) $group->t);

            if ($propertyId === '' || $tin === '') {
                continue;
            }

            $keeper = (int) $group->keeper;

            $dupeIds = DB::table(self::TABLE)
                ->whereNull('deleted_at')
                ->where('id', '!=', $keeper)
                ->whereRaw("$p = ?", [$propertyId])
                ->whereRaw("$t = ?", [$tin])
                ->whereRaw("$per = ?", [trim((string) $group->per)])
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (! $dupeIds) {
                continue;
            }

            $this->mergeDependentsOn($dupeIds, $keeper);

            DB::table(self::TABLE)->whereIn('id', $dupeIds)->update(['deleted_at' => now()]);

            if (defined('STDOUT')) {
                fwrite(STDOUT, sprintf(
                    '[unique-bill-logging] Merged %d duplicate log row(s) (%s) onto bill id %d for Property %s / TIN %s / Tax Period %s.'.PHP_EOL,
                    count($dupeIds),
                    implode(', ', array_map('strval', $dupeIds)),
                    $keeper,
                    $propertyId,
                    $tin,
                    trim((string) $group->per)
                ));
            }
        }
    }

    private function mergeDependentsOn(array $dupeIds, int $keeper): void
    {
        foreach (self::DEPENDENT_TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'bill_id')) {
                DB::table($table)->whereIn('bill_id', $dupeIds)->update(['bill_id' => $keeper]);
            }
        }

        if (Schema::hasTable('tasks')) {
            DB::table('tasks')
                ->where('reference_type', 'property_bill')
                ->whereIn('reference_id', $dupeIds)
                ->update(['reference_id' => $keeper]);
        }
    }

    /**
     * Stored generated key: normalized triple for every LIVE row that has a
     * real Property ID and TIN; NULL otherwise, so soft-deleted duplicates,
     * legacy blank rows and NULL Tax Periods are all excluded from uniqueness.
     */
    private function addUniqueBackstop(): void
    {
        if (Schema::hasColumn(self::TABLE, self::KEY_COLUMN)) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        $pid = "TRIM(COALESCE(property_id, ''))";
        $tin = "TRIM(COALESCE(tin, ''))";
        $per = "TRIM(COALESCE(tax_period, ''))";

        $expr = match ($driver) {
            'sqlite' => "UPPER(LENGTH($pid) || '|' || $pid || '|' || LENGTH($tin) || '|' || $tin || '|' || LENGTH($per) || '|' || $per)",
            default => "UPPER(CONCAT_WS('|', LENGTH($pid), $pid, LENGTH($tin), $tin, LENGTH($per), $per))",
        };

        $case = "CASE WHEN deleted_at IS NULL AND TRIM(COALESCE(property_id, '')) <> '' AND TRIM(COALESCE(tin, '')) <> '' THEN $expr ELSE NULL END";

        DB::statement(match ($driver) {
            'sqlite' => 'ALTER TABLE property_bills ADD COLUMN '.self::KEY_COLUMN." TEXT GENERATED ALWAYS AS ($case) STORED",
            default => 'ALTER TABLE property_bills ADD COLUMN '.self::KEY_COLUMN." VARCHAR(255) GENERATED ALWAYS AS ($case) STORED",
        });

        DB::statement('CREATE UNIQUE INDEX '.self::INDEX_NAME.' ON '.self::TABLE.' ('.self::KEY_COLUMN.')');
    }
};
