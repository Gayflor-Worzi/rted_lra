<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$spa = public_path('index.html');

// Serve the React SPA for any unmatched GET (client-side routing), while
// keeping unknown API paths as JSON 404s. `public/index.html` is produced by
// the frontend build (`npm run build`) and copied next to Laravel's entry.
Route::fallback(function (Request $request) use ($spa) {
    if ($request->isMethod('GET') && ! str_starts_with($request->path(), 'api')) {
        if (is_file($spa)) {
            return response()->file($spa);
        }
    }

    return response()->json(['message' => 'Not Found'], 404);
});