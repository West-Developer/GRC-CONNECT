<?php

namespace App\Domain\Enrollment;

/**
 * Holds the queue tickets a request issued, with the plain tokens of their
 * "view my queue number" links, until the controller has committed and can email
 * them. The closing actions run inside a transaction and an email cannot be rolled
 * back, so they only record the ticket here; `SendQueueLinks` sends after commit.
 * The token exists only on the call that created the ticket, so it travels here.
 */
final class QueueLinkOutbox
{
    /** @var list<IssuedFreshmanQueueTicket> */
    private array $issued = [];

    public function add(IssuedFreshmanQueueTicket $issued): void
    {
        $this->issued[] = $issued;
    }

    /**
     * Everything collected so far, emptying the outbox so nothing is sent twice.
     *
     * @return list<IssuedFreshmanQueueTicket>
     */
    public function drain(): array
    {
        $issued = $this->issued;
        $this->issued = [];

        return $issued;
    }
}
