<?php

namespace Tests\Feature;

use App\Mail\AdminPasswordRecoveryOtp;
use App\Models\AdminPasswordRecoveryRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminPasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['mail.default' => 'smtp']);
        Mail::fake();

        $this->admin = User::query()
            ->whereHas('role', fn ($query) => $query->where('name', 'System Administrator'))
            ->firstOrFail();
    }

    public function test_recovery_is_available_only_to_active_system_administrators_without_disclosing_account_status(): void
    {
        $adminResponse = $this->postJson('/api/v1/auth/password-recovery/request', [
            'email' => $this->admin->email,
        ])->assertAccepted();

        $staff = User::query()
            ->whereHas('role', fn ($query) => $query->where('name', '!=', 'System Administrator'))
            ->firstOrFail();
        $staffResponse = $this->postJson('/api/v1/auth/password-recovery/request', [
            'email' => $staff->email,
        ])->assertAccepted();

        $this->assertSame($adminResponse->json('message'), $staffResponse->json('message'));
        $this->assertSame(64, strlen($adminResponse->json('data.reference')));
        $this->assertSame(64, strlen($staffResponse->json('data.reference')));
        $this->assertDatabaseCount('admin_password_recovery_requests', 1);
        $this->assertDatabaseHas('admin_password_recovery_requests', ['user_id' => $this->admin->id]);
        Mail::assertSentTimes(AdminPasswordRecoveryOtp::class, 1);
    }

    public function test_non_delivering_mail_transports_do_not_send_or_persist_recovery_codes(): void
    {
        foreach (['log', 'array', 'failover'] as $mailer) {
            config(['mail.default' => $mailer]);
            $this->postJson('/api/v1/auth/password-recovery/request', [
                'email' => $this->admin->email,
            ])->assertStatus(503);
        }

        Mail::assertNothingSent();
        $this->assertDatabaseCount('admin_password_recovery_requests', 0);
        $this->assertSame(3, AuditLog::query()->where('action', 'password_recovery.email_failed')->count());
    }

    public function test_smtp_failure_returns_unavailable_without_logging_otp_or_claiming_delivery(): void
    {
        Log::spy();
        Mail::shouldReceive('to')
            ->once()
            ->with($this->admin->email)
            ->andThrow(new \RuntimeException('SMTP connection rejected'));

        $response = $this->postJson('/api/v1/auth/password-recovery/request', [
            'email' => $this->admin->email,
        ])->assertStatus(503)
            ->assertJsonMissingPath('data.reference');

        $recovery = AdminPasswordRecoveryRequest::query()->firstOrFail();
        $this->assertNotNull($recovery->invalidated_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'password_recovery.email_failed',
            'auditable_id' => $recovery->id,
        ]);
        Log::shouldHaveReceived('warning')->once()->with(
            'Administrator password recovery email transport failed.',
            \Mockery::on(fn (array $context) => $context['user_id'] === $this->admin->id
                && $context['exception'] === \RuntimeException::class
                && ! array_key_exists('otp', $context)),
        );
        $this->assertStringNotContainsString('SMTP connection rejected', $response->getContent());
    }

    public function test_otp_is_stored_as_a_hash_and_a_successful_reset_preserves_account_state_and_revokes_tokens(): void
    {
        $oldPassword = 'OldAdminPassword2025!';
        $this->admin->update([
            'must_reset_password' => true,
            'password' => $oldPassword,
        ]);
        $roleId = $this->admin->role_id;
        $permissions = $this->admin->permissions();
        $staff = User::query()
            ->whereHas('role', fn ($query) => $query->where('name', '!=', 'System Administrator'))
            ->firstOrFail();
        $staffPassword = $staff->password;
        $staffRoleId = $staff->role_id;
        $this->admin->createToken('existing-session');

        $reference = $this->requestOtp();
        $mail = Mail::sent(AdminPasswordRecoveryOtp::class)->first();
        $recovery = AdminPasswordRecoveryRequest::query()->where('user_id', $this->admin->id)->firstOrFail();

        $this->assertNotSame($mail->otp, $recovery->otp_hash);
        $this->assertTrue(Hash::check($mail->otp, $recovery->otp_hash));

        $verified = $this->postJson('/api/v1/auth/password-recovery/verify', [
            'reference' => $reference,
            'otp' => $mail->otp,
        ])->assertOk();
        $resetToken = $verified->json('data.reset_token');

        $newPassword = 'LocalSafePass2026!';
        $this->postJson('/api/v1/auth/password-recovery/complete', [
            'reset_token' => $resetToken,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertOk();

        $this->admin->refresh();
        $this->assertSame($roleId, $this->admin->role_id);
        $this->assertSame($permissions, $this->admin->permissions());
        $this->assertSame($staffPassword, $staff->fresh()->password);
        $this->assertSame($staffRoleId, $staff->fresh()->role_id);
        $this->assertTrue($this->admin->must_reset_password);
        $this->assertNotNull($this->admin->password_changed_at);
        $this->assertTrue(Hash::check($newPassword, $this->admin->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNotNull($recovery->fresh()->consumed_at);

        $this->postJson('/api/v1/auth/login', [
            'email' => $this->admin->email,
            'password' => $newPassword,
        ])->assertOk()->assertJsonPath('data.must_reset', true);

        $this->postJson('/api/v1/auth/login', [
            'email' => $this->admin->email,
            'password' => $oldPassword,
        ])->assertUnprocessable();

        $this->postJson('/api/v1/auth/password-recovery/verify', [
            'reference' => $reference,
            'otp' => $mail->otp,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'password_recovery.completed',
            'auditable_type' => 'password_recovery',
            'auditable_id' => $recovery->id,
        ]);
        $this->assertStringNotContainsString($mail->otp, AuditLog::query()->where('action', 'like', 'password_recovery.%')->get()->toJson());
    }

    public function test_incorrect_otp_attempts_are_limited_and_failed_password_validation_does_not_consume_recovery(): void
    {
        $this->admin->update(['must_reset_password' => true]);
        $reference = $this->requestOtp();
        $mail = Mail::sent(AdminPasswordRecoveryOtp::class)->first();

        $this->postJson('/api/v1/auth/password-recovery/verify', [
            'reference' => $reference,
            'otp' => '000000',
        ])->assertUnprocessable();

        $this->assertSame(1, AdminPasswordRecoveryRequest::query()->firstOrFail()->failed_attempts);

        $verified = $this->postJson('/api/v1/auth/password-recovery/verify', [
            'reference' => $reference,
            'otp' => $mail->otp,
        ])->assertOk();

        $this->postJson('/api/v1/auth/password-recovery/complete', [
            'reset_token' => $verified->json('data.reset_token'),
            'password' => 'weakpass',
            'password_confirmation' => 'weakpass',
        ])->assertUnprocessable();

        $this->assertNull(AdminPasswordRecoveryRequest::query()->firstOrFail()->consumed_at);
        $this->assertFalse(Hash::check('weakpass', $this->admin->fresh()->password));
        $this->assertTrue($this->admin->fresh()->must_reset_password);
    }

    public function test_otp_verification_stops_after_five_incorrect_attempts(): void
    {
        $reference = $this->requestOtp();
        $mail = Mail::sent(AdminPasswordRecoveryOtp::class)->first();
        $incorrectOtp = $mail->otp === '000000' ? '000001' : '000000';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/password-recovery/verify', [
                'reference' => $reference,
                'otp' => $incorrectOtp,
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/password-recovery/verify', [
            'reference' => $reference,
            'otp' => $mail->otp,
        ])->assertUnprocessable();

        $this->assertSame(5, AdminPasswordRecoveryRequest::query()->firstOrFail()->failed_attempts);
    }

    private function requestOtp(): string
    {
        return $this->postJson('/api/v1/auth/password-recovery/request', [
            'email' => $this->admin->email,
        ])->assertAccepted()->json('data.reference');
    }
}
