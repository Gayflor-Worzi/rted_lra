<?php

namespace App\Support;

use RuntimeException;

/**
 * Fails fast when the application is running with the production environment
 * and debugging enabled — a combination that silently leaks stack traces,
 * filesystem paths and secrets to real users. Production must boot with
 * APP_ENV=production and APP_DEBUG=false (pre-release audit G0.1).
 *
 * The check is parameterised for direct unit testing; in normal boot it reads
 * the resolved config (which reflects cached config in production).
 */
class ProductionGuard
{
    public static function assertEnvironmentSafe(?string $environment = null, ?bool $debug = null): void
    {
        $effectiveEnvironment = $environment ?? (config('app.env') ?: env('APP_ENV', 'production'));
        $effectiveDebug = $debug ?? (bool) config('app.debug', env('APP_DEBUG', false));

        if ($effectiveEnvironment === 'production' && $effectiveDebug) {
            throw new RuntimeException(
                'Refusing to boot: APP_ENV=production with APP_DEBUG=true. Set APP_DEBUG=false in the production environment before starting the application.'
            );
        }
    }
}
