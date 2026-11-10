<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class StudentAccountSetupMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $studentName,
        public readonly string $setupUrl,
        public readonly string $setupCode,
        public readonly string $studentEmail = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Set up your GRC student account');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.student-account-setup');
    }
}
