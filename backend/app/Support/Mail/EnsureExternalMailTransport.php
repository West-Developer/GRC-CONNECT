<?php

namespace App\Support\Mail;

use Symfony\Component\Mailer\Exception\TransportException;

/** Local mail sinks must not count as delivered invitations on the deployed system. */
final class EnsureExternalMailTransport
{
    public function check(): void
    {
        if (app()->isProduction() && $this->usesLocalSink((string) config('mail.default'), [])) {
            throw new TransportException('Email delivery is disabled for this production mailer. Configure an external email provider.');
        }
    }

    /** @param list<string> $visited */
    private function usesLocalSink(string $mailer, array $visited): bool
    {
        // A cyclic failover configuration cannot establish external delivery either.
        if (in_array($mailer, $visited, true)) {
            return true;
        }

        $config = config('mail.mailers.'.$mailer, []);
        $transport = $config['transport'] ?? $mailer;
        if (in_array($transport, ['array', 'log'], true)) {
            return true;
        }

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            foreach ($config['mailers'] ?? [] as $child) {
                if (is_string($child) && $this->usesLocalSink($child, [...$visited, $mailer])) {
                    return true;
                }
            }
        }

        return false;
    }
}
