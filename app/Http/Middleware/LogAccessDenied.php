<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records genuine 403 authorization failures as security events (RBAC audit).
 *
 * This is a passive audit trail for intentional / accidental attempts to reach
 * resources the authenticated user is not permitted to access. It never blocks
 * or alters the response — the backend's own permission checks (EnsurePermission,
 * controller abort_unless) remain the authoritative security boundary.
 */
class LogAccessDenied
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() !== 403) {
            return $response;
        }

        $user = $request->user();
        if (! $user) {
            return $response; // unauthenticated (401) handled elsewhere
        }

        try {
            Log::channel('access_denied')->notice(
                'Access denied',
                [
                    'user_id' => $user->id,
                    'staff_id' => $user->staff_id,
                    'full_name' => $user->full_name,
                    'role' => $user->role?->name,
                    'attempted_method' => $request->method(),
                    'attempted_resource' => $request->path(),
                    'attempted_url' => $request->fullUrl(),
                    'required_permission' => $this->extractPermission($response),
                    'ip' => $request->ip(),
                    'timestamp' => now()->toIso8601String(),
                ]
            );
        } catch (\Throwable $e) {
            // Logging must never break the application response.
            report($e);
        }

        return $response;
    }

    /**
     * Best-effort extraction of the permission that was denied, from the 403
     * response body ("Missing permission: tasks.assign", etc.).
     */
    private function extractPermission(Response $response): ?string
    {
        $content = $response->getContent();

        if (! $content) {
            return null;
        }

        $decoded = json_decode($content, true);

        $message = is_array($decoded)
            ? ($decoded['message'] ?? $decoded['errors'] ?? null)
            : $content;

        if (is_string($message) && preg_match('/[Mm]issing permission[:\s]+([\w.]+)/', $message, $m)) {
            return $m[1];
        }

        return null;
    }
}
