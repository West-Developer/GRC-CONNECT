<?php

namespace Tests\Unit\Mail;

use App\Support\Mail\EnsureExternalMailTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

final class EnsureExternalMailTransportTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function localTransports(): array
    {
        return ['array' => ['array'], 'log' => ['log'], 'failover' => ['failover'], 'roundrobin' => ['roundrobin']];
    }

    #[DataProvider('localTransports')]
    public function test_production_cannot_report_local_mail_sinks_as_external_delivery(string $transport): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('mail.default', 'student-mail');
        config()->set('mail.mailers.student-mail', ['transport' => $transport, 'mailers' => ['smtp', 'log']]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Email delivery is disabled for this production mailer. Configure an external email provider.');

        (new EnsureExternalMailTransport)->check();
    }

    public function test_local_and_testing_mail_fakes_remain_available(): void
    {
        config()->set('mail.default', 'array');
        foreach (['local', 'testing'] as $environment) {
            $this->app->detectEnvironment(fn (): string => $environment);
            (new EnsureExternalMailTransport)->check();
        }

        self::assertTrue(true);
    }

    public function test_production_can_use_external_transports_and_external_only_failover(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('mail.mailers.external-failover', ['transport' => 'failover', 'mailers' => ['smtp', 'resend_http']]);
        foreach (['smtp', 'resend_http', 'external-failover'] as $mailer) {
            config()->set('mail.default', $mailer);
            (new EnsureExternalMailTransport)->check();
        }

        self::assertTrue(true);
    }
}
