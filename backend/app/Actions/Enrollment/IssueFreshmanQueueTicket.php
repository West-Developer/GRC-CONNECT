<?php

namespace App\Actions\Enrollment;

use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\IssuedFreshmanQueueTicket;
use App\Models\QueueTicket;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Gives a freshman their queue number at Admission, before they have paid or
 * can log in. They carry no enrollment yet, so the ticket belongs to their
 * student profile (`unique(student_profile_id)` makes a repeat call return the
 * same ticket) and draws from the same open cycle and `Q%03d` sequence as
 * every other ticket (`AllocateQueueTicket`).
 *
 * A fresh, unguessable token is generated for the "view my queue number" link
 * and only its SHA-256 is stored — the plain token is returned once, in the
 * result, for the email. If the ticket already existed no token is returned;
 * `RotateFreshmanQueueToken` is how the link is sent again.
 */
final readonly class IssueFreshmanQueueTicket
{
    public function __construct(private AllocateQueueTicket $allocator) {}

    public function execute(
        StudentProfile $profile,
        User $actor,
        AuditRequestContext $context,
        string $source = 'freshman_admission',
    ): IssuedFreshmanQueueTicket {
        $plainToken = Str::random(48);

        $ticket = $this->allocator->execute(
            [
                'student_profile_id' => $profile->id,
                'public_token_hash' => QueueTicket::hashPublicToken($plainToken),
            ],
            fn (): ?QueueTicket => QueueTicket::query()->where('student_profile_id', $profile->id)->first(),
            $actor,
            $context,
            guard: function () use ($profile): void {
                if (! $profile->isAwaitingFirstPayment()) {
                    throw ValidationException::withMessages([
                        'student' => 'Only a freshman or transferee who has not paid yet can be given a queue number.',
                    ]);
                }
            },
            auditExtras: ['student_profile_id' => $profile->id, 'source' => $source],
        );

        // `wasRecentlyCreated` is what tells a brand-new ticket (whose token we
        // just generated) from one that already existed (whose token is unknown).
        return new IssuedFreshmanQueueTicket($ticket, $ticket->wasRecentlyCreated ? $plainToken : null);
    }
}
