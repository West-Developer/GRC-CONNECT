<?php

namespace App\Mail;

use App\Domain\Identity\UserRole;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class StaffAccountSetupMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly UserRole $role,
        public readonly string $setupUrl,
        public readonly string $setupCode,
        public readonly string $staffEmail = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Set up your GRC '.$this->role->label().' account');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.staff-account-setup', with: [
            'roleLabel' => $this->role->label(),
        ]);
    }
}
