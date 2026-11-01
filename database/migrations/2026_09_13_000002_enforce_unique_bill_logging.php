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

    /**
     * One assessment may be logged only once: the normalized Property ID + TIN
     * + Tax Period triple is unique while the document is live. Existing
     * duplicate assessments must be reviewed before the unique backstop is
     * added; this migration does not merge or hide existing bill records. The
     * API layer is the primary guard and returns the friendly 422 + Amend hint;
     * the index prevents concurrent double-logging.
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

        $this->assertNoUnresolvedDuplicateAssessments();
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

    private function assertNoUnresolvedDuplicateAssessments(): void
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

        $hasUnresolvedDuplicate = $groups->contains(function ($group): bool {
            return trim((string) $group->p) !== ''
                && trim((string) $group->t) !== '';
        });

        if ($hasUnresolvedDuplicate) {
            throw new RuntimeException(
                'Migration 2026_09_13_000002_enforce_unique_bill_logging found duplicate live assessments. '
                .'No bill or linked records were changed; review and resolve duplicates before retrying.'
            );
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
            'sqlite', 'pgsql' => "UPPER(LENGTH($pid) || '|' || $pid || '|' || LENGTH($tin) || '|' || $tin || '|' || LENGTH($per) || '|' || $per)",
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
