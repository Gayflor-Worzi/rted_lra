<?php

namespace App\Mail\Transport;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class BrevoApiTransport extends AbstractTransport
{
    private const API_URL = 'https://api.brevo.com/v3/smtp/email';

    public function __construct(
        private readonly ?string $apiKey,
        private readonly ?string $configuredSender,
        private readonly ?string $configuredSenderName,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if (! $this->apiKey) {
            throw new TransportException('Brevo API credentials are not configured.');
        }

        $email = $message->getOriginalMessage();
        if (! $email instanceof Email) {
            throw new TransportException('Brevo transport requires an email message.');
        }

        $senderAddress = $email->getFrom()[0] ?? $message->getEnvelope()->getSender();
        $sender = [
            'email' => $senderAddress->getAddress() ?: $this->configuredSender,
            'name' => $senderAddress->getName() ?: $this->configuredSenderName,
        ];
        if (! $sender['email']) {
            throw new TransportException('Brevo sender address is not configured.');
        }

        $payload = [
            'sender' => array_filter($sender, static fn (?string $value): bool => $value !== null && $value !== ''),
            'to' => $this->addresses($email->getTo()),
            'subject' => $email->getSubject() ?? '',
        ];

        $cc = $this->addresses($email->getCc());
        $bcc = $this->addresses($email->getBcc());
        if ($cc) {
            $payload['cc'] = $cc;
        }
        if ($bcc) {
            $payload['bcc'] = $bcc;
        }

        $text = $this->bodyAsString($email->getTextBody());
        $html = $this->bodyAsString($email->getHtmlBody());
        if ($text !== null) {
            $payload['textContent'] = $text;
        }
        if ($html !== null) {
            $payload['htmlContent'] = $html;
        }
        if ($text === null && $html === null) {
            throw new TransportException('Brevo email content is empty.');
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['api-key' => $this->apiKey])
                ->connectTimeout(5)
                ->timeout(15)
                ->post(self::API_URL, $payload);
        } catch (ConnectionException) {
            throw new TransportException('Brevo API connection failed.');
        }

        if (! $response->successful()) {
            throw new TransportException('Brevo API rejected the email request with HTTP '.$response->status().'.');
        }
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, array{email: string, name?: string}>
     */
    private function addresses(array $addresses): array
    {
        return array_map(static function (Address $address): array {
            $recipient = ['email' => $address->getAddress()];
            if ($address->getName() !== '') {
                $recipient['name'] = $address->getName();
            }

            return $recipient;
        }, $addresses);
    }

    /**
     * @param  resource|string|null  $body
     */
    private function bodyAsString(mixed $body): ?string
    {
        if (is_resource($body)) {
            $contents = stream_get_contents($body);

            return $contents === false ? null : $contents;
        }

        return $body;
    }

    public function __toString(): string
    {
        return 'brevo';
    }
}
