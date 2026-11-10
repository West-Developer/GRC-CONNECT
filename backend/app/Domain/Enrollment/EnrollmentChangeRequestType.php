<?php

namespace App\Domain\Enrollment;

enum EnrollmentChangeRequestType: string
{
    case Add = 'add';
    case Drop = 'drop';
    case ChangeSection = 'change_section';

    public function label(): string
    {
        return match ($this) {
            self::Add => 'Add subject',
            self::Drop => 'Drop subject',
            self::ChangeSection => 'Change subject',
        };
    }

    public function requiresFromSection(): bool
    {
        return $this !== self::Add;
    }

    public function requiresToSection(): bool
    {
        return $this !== self::Drop;
    }
}
