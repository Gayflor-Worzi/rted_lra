<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Block all authenticated API requests when the user must reset their password,
 * except for the reset-password, logout, and me endpoints.
 */
class EnsurePasswordReset
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && $user->must_reset_password) {
            $allowed = in_array($request->route()->getActionMethod() ?? '', [
                'resetPassword',
                'logout',
                'me',
            ], true);

            $allowedPaths = ['auth/reset-password', 'auth/logout', 'auth/me'];

            $path = $request->path();

            if (! $allowed && ! in_array($path, $allowedPaths, true)) {
                return response()->json([
                    'message' => 'You must reset your password before continuing.',
                    'must_reset' => true,
                ], 403);
            }
        }

        return $next($request);
    }
}
