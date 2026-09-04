<?php

namespace App\Console\Commands;

use App\Services\DataIntegrityService;
use Illuminate\Console\Command;

/**
 * Cross-checks every stored dashboard figure against the authoritative
 * transactional records it derives from. Callable by hand: php artisan
 * lra:integrity-check. Returns a non-zero exit code when drift is found.
 */
class IntegrityCheckCommand extends Command
{
    protected $signature = 'lra:integrity-check {--format=text : Output format (text or json)}';

    protected $description = 'Check dashboard figures against source records for data drift';

    public function handle(): int
    {
        $result = DataIntegrityService::run();
        $format = $this->option('format');

        if ($format === 'json') {
            $this->line(json_encode($result));
        } else {
            $this->line('Data integrity check — '.$result['status'].' ('.date('c').')');

            foreach ($result['checks'] as $check) {
                $marker = $check['count'] > 0 ? '✗' : '✓';
                $this->line("  {$marker} {$check['label']} — {$check['count']} finding(s)");

                foreach ($check['findings'] as $finding) {
                    $this->line("      #{$finding['id']} [{$finding['reference']}] {$finding['issue']}");
                }
            }

            if ($result['status'] === 'degraded') {
                $this->warn('Drift detected: dashboard figures are out of sync with the source records.');
            } else {
                $this->info('No drift detected — every dashboard figure matches its source records.');
            }
        }

        return $result['status'] === 'degraded' ? self::FAILURE : self::SUCCESS;
    }
}