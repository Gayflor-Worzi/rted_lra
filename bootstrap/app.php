<?php

use App\Http\Middleware\ApiResponseEnvelope;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsurePasswordReset;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\LogAccessDenied;
use App\Http\Middleware\SecurityHeaders;
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
        $middleware->api(ApiResponseEnvelope::class);
        $middleware->api(CorsMiddleware::class);
        $middleware->api(LogAccessDenied::class);
        $middleware->api(EnsurePasswordReset::class);
        $middleware->api(EnsureActiveUser::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'permission' => EnsurePermission::class,
            'auth' => Authenticate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            function ($request) {
                return $request->is('api/*') || $request->expectsJson();
            },
        );
        $exceptions->respond(function ($response, $exception, $request) {
            $origin = $request->header('Origin');

            if ($origin && in_array($origin, config('cors.allowed_origins', []), true)) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
                $response->headers->set('Vary', 'Origin');
                $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
                $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
            }

            return $response;
        });
    })->create();
