<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EffectivePermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly EffectivePermissionResolver $effectivePermissions) {}
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Account is inactive. Contact the system administrator.'], 403);
        }

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('retd-mobile-web')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'must_reset' => $user->must_reset_password,
                'user' => $this->present($user),
            ],
            'message' => 'Authenticated.',
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'data' => $this->present($request->user()),
        ]);
    }

    /**
     * Self-service effective permission catalogue for the authenticated user.
     * Drives navigation, route guards and action visibility on web + mobile so
     * every surface reads from the exact same RBAC engine as the backend.
     */
    public function permissions(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'data' => array_merge($this->effectivePermissions->resolve($user), [
                'user' => [
                    'id' => $user->id,
                    'full_name' => $user->full_name,
                    'staff_id' => $user->staff_id,
                    'email' => $user->email,
                    'is_active' => $user->is_active,
                ],
            ]),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'password' => 'required|string|min:8|confirmed',
            'current_password' => 'sometimes|string',
        ]);

        $user = $request->user();

        if ($request->filled('current_password')) {
            abort_unless(Hash::check($request->current_password, $user->password), 422, 'Current password is incorrect.');
        } elseif (! $user->must_reset_password) {
            throw ValidationException::withMessages(['current_password' => ['Current password is required.']]);
        }

        $user->update([
            'password' => Hash::make($request->password),
            'must_reset_password' => false,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        // Invalidate other sessions: the user now has the new password.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();

        return response()->json(['message' => 'Password updated.']);
    }

    public static function present(User $user): array
    {
        return [
            'id' => $user->id,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'staff_id' => $user->staff_id,
            'is_active' => $user->is_active,
            'must_reset_password' => $user->must_reset_password,
            'last_login_at' => $user->last_login_at?->toISOString(),
            'role' => $user->role?->name,
            'role_id' => $user->role_id,
            'scope' => $user->scopeLevel(),
            'permissions' => $user->permissions(),
            'section' => $user->section?->name,
            'section_id' => $user->section_id,
            'supervisor_id' => $user->supervisor_id,
        ];
    }

    /** Validate that e-mail uniqueness respects the request's id when present.  */
    public static function emailRule(?int $ignoreId = null): array
    {
        return ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ignoreId)];
    }
}