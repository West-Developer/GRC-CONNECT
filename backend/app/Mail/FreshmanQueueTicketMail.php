<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a freshman right after Admission records them, in place of the
 * account-setup email (which now goes out only after they pay): their queue
 * number for the Cashier and a link to a live view of the queue.
 */
final class FreshmanQueueTicketMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $studentName,
        public readonly string $ticketNumber,
        public readonly string $liveUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your GRC queue number is {$this->ticketNumber}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.freshman-queue-ticket');
    }
}
