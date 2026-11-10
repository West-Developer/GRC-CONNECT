<?php

namespace App\Domain\Identity;

enum UserStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
