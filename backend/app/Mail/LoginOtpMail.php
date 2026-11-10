<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class LoginOtpMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your GRC sign-in verification code');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.login-otp');
    }
}
