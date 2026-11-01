<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MigrationStartupIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_startup_migration_command_does_not_reapply_recorded_migrations(): void
    {
        $appliedMigrationCount = DB::table('migrations')->count();

        $exitCode = Artisan::call('migrate', [
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame($appliedMigrationCount, DB::table('migrations')->count());
    }
}
