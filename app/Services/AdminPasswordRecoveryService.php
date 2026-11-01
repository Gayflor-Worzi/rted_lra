<?php

namespace App\Services;

use App\Mail\AdminPasswordRecoveryOtp;
use App\Models\AdminPasswordRecoveryRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

class AdminPasswordRecoveryService
{
    private const OTP_TTL_MINUTES = 5;

    private const RESET_TTL_MINUTES = 5;

    private const MAX_OTP_ATTEMPTS = 5;

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @return array{reference: string, rate_limited: bool, delivery_unavailable: bool}
     */
    public function requestOtp(string $email, string $ip, ?string $userAgent): array
    {
        $normalizedEmail = mb_strtolower(trim($email));
        $emailKey = 'admin-recovery:email:'.hash_hmac('sha256', $normalizedEmail, (string) config('app.key'));
        $ipKey = 'admin-recovery:request-ip:'.hash('sha256', $ip);

        if ($this->rateLimited($emailKey, 3, 900) || $this->rateLimited($ipKey, 10, 60)) {
            $this->record(null, 'password_recovery.rate_limited', 'rate_limited', $ip, $userAgent);

            return ['reference' => '', 'rate_limited' => true, 'delivery_unavailable' => false];
        }

        $mailer = config('mail.mailers.'.config('mail.default'), []);
        if ($this->usesNonDeliveringTransport($mailer ?? [])) {
            $this->record(null, 'password_recovery.email_failed', 'non_delivering_mail_transport', $ip, $userAgent);
            Log::warning('Administrator password recovery email is unavailable: configure a delivering mail transport.');

            return ['reference' => '', 'rate_limited' => false, 'delivery_unavailable' => true];
        }

        $reference = bin2hex(random_bytes(32));
        $otp = (string) random_int(100000, 999999);
        $user = User::query()->whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();

        if (! $user || ! $this->isEligible($user)) {
            $this->record(
                $user?->id,
                'password_recovery.requested',
                'not_eligible',
                $ip,
                $userAgent,
                hash('sha256', $reference),
            );

            return ['reference' => $reference, 'rate_limited' => false, 'delivery_unavailable' => false];
        }

        $expiresAt = now()->addMinutes(self::OTP_TTL_MINUTES);
        $recovery = DB::transaction(function () use ($user, $reference, $otp, $expiresAt, $ip, $userAgent): ?AdminPasswordRecoveryRequest {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! $this->isEligible($lockedUser)) {
                return null;
            }

            AdminPasswordRecoveryRequest::query()
                ->where('user_id', $lockedUser->id)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now(), 'updated_at' => now()]);

            $recovery = AdminPasswordRecoveryRequest::create([
                'user_id' => $lockedUser->id,
                'request_token_hash' => hash('sha256', $reference),
                'otp_hash' => Hash::make($otp),
                'expires_at' => $expiresAt,
                'request_ip' => $ip,
                'request_user_agent' => Str::limit((string) $userAgent, 500, ''),
            ]);

            $this->record(
                $lockedUser->id,
                'password_recovery.requested',
                'otp_issued',
                $ip,
                $userAgent,
                hash('sha256', $reference),
                $recovery->id,
            );

