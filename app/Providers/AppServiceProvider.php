<?php

namespace App\Providers;

use App\Mail\Transport\BrevoApiTransport;
use App\Support\ProductionGuard;
use Illuminate\Support\Facades\Mail;
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
        Mail::extend('brevo', function (array $config): BrevoApiTransport {
            return new BrevoApiTransport(
                config('services.brevo.api_key'),
                config('mail.from.address'),
                config('mail.from.name'),
            );
        });

        // Fail fast if production is booted with debugging enabled — never
        // expose stack traces, paths or secrets to production users (G0.1).
        ProductionGuard::assertEnvironmentSafe();
    }
}
