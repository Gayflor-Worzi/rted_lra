<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminPasswordRecoveryOtp extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $otp,
        public readonly string $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'RETD administrator password recovery');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-password-recovery-otp',
            with: [
                'otp' => $this->otp,
                'expiresAt' => $this->expiresAt,
            ],
        );
    }
}
