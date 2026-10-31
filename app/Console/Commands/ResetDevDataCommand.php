<?php

namespace App\Console\Commands;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Post-test cleanup: wipes every transaction/audit/business record created
 * during manual testing, keeps the auth/RBAC baseline (users, roles,
 * permissions, sections), and re-seeds the canonical demo accounts.
 *
 * Usage: php artisan app:reset-dev  (pass --force to skip the prompt)
 */
class ResetDevDataCommand extends Command
{
    protected $signature = 'app:reset-dev {--force : Skip the confirmation prompt} {--dry-run : Print which tables would be truncated without changing anything}';

    protected $description = 'Clean test data: truncate business/audit tables, then re-seed the demo baseline';

    /**
     * Transactional / audit tables wiped by the cleanup. Auth, RBAC, sections
     * and infrastructure (sessions, tokens, cache, jobs) are preserved.
     */
    private const TRUNCATE_TABLES = [
        'appeals',
        'attachments',
        'audit_chain_locks',
        'audit_logs',
        'bill_amendments',
        'data_quality_flags',
        'enforcement_visits',
        'evidence_photos',
        'gps_location_history',
        'gps_locations',
        'me_queries',
        'notifications',
        'payment_verifications',
        'payments',
        'property_bills',
        'property_discoveries',
        'receipt_fingerprints',
        'staff_targets',
        'sync_transactions',
        'task_engagements',
        'task_histories',
        'tasks',
        'valuation_property_descriptions',
        'valuation_reviews',
        'valuations',
    ];

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to run against production. Pass --force only if you really mean it.');

            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');

        if (! $dryRun && ! $this->option('force') && ! $this->confirm('This wipes all business/audit data and re-seeds the demo baseline. Continue?', false)) {
            $this->line('Aborted. Nothing was changed.');

            return self::SUCCESS;
        }

        $purged = $this->selectedTables();

        if ($dryRun) {
            $this->info('Dry run — the following tables would be truncated ('.$this->businessRowCount().' rows total):');

            foreach (self::TRUNCATE_TABLES as $table) {
                $marker = in_array($table, $purged, true) ? 'truncate' : 'skip (missing)';
                $this->line("  {$marker}: {$table}");
            }

            $this->warn('Nothing was changed. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        $this->truncateBusinessTables($purged);
        $this->callSilently(DatabaseSeeder::class);

        $this->info('Test data cleaned. Business/audit tables truncated: '.implode(', ', $purged));
        $this->warn('Demo baseline re-seeded (users, roles, permissions, sections). Run `php artisan lra:integrity-check` afterwards if needed.');

        Log::channel('stack')->info('app:reset-dev executed', ['purged_tables' => $purged]);

        return self::SUCCESS;
    }

    private function selectedTables(): array
    {
        $db = DB::connection()->getDatabaseName();

        $unqualified = collect(DB::getSchemaBuilder()->getTableListing())
            ->map(fn ($table) => str_starts_with($table, $db.'.')
                ? substr($table, strlen($db) + 1)
                : (str_contains($table, '.') ? null : $table))
            ->filter()
            ->values()
            ->all();

        return array_values(array_intersect(self::TRUNCATE_TABLES, $unqualified));
    }

    private function businessRowCount(): int
    {
        $total = 0;

        foreach (self::TRUNCATE_TABLES as $table) {
            if (in_array($table, $this->selectedTables(), true)) {
                $total += DB::table($table)->count();
            }
        }

        return $total;
    }

    private function truncateBusinessTables(array $selected): array
    {
        if (! $selected) {
            return [];
        }

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($selected as $table) {
                DB::table($table)->truncate();
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $selected;
    }
}
