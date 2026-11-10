<?php

namespace App\Domain\Identity;

final class QueueKioskAccess
{
    public const TOKEN_ABILITY = 'queue-kiosk:claim';

    public const TOKEN_HEADER = 'X-Queue-Kiosk-Token';
}
