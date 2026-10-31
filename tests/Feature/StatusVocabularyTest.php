<?php

namespace Tests\Feature;

use App\Models\PropertyDiscovery;
use App\Models\User;
use App\Services\StatusEnumeration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The status vocabulary published to clients must be the vocabulary stored.
 *
 * `/lookup/statuses` used to publish a second, uppercase discovery list
 * (DISCOVERED, SENT_TO_ACCOUNT, PENDING_AC_APPROVAL, …). The workflow never
 * writes those values, so a client building a filter or a legend from the
 * endpoint produced options that matched no record. The enumeration now
 * delegates to the model, and this test keeps it that way.
 */
class StatusVocabularyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_the_published_discovery_vocabulary_is_the_stored_one(): void
    {
        Sanctum::actingAs(User::where('email', 'account.officer@test.lra')->firstOrFail());

        $published = $this->getJson('/api/v1/lookup/statuses')
            ->assertOk()
            ->json('data.discovery_statuses');

        $this->assertSame(PropertyDiscovery::STATUSES, $published);

        // No parallel uppercase vocabulary survives (those constants used
        // underscores: SENT_TO_ACCOUNT, PENDING_AC_APPROVAL, …).
        foreach ($published as $status) {
            $this->assertStringNotContainsString('_', $status, "Unexpected non-vocabulary status: {$status}");
        }
    }

    public function test_the_enumeration_exposes_no_status_outside_the_model(): void
    {
        $this->assertSame(PropertyDiscovery::STATUSES, StatusEnumeration::DISCOVERY_STATUSES);
    }
}
