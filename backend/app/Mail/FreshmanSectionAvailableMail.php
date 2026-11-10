<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a freshman whose payment is held (every section was full) when a section
 * opens for their program and year (ADR 0043).
 */
final class FreshmanSectionAvailableMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $studentName,
        public readonly string $blockLabel,
        public readonly string $enrollmentUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'A section is now open for you');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.freshman-section-available');
    }
}
