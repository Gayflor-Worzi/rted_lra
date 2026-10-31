<?php

namespace Tests\Unit;

use App\Support\ProductionGuard;
use PHPUnit\Framework\TestCase;

/**
 * G0.1 — the production environment must never boot with debugging enabled.
 */
class ProductionGuardTest extends TestCase
{
    public function test_production_with_debug_true_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_DEBUG=true/');

        ProductionGuard::assertEnvironmentSafe('production', true);
    }

    public function test_production_with_debug_disabled_boots(): void
    {
        ProductionGuard::assertEnvironmentSafe('production', false);
        $this->assertTrue(true);
    }

    public function test_development_with_debug_enabled_is_allowed(): void
    {
        ProductionGuard::assertEnvironmentSafe('local', true);
        $this->assertTrue(true);
    }
}
