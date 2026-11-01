<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminPasswordRecoveryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class AdminPasswordRecoveryController extends Controller
{
    public function __construct(private readonly AdminPasswordRecoveryService $recovery) {}

    public function requestOtp(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $result = $this->recovery->requestOtp(
            $validated['email'],
            (string) $request->ip(),
            $request->userAgent(),
        );

        if ($result['rate_limited']) {
            return response()->json(['message' => 'Too many recovery attempts. Please wait before trying again.'], 429);
        }

        if ($result['delivery_unavailable']) {
            return response()->json([
                'message' => 'Password recovery email is currently unavailable. Contact the system administrator.',
            ], 503);
        }

        return response()->json([
            'data' => ['reference' => $result['reference']],
            'message' => 'If this address belongs to an active System Administrator, a verification code will be sent.',
        ], 202);
    }

    public function verifyOtp(Request $request)
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/i'],
            'otp' => ['required', 'string', 'digits:6'],
        ]);

        $resetToken = $this->recovery->verifyOtp(
            $validated['reference'],
            $validated['otp'],
            (string) $request->ip(),
            $request->userAgent(),
        );

        if (! $resetToken) {
            return response()->json(['message' => 'The verification code is invalid or expired.'], 422);
        }

        return response()->json([
            'data' => ['reset_token' => $resetToken],
            'message' => 'Verification complete.',
        ]);
    }

    public function completeReset(Request $request)
    {
        $validated = $request->validate([
            'reset_token' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/i'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
            ],
        ]);

        $updated = $this->recovery->completeReset(
            $validated['reset_token'],
            $validated['password'],
            (string) $request->ip(),
            $request->userAgent(),
        );

        if (! $updated) {
            return response()->json(['message' => 'The recovery request is invalid or expired.'], 422);
        }

        return response()->json(['message' => 'Password updated. Sign in with your new password.']);
    }
}
