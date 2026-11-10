<?php

namespace App\Domain\Identity;

use App\Domain\Enrollment\IssuedFreshmanQueueTicket;
use App\Models\StudentProfile;

/**
 * What Admission just recorded: the student, and — only for a freshman — the queue
 * number issued with them (ADR 0043). `queueTicket->plainToken` is the secret in the
 * emailed live-queue link and exists only on this result.
 */
final readonly class AdmittedStudent
{
    public function __construct(
        public StudentProfile $profile,
        public ?IssuedFreshmanQueueTicket $queueTicket,
    ) {}
}
