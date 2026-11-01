<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkflowMigrationDataPreservationTest extends TestCase
{
    public function test_workflow_schema_migration_preserves_existing_records(): void
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
            $this->createExistingWorkflowSchema();
            $this->insertExistingWorkflowRecords();

            $migration = require database_path('migrations/2026_09_12_000001_reset_workflow_discovery_valuation.php');
            $migration->up();

            $this->assertSame(1, DB::table('property_discoveries')->count());
            $this->assertSame(1, DB::table('valuations')->count());
            $this->assertSame(1, DB::table('valuation_property_descriptions')->count());
            $this->assertSame(1, DB::table('valuation_reviews')->count());
            $this->assertSame(1, DB::table('tasks')->count());
            $this->assertSame(1, DB::table('task_histories')->count());
            $this->assertSame(1, DB::table('evidence_photos')->count());
            $this->assertTrue(Schema::hasColumn('property_discoveries', 'declared_value'));
            $this->assertTrue(Schema::hasColumn('valuations', 'account_reviewed_by'));
        } finally {
            config(['database.default' => $defaultConnection]);
            DB::purge('migration_test');
        }
    }

    private function createExistingWorkflowSchema(): void
    {
        Schema::create('users', function ($table): void {
            $table->id();
        });
        Schema::create('property_discoveries', function ($table): void {
            $table->id();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('discovered_by')->nullable();
            $table->timestamp('classified_at')->nullable();
            $table->unsignedBigInteger('valuation_id')->nullable();
            $table->string('manager_remarks')->nullable();
            $table->string('status')->nullable();
            $table->string('decision_path')->nullable();
        });
        Schema::create('valuations', function ($table): void {
            $table->id();
            $table->timestamp('ac_reviewed_at')->nullable();
        });

        foreach (['valuation_property_descriptions', 'valuation_reviews', 'tasks', 'task_histories', 'evidence_photos'] as $tableName) {
            Schema::create($tableName, function ($table): void {
                $table->id();
            });
        }
    }

    private function insertExistingWorkflowRecords(): void
    {
        DB::table('property_discoveries')->insert(['id' => 1, 'description' => 'Existing discovery']);
        DB::table('valuations')->insert(['id' => 1]);
        DB::table('valuation_property_descriptions')->insert(['id' => 1]);
        DB::table('valuation_reviews')->insert(['id' => 1]);
        DB::table('tasks')->insert(['id' => 1]);
        DB::table('task_histories')->insert(['id' => 1]);
        DB::table('evidence_photos')->insert(['id' => 1]);
    }
}
