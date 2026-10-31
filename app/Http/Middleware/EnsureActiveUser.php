<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block all authenticated API requests for deactivated accounts.
 *
 * Token validation alone is not enough: a deactivated user whose token is
 * still valid must lose access immediately, otherwise account deactivation
 * (UsersController::setActive) would be bypassed until the token expires.
 * The logout endpoint stays open so the device can clean up its session.
 */
class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            $path = $request->path();

            // The logout route is prefixed (`api/v1/auth/logout`) and must stay
            // reachable so a deactivated device can clean up its session.
            $isLogout = $path === 'auth/logout' || str_ends_with($path, '/auth/logout');

            if (! $isLogout) {
                return response()->json([
                    'message' => 'Your account is deactivated. Contact the system administrator.',
                ], 403);
            }
        }

        return $next($request);
    }
}
