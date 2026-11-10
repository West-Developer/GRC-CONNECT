<?php

namespace App\Actions\Enrollment;

use App\Models\QueueTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Replaces the secret in a freshman's "view my queue number" link so it can be
 * sent again (only the hash is stored, so the old link cannot be recovered).
 * The previous link stops working at once. Refused once the freshman's first
 * payment is confirmed: the ticket is done and the link has no further use.
 * Returns the new plain token for the email.
 */
final readonly class RotateFreshmanQueueToken
{
    public function execute(QueueTicket $ticket): string
    {
        return DB::transaction(function () use ($ticket): string {
            $locked = QueueTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $profile = $locked->studentProfile;

            if ($profile === null || ! $profile->isFreshmanAwaitingPayment()) {
                throw ValidationException::withMessages([
                    'student' => 'A queue link can only be re-sent to a freshman who has not paid yet.',
                ]);
            }

            $plainToken = Str::random(48);
            $locked->forceFill(['public_token_hash' => QueueTicket::hashPublicToken($plainToken)])->save();

            return $plainToken;
        });
    }
}
