<?php

namespace App\Domain\Identity;

use App\Domain\Organization\CollegeCode;
use InvalidArgumentException;

final class ActingContext
{
    public function __construct(
        public readonly UserRole $role,
        public readonly ?CollegeCode $college = null,
    ) {}

    public static function fromRequest(UserRole $role, ?CollegeCode $college): self
    {
        if (! in_array($role, UserRole::superAdminSwitchableCases(), true)) {
            throw new InvalidArgumentException("Role {$role->value} is not switchable by a Super Admin.");
        }

        if (UserRole::collegeRequiredWhenActing($role)) {
            if ($college === null) {
                throw new InvalidArgumentException("Acting as {$role->label()} requires a college.");
            }
        } else {
            if ($college !== null) {
                throw new InvalidArgumentException("Acting as {$role->label()} does not allow a college.");
            }
        }

        return new self($role, $college);
    }

    public function label(): string
    {
        if ($this->college !== null) {
            return "{$this->role->label()} (" . strtoupper($this->college->value) . ")";
        }

        return $this->role->label();
    }
}
