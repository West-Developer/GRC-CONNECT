<?php

namespace Tests\Unit\Mail;

use App\Mail\Transports\ResendHttpTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

final class ResendHttpTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_it_preserves_message_content_and_recipient_privacy_over_https(): void
    {
        Http::fake(['https://api.resend.com/emails' => Http::response(['id' => 'email-123'])]);
        $email = $this->email()
            ->cc(new Address('cc@grc.test', 'Office'))
            ->bcc('private@grc.test')
            ->replyTo('reply@grc.test')
            ->attach('attachment bytes', 'record.txt', 'text/plain')
            ->addPart((new DataPart('image bytes', 'logo.png', 'image/png'))->asInline()->setContentId('logo@grc.test'));
        $email->getHeaders()->addTextHeader('X-GRC-Message', 'account-setup');

        $sent = (new ResendHttpTransport('fake-provider-key'))->send($email);

        self::assertSame('email-123', $sent?->getMessageId());
        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://api.resend.com/emails'
                && $request->hasHeader('Authorization', 'Bearer fake-provider-key')
                && $payload['from'] === (new Address('sender@grc.test', 'GRC Enrollment'))->toString()
                && $payload['to'] === [(new Address('student@grc.test', 'Student'))->toString()]
                && $payload['cc'] === [(new Address('cc@grc.test', 'Office'))->toString()]
                && $payload['bcc'] === ['private@grc.test']
                && $payload['reply_to'] === ['reply@grc.test']
                && $payload['subject'] === 'Set up your account'
                && $payload['html'] === '<p>Your code is 123456.</p>'
                && $payload['text'] === 'Your code is 123456.'
                && $payload['headers']['X-GRC-Message'] === 'account-setup'
                && ! isset($payload['headers']['Bcc'])
                && ! isset($payload['headers']['To'])
                && $payload['attachments'][0]['filename'] === 'record.txt'
                && $payload['attachments'][0]['content'] === base64_encode('attachment bytes')
                && $payload['attachments'][1]['content_id'] === 'logo@grc.test'
                && $payload['attachments'][1]['content'] === base64_encode('image bytes');
        });
        Http::assertSentCount(1);
    }

    public function test_it_uses_envelope_recipients_when_the_caller_overrides_the_destination(): void
    {
        Http::fake(['https://api.resend.com/emails' => Http::response(['id' => 'redirected'])]);
        $email = $this->email()->cc('old-cc@grc.test')->bcc('old-bcc@grc.test');
        $envelope = new Envelope(new Address('sender@grc.test'), [new Address('override@grc.test')]);

        (new ResendHttpTransport('fake-provider-key'))->send($email, $envelope);

        Http::assertSent(fn (Request $request): bool => $request['to'] === ['override@grc.test']
            && ! isset($request['cc']) && ! isset($request['bcc']));
    }

    public function test_provider_rejections_are_failures_without_logging_codes_addresses_or_api_keys(): void
    {
        Http::fake(['https://api.resend.com/emails' => Http::response([
            'message' => 'fake-provider-key student@grc.test code 123456',
        ], 422)]);

        try {
            (new ResendHttpTransport('fake-provider-key'))->send($this->email());
            self::fail('A rejected message must fail delivery.');
        } catch (TransportException $exception) {
            self::assertSame('Email provider rejected the message (HTTP 422).', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            foreach (['fake-provider-key', 'student@grc.test', '123456'] as $privateValue) {
                self::assertStringNotContainsString($privateValue, (string) $exception);
            }
        }

        // No blind retry after an ambiguous email submission.
        Http::assertSentCount(1);
    }

    public function test_connection_failures_do_not_expose_the_original_http_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('fake-provider-key student@grc.test code 123456'));

        try {
            (new ResendHttpTransport('fake-provider-key'))->send($this->email());
            self::fail('An unavailable provider must fail delivery.');
        } catch (TransportException $exception) {
            self::assertSame('Email provider could not be reached. Check the email service configuration and connection.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            foreach (['fake-provider-key', 'student@grc.test', '123456'] as $privateValue) {
                self::assertStringNotContainsString($privateValue, (string) $exception);
            }
        }
    }

    public function test_a_success_response_without_a_message_id_is_not_reported_as_sent(): void
    {
        Http::fake(['https://api.resend.com/emails' => Http::response(['id' => null])]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Email provider returned an invalid delivery response.');

        (new ResendHttpTransport('fake-provider-key'))->send($this->email());
    }

    public function test_a_missing_key_fails_before_contacting_the_provider(): void
    {
        Http::fake();

        try {
            (new ResendHttpTransport(''))->send($this->email());
            self::fail('A missing provider credential must fail delivery.');
        } catch (TransportException $exception) {
            self::assertSame('Email service is not configured. Set RESEND_KEY for the resend_http mailer.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_the_registered_laravel_mailer_uses_the_https_transport(): void
    {
        config()->set('services.resend.key', 'fake-provider-key');
        config()->set('mail.from', ['address' => 'sender@grc.test', 'name' => 'GRC Enrollment']);
        Http::fake(['https://api.resend.com/emails' => Http::response(['id' => 'laravel-mail'])]);

        $sent = Mail::mailer('resend_http')->html('<p>Setup link</p>', function ($message): void {
            $message->to('student@grc.test')->subject('Account setup');
        });

        self::assertSame('laravel-mail', $sent?->getMessageId());
        Http::assertSent(fn (Request $request): bool => $request['subject'] === 'Account setup'
            && $request['html'] === '<p>Setup link</p>');
    }

    public function test_the_existing_array_mailer_remains_local_only(): void
    {
        Http::fake();
        Mail::mailer('array')->html('<p>Local only</p>', function ($message): void {
            $message->to('student@grc.test')->subject('Local test');
        });

        Http::assertNothingSent();
    }

    public function test_raw_messages_are_rejected_before_the_provider_is_contacted(): void
    {
        Http::fake();

        try {
            (new ResendHttpTransport('fake-provider-key'))->send(
                new RawMessage('Unstructured contents'),
                new Envelope(new Address('sender@grc.test'), [new Address('student@grc.test')]),
            );
            self::fail('The provider needs structured email fields.');
        } catch (TransportException $exception) {
            self::assertSame('Email transport requires a structured email message.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    private function email(): Email
    {
        return (new Email)
            ->from(new Address('sender@grc.test', 'GRC Enrollment'))
            ->to(new Address('student@grc.test', 'Student'))
            ->subject('Set up your account')
            ->html('<p>Your code is 123456.</p>')
            ->text('Your code is 123456.');
    }
}
