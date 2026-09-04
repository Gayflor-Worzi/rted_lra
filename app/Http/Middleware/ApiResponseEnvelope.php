<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiResponseEnvelope
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Never wrap file downloads / non-JSON payloads (e.g. CSV/PDF exports)
        $contentType = $response->headers->get('Content-Type', '');
        if (str_starts_with($contentType, 'text/csv') || $response->headers->has('Content-Disposition')) {
            return $response;
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            $originalContent = $response->getContent();

            $decoded = $originalContent ? json_decode($originalContent, true) : null;
            $isJson = $decoded !== null;

            // Pass through responses already enveloped, and native error shapes
            // (validation `errors`, bare `message`) — don't double-wrap.
            if ($isJson) {
                if (array_key_exists('success', $decoded) && array_key_exists('data', $decoded)) {
                    return $response;
                }
                if (array_key_exists('errors', $decoded)) {
                    return $response;
                }
                if (array_key_exists('message', $decoded) && ! array_key_exists('data', $decoded)) {
                    return $response;
                }
            }

            $data = null;
            $message = null;
            $errors = null;

            // Try to parse existing JSON data
            if ($isJson) {
                $data = $decoded['data'] ?? $decoded['result'] ?? null;
                $message = $decoded['message'] ?? null;
                $errors = $decoded['errors'] ?? null;
            }

            // If no data extracted from existing response, use the full content as data
            if ($data === null) {
                $data = $isJson ? $decoded : ($originalContent ? json_decode($originalContent, true) : null);
            }

            $statusCode = $response->getStatusCode();

            $envelope = [
                'success' => $statusCode >= 200 && $statusCode < 300,
                'data' => $data,
                'message' => $message,
                'errors' => $errors,
            ];

            return response()->json($envelope, $statusCode);
        }

        return $response;
    }
}
