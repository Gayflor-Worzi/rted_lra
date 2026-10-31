<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The enforcement Discovery pipeline strip reads `/discoveries/stats`.
 *
 * The strip asked for `sent_to_account`, `requiring_valuation` and
 * `pending_ac` — keys the endpoint never returns — so three of its seven tiles
 * could only render zero. This pins the contract the strip now relies on, from
 * the desk that actually views it (an Enforcement Officer holds
 * `discovery.view`).
 */
class DiscoveryPipelineStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_the_stats_endpoint_exposes_every_stage_the_pipeline_strip_reads(): void
    {
        Sanctum::actingAs(User::where('email', 'enf.officer@test.lra')->firstOrFail());

        $stats = $this->getJson('/api/v1/discoveries/stats')->assertOk()->json('data');

        foreach ([
            'awaiting_review',
            'awaiting_acct_review',
            'awaiting_valuation',
            'under_valuation',
            'awaiting_ac',
            'registered',
            'completed',
        ] as $key) {
            $this->assertArrayHasKey($key, $stats, "The pipeline strip reads `{$key}`, so the endpoint must return it.");
            $this->assertIsInt($stats[$key]);
        }
    }
}
