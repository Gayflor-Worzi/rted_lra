<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NonDestructiveTaskMigrationTest extends TestCase
{
    public function test_legacy_task_deduplication_migration_preserves_tasks_histories_and_notifications(): void
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
            Schema::create('tasks', function ($table): void {
                $table->id();
            });
            Schema::create('task_histories', function ($table): void {
                $table->id();
            });
            Schema::create('notifications', function ($table): void {
                $table->id();
            });

            DB::table('tasks')->insert([['id' => 1], ['id' => 2]]);
            DB::table('task_histories')->insert([['id' => 1], ['id' => 2]]);
            DB::table('notifications')->insert([['id' => 1], ['id' => 2]]);

            $migration = require database_path('migrations/2026_09_14_000001_dedupe_open_tasks.php');
            $migration->up();

            $this->assertSame(2, DB::table('tasks')->count());
            $this->assertSame(2, DB::table('task_histories')->count());
            $this->assertSame(2, DB::table('notifications')->count());
        } finally {
            config(['database.default' => $defaultConnection]);
            DB::purge('migration_test');
        }
    }
}