            return $recovery;
        });

        if (! $recovery) {
            $this->record($user->id, 'password_recovery.requested', 'account_not_eligible', $ip, $userAgent);

            return ['reference' => $reference, 'rate_limited' => false, 'delivery_unavailable' => false];
        }

        try {
            Mail::to($user->email)->send(new AdminPasswordRecoveryOtp(
                $otp,
                $expiresAt->timezone(config('app.timezone'))->format('H:i T'),
            ));
        } catch (Throwable $exception) {
            $recovery->forceFill(['invalidated_at' => now()])->save();
            $this->record(
                $user->id,
                'password_recovery.email_failed',
                'transport_error',
                $ip,
                $userAgent,
                null,
                $recovery->id,
            );
            Log::warning('Administrator password recovery email transport failed.', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);

            return ['reference' => '', 'rate_limited' => false, 'delivery_unavailable' => true];
        }

        $this->record(
            $user->id,
            'password_recovery.email_sent',
            'transport_accepted',
            $ip,
            $userAgent,
            null,
            $recovery->id,
        );

        return ['reference' => $reference, 'rate_limited' => false, 'delivery_unavailable' => false];
    }

    public function verifyOtp(string $reference, string $otp, string $ip, ?string $userAgent): ?string
    {
        $ipKey = 'admin-recovery:verify-ip:'.hash('sha256', $ip);
        if ($this->rateLimited($ipKey, 20, 900)) {
            $this->record(null, 'password_recovery.rate_limited', 'rate_limited', $ip, $userAgent);

            return null;
        }

        return DB::transaction(function () use ($reference, $otp, $ip, $userAgent): ?string {
            $recovery = AdminPasswordRecoveryRequest::query()
                ->where('request_token_hash', hash('sha256', $reference))
                ->lockForUpdate()
                ->first();

            if (! $this->isValidRecovery($recovery) || $recovery->verified_at || $recovery->failed_attempts >= self::MAX_OTP_ATTEMPTS) {
                $this->record($recovery?->user_id, 'password_recovery.otp_rejected', 'invalid_or_expired', $ip, $userAgent, null, $recovery?->id);

                return null;
            }

            $user = $recovery->user_id ? User::query()->lockForUpdate()->find($recovery->user_id) : null;
            if (! $user || ! $this->isEligible($user)) {
                $recovery->forceFill(['invalidated_at' => now()])->save();
                $this->record($recovery->user_id, 'password_recovery.otp_rejected', 'account_not_eligible', $ip, $userAgent, null, $recovery->id);

                return null;
            }

            if (! Hash::check($otp, $recovery->otp_hash)) {
                $recovery->increment('failed_attempts');
                $this->record($user->id, 'password_recovery.otp_rejected', 'incorrect_code', $ip, $userAgent, null, $recovery->id);

                return null;
            }

            $resetToken = bin2hex(random_bytes(32));
            $recovery->forceFill([
                'verified_at' => now(),
                'reset_token_hash' => hash('sha256', $resetToken),
                'reset_expires_at' => now()->addMinutes(self::RESET_TTL_MINUTES),
            ])->save();

            $this->record($user->id, 'password_recovery.otp_verified', 'verified', $ip, $userAgent, null, $recovery->id);

            return $resetToken;
        });
    }

    public function completeReset(string $resetToken, string $password, string $ip, ?string $userAgent): bool
    {
        return DB::transaction(function () use ($resetToken, $password, $ip, $userAgent): bool {
            $recovery = AdminPasswordRecoveryRequest::query()
                ->where('reset_token_hash', hash('sha256', $resetToken))
                ->lockForUpdate()
                ->first();

            if (! $this->isValidRecovery($recovery)
                || ! $recovery->verified_at
                || ! $recovery->reset_expires_at
                || $recovery->reset_expires_at->isPast()
                || $recovery->consumed_at) {
                $this->record($recovery?->user_id, 'password_recovery.reset_rejected', 'invalid_or_expired', $ip, $userAgent, null, $recovery?->id);

                return false;
            }

            $user = $recovery->user_id ? User::query()->lockForUpdate()->find($recovery->user_id) : null;
            if (! $user || ! $this->isEligible($user)) {
                $recovery->forceFill(['invalidated_at' => now()])->save();
                $this->record($recovery->user_id, 'password_recovery.reset_rejected', 'account_not_eligible', $ip, $userAgent, null, $recovery->id);

                return false;
            }

            $user->forceFill([
                'password' => $password,
                'password_changed_at' => now(),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
            $user->tokens()->delete();

            $recovery->forceFill([
                'consumed_at' => now(),
                'otp_hash' => '',
                'reset_token_hash' => null,
            ])->save();

            $this->record($user->id, 'password_recovery.completed', 'password_reset', $ip, $userAgent, null, $recovery->id);

            return true;
        });
    }

    private function isValidRecovery(?AdminPasswordRecoveryRequest $recovery): bool
    {
        return $recovery
            && ! $recovery->invalidated_at
            && ! $recovery->consumed_at
            && ! $recovery->expires_at->isPast();
    }

    /**
     * @param  array<string, mixed>  $mailer
     */
    private function usesNonDeliveringTransport(array $mailer): bool
    {
        if (in_array($mailer['transport'] ?? null, ['log', 'array', 'null'], true)) {
            return true;
        }

        foreach (($mailer['mailers'] ?? []) as $nestedMailer) {
            if (is_string($nestedMailer)) {
                $nestedMailer = config('mail.mailers.'.$nestedMailer, []);
            }

            if (is_array($nestedMailer) && $this->usesNonDeliveringTransport($nestedMailer)) {
                return true;
            }
        }

        return false;
    }

    private function isEligible(User $user): bool
    {
        return $user->is_active
            && $user->isSystemAdministrator()
            && (bool) $user->role?->is_active;
    }

    private function rateLimited(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return true;
        }

        RateLimiter::hit($key, $decaySeconds);

        return false;
    }

    private function record(
        ?int $userId,
        string $action,
        string $result,
        string $ip,
        ?string $userAgent,
        ?string $referenceHash = null,
        ?int $recoveryId = null,
    ): void {
        $this->audit->append([
            'actor_id' => null,
            'action' => $action,
            'auditable_type' => 'password_recovery',
            'auditable_id' => $recoveryId ?? $userId,
            'new_values' => array_filter([
                'user_id' => $userId,
                'result' => $result,
                'source' => 'email_otp',
                'initiator' => 'public_email_recovery_flow',
                'request_reference_hash' => $referenceHash,
                'user_agent' => Str::limit((string) $userAgent, 500, ''),
            ], static fn ($value) => $value !== null),
            'ip_address' => $ip,
        ]);
    }
}
