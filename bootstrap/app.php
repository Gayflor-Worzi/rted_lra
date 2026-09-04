<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(App\Http\Middleware\ApiResponseEnvelope::class);
        $middleware->api(App\Http\Middleware\CorsMiddleware::class);
        $middleware->api(App\Http\Middleware\LogAccessDenied::class);
        $middleware->api(App\Http\Middleware\EnsurePasswordReset::class);
        $middleware->append(App\Http\Middleware\SecurityHeaders::class);
        $middleware->alias([
            'permission' => \App\Http\Middleware\EnsurePermission::class,
            'auth' => \App\Http\Middleware\Authenticate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            function ($request) { return $request->is('api/*') || $request->expectsJson(); },
        );
    })->create();