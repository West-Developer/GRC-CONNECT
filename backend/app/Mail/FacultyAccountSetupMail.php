<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class FacultyAccountSetupMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $setupUrl,
        public readonly string $setupCode,
        public readonly string $facultyEmail = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Set up your GRC faculty account');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.faculty-account-setup');
    }
}
