<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'property_bills';

    private const INDEX_NAME = 'property_bills_assessment_unique';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)
            || DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $hasIndex = collect(Schema::getIndexes(self::TABLE))
            ->contains(fn (array $index) => $index['name'] === self::INDEX_NAME);

        if ($hasIndex) {
            DB::statement('DROP INDEX '.self::INDEX_NAME);
        }

        // SQLite's property-identity table rebuild leaves this key as ordinary
        // text, so enforce the normalized live-assessment tuple directly.
        $propertyId = "TRIM(COALESCE(property_id, ''))";
        $tin = "TRIM(COALESCE(tin, ''))";
        $period = "TRIM(COALESCE(tax_period, ''))";
        $key = "UPPER(LENGTH($propertyId) || '|' || $propertyId || '|' || LENGTH($tin) || '|' || $tin || '|' || LENGTH($period) || '|' || $period)";
        $eligible = "deleted_at IS NULL AND $propertyId <> '' AND $tin <> ''";

        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX_NAME.' ON '.self::TABLE.' ('.$key.') WHERE '.$eligible
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite'
            || ! Schema::hasTable(self::TABLE)) {
            return;
        }

        $hasIndex = collect(Schema::getIndexes(self::TABLE))
            ->contains(fn (array $index) => $index['name'] === self::INDEX_NAME);

        if ($hasIndex) {
            DB::statement('DROP INDEX '.self::INDEX_NAME);
        }
    }
};
