<?php

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/**
 * Raised when a super admin's browser tab sends an `X-Acting-Context` header
 * that no longer matches what the current bearer token actually holds — e.g.
 * another tab switched offices after this tab last synced. Stops a stale tab
 * from silently acting (and being audited) as an office it isn't showing;
 * the tab must resync via `GET /auth/me` first (see ADR 0038, Design spec §B).
 */
final class ActingContextChangedException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'Your admin workspace changed in another tab. Refreshing your session.',
        );
    }
}
