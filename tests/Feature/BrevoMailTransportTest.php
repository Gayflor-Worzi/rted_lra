<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Feature\Concerns\SystemAdministratorFixture;
use Tests\TestCase;

class BrevoMailTransportTest extends TestCase
{
    use RefreshDatabase;
    use SystemAdministratorFixture;

    private const API_URL = 'https://api.brevo.com/v3/smtp/email';

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        config([
            'mail.default' => 'brevo',
            'mail.from.address' => 'verified-sender@example.test',
            'mail.from.name' => 'RETD Test',
            'services.brevo.api_key' => 'test-brevo-api-key',
        ]);
        Mail::purge('brevo');
        Http::preventStrayRequests();

        $this->admin = $this->systemAdministratorFixture();
    }

    public function test_admin_otp_uses_brevo_https_api_and_verified_configured_sender(): void
    {
        Http::fake([
            self::API_URL => Http::response(['messageId' => '<test-message-id>'], 201),
        ]);

        $this->postJson('/api/v1/auth/password-recovery/request', [
            'email' => $this->admin->email,
        ])->assertAccepted();

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === self::API_URL
                && $request->method() === 'POST'
                && $request->hasHeader('api-key', 'test-brevo-api-key')
                && $payload['sender'] === [
                    'email' => 'verified-sender@example.test',
                    'name' => 'RETD Test',
                ]
                && $payload['to'][0]['email'] === $this->admin->email
                && $payload['subject'] === 'RETD administrator password recovery'
                && isset($payload['htmlContent'])
                && ! str_contains(json_encode($payload), 'test-brevo-api-key');
        });
    }

    public function test_brevo_api_rejection_returns_unavailable_without_exposing_provider_response(): void
    {
        Log::spy();
        Http::fake([
            self::API_URL => Http::response(['message' => 'test-brevo-api-key rejected'], 401),
        ]);

        $response = $this->postJson('/api/v1/auth/password-recovery/request', [
            'email' => $this->admin->email,
        ])->assertStatus(503)
            ->assertJsonMissingPath('data.reference');

        $this->assertStringNotContainsString('test-brevo-api-key', $response->getContent());
        $this->assertStringNotContainsString('rejected', $response->getContent());
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Administrator password recovery email transport failed.'
                && $context['exception'] === TransportException::class
                && ! array_key_exists('otp', $context),
        );
        Http::assertSentCount(1);
    }

    public function test_brevo_network_failure_returns_unavailable_without_leaking_connection_details(): void
    {
        Log::spy();
        Http::fake([
            self::API_URL => Http::failedConnection('test-brevo-api-key must not be logged'),
        ]);

        $response = $this->postJson('/api/v1/auth/password-recovery/request', [
            'email' => $this->admin->email,
        ])->assertStatus(503)
            ->assertJsonMissingPath('data.reference');

        $this->assertStringNotContainsString('test-brevo-api-key', $response->getContent());
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Administrator password recovery email transport failed.'
                && $context['exception'] === TransportException::class
                && ! array_key_exists('otp', $context),
        );
        Http::assertSentCount(1);
    }

    public function test_missing_brevo_api_key_fails_closed_without_making_an_http_request(): void
    {
        config(['services.brevo.api_key' => null]);
        Mail::purge('brevo');

        $this->postJson('/api/v1/auth/password-recovery/request', [
            'email' => $this->admin->email,
        ])->assertStatus(503)
            ->assertJsonMissingPath('data.reference');

        Http::assertNothingSent();
    }
}
