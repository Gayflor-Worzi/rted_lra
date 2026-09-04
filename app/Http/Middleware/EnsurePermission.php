<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * Guard a route with a permission checklist entry, e.g. `permission:bills.create`.
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->canPermission($permission)) {
            return response()->json([
                'message' => 'Forbidden. Missing permission: '.$permission,
            ], 403);
        }

        return $next($request);
    }
}