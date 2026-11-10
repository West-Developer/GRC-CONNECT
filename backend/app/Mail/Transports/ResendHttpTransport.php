<?php

namespace App\Mail\Transports;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

/** Sends existing Laravel mail through HTTPS when hosting blocks SMTP (Render Free). */
final class ResendHttpTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $key,
        private readonly int $timeout = 15,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'resend_http';
    }

    protected function doSend(SentMessage $message): void
    {
        if (trim($this->key) === '') {
            throw new TransportException('Email service is not configured. Set RESEND_KEY for the resend_http mailer.');
        }

        $original = $message->getOriginalMessage();
        if (! $original instanceof Message) {
            throw new TransportException('Email transport requires a structured email message.');
        }
        $email = MessageConverter::toEmail($original);
        $payload = $this->payload($email, $message->getEnvelope());

        try {
            // Do not follow redirects with account contents or retry an ambiguous send.
            $response = Http::withToken($this->key)
                ->acceptJson()
                ->asJson()
                ->withoutRedirecting()
                ->connectTimeout(5)
                ->timeout(max(1, min($this->timeout, 60)))
                ->post('https://api.resend.com/emails', $payload);
        } catch (Throwable) {
            // A previous HTTP exception can contain headers, recipient data and one-time
            // codes. Callers report transport failures, so never chain it into their logs.
            throw new TransportException('Email provider could not be reached. Check the email service configuration and connection.');
        }

        if (! $response->successful()) {
            throw new TransportException('Email provider rejected the message (HTTP '.$response->status().').');
        }

        $id = $response->json('id');
        if (! is_string($id) || trim($id) === '') {
            throw new TransportException('Email provider returned an invalid delivery response.');
        }

        $message->setMessageId($id);
    }

    /** @return array<string, mixed> */
    private function payload(Email $email, Envelope $envelope): array
    {
        $headers = [];
        $reserved = ['from', 'to', 'cc', 'bcc', 'reply-to', 'sender', 'subject', 'content-type', 'content-transfer-encoding', 'mime-version'];
        foreach ($email->getHeaders()->all() as $name => $header) {
            if (! in_array($name, $reserved, true)) {
                $headers[$header->getName()] = $header->getBodyAsString();
            }
        }

        $attachments = [];
        foreach ($email->getAttachments() as $attachment) {
            $item = [
                'filename' => $attachment->getFilename() ?? 'attachment',
                'content_type' => $attachment->getContentType(),
                'content' => base64_encode($attachment->getBody()),
            ];
            if ($attachment->getDisposition() === 'inline') {
                $item['content_id'] = $attachment->getContentId();
            }
            $attachments[] = $item;
        }

        return array_filter([
            'from' => ($email->getFrom()[0] ?? $envelope->getSender())->toString(),
            ...$this->recipients($email, $envelope),
            'reply_to' => $this->stringifyAddresses($email->getReplyTo()),
            'subject' => $email->getSubject(),
            'html' => $email->getHtmlBody(),
            'text' => $email->getTextBody(),
            'headers' => $headers,
            'attachments' => $attachments,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * Honor an overridden envelope without leaking Bcc into To/custom headers.
     * Symfony's envelope drops display names, so recover names only for recipients
     * the envelope still permits.
     *
     * @return array{to: list<string>, cc: list<string>, bcc: list<string>}
     */
    private function recipients(Email $email, Envelope $envelope): array
    {
        $names = [];
        $groups = [];
        foreach (['to' => $email->getTo(), 'cc' => $email->getCc(), 'bcc' => $email->getBcc()] as $group => $addresses) {
            foreach ($addresses as $address) {
                $names[$address->getAddress()] = $address;
                $groups[$address->getAddress()] = $group;
            }
        }

        $recipients = ['to' => [], 'cc' => [], 'bcc' => []];
        foreach ($envelope->getRecipients() as $recipient) {
            $address = $recipient->getAddress();
            $group = $groups[$address] ?? 'to';
            $recipients[$group][] = ($names[$address] ?? $recipient)->toString();
        }

        return $recipients;
    }
}
