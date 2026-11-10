<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class EnrollmentConfirmedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $snapshot  the same COR snapshot `ConfirmPayment` already built and
     *                                          stored on the `EnrollmentDocument` — no data is recomputed here.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $documentNumber,
        public readonly array $snapshot,
        public readonly string $portalUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your GRC enrollment is confirmed');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.enrollment-confirmed');
    }
}
