<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a transferee when their credit mapping is done: their queue number for
 * the Cashier and the no-login live-queue link. Same layout as the freshman's
 * (`mail.freshman-queue-ticket`), with the text that fits a transferee, who already
 * has an account and still has to choose their subjects before paying.
 */
final class TransfereeQueueTicketMail extends Mailable
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
        return new Content(
            view: 'mail.freshman-queue-ticket',
            with: [
                'introText' => 'Your credits from your previous school have been evaluated. Sign in and choose your subjects on the Enrollment page, then go to the Cashier for your first payment. Your queue number is:',
                'noteText' => 'Your enrollment goes to the Registrar for approval after the Cashier confirms your payment, and you will get another email once you are enrolled. Keep this link to yourself. If you did not apply to GRC, you can safely ignore this email.',
                'previewText' => "Your queue number at the Cashier is {$this->ticketNumber}. Choose your subjects, then pay at the Cashier.",
            ],
        );
    }
}
