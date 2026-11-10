<?php

namespace App\Domain\Enrollment;

final readonly class AudienceAvailability
{
    public function __construct(
        public EnrollmentAudience $audience,
        public EnrollmentAvailability $availability,
    ) {}
}
