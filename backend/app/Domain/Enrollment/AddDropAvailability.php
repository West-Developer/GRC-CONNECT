<?php

namespace App\Domain\Enrollment;

use Carbon\CarbonImmutable;

final readonly class AddDropAvailability
{
    public function __construct(
        public bool $isOpen,
        public AddDropAvailabilityReason $reason,
        public ?CarbonImmutable $opensAt,
        public ?CarbonImmutable $closesAt,
    ) {}
}
