<?php

namespace App\Domain\Enrollment;

use Carbon\CarbonImmutable;

final readonly class EnrollmentAvailability
{
    public function __construct(
        public bool $isOpen,
        public EnrollmentAvailabilityReason $reason,
        public ?CarbonImmutable $opensAt,
        public ?CarbonImmutable $closesAt,
    ) {}
}
