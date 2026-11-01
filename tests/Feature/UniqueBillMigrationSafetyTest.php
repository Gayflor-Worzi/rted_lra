<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class UniqueBillMigrationSafetyTest extends TestCase
{
    public function test_duplicate_live_assessments_block_migration_without_changing_bill_records(): void
    {
        config([
            'database.connections.migration_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('migration_test');
        $defaultConnection = config('database.default');
        config(['database.default' => 'migration_test']);

        try {
            Schema::create('property_bills', function ($table): void {
                $table->id();
                $table->string('property_id')->nullable();
                $table->string('tin')->nullable();
                $table->string('tax_period')->nullable();
                $table->timestamp('deleted_at')->nullable();
            });
            DB::table('property_bills')->insert([
                ['id' => 1, 'property_id' => 'PR-1', 'tin' => 'TIN-1', 'tax_period' => '2026'],
                ['id' => 2, 'property_id' => 'PR-1', 'tin' => 'TIN-1', 'tax_period' => '2026'],
            ]);

            $migration = require database_path('migrations/2026_09_13_000002_enforce_unique_bill_logging.php');
            $migration->up();

            $this->fail('The migration should stop rather than hide duplicate bill records.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('No bill or linked records were changed', $exception->getMessage());
            $this->assertSame(2, DB::table('property_bills')->whereNull('deleted_at')->count());
            $this->assertFalse(Schema::hasColumn('property_bills', 'assessment_key'));
        } finally {
            config(['database.default' => $defaultConnection]);
            DB::purge('migration_test');
        }
    }
}
