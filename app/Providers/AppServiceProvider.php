<?php

namespace App\Providers;

use App\Support\ProductionGuard;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fail fast if production is booted with debugging enabled — never
        // expose stack traces, paths or secrets to production users (G0.1).
        ProductionGuard::assertEnvironmentSafe();
    }
}
