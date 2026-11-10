<?php

namespace App\Domain\Enrollment;

use App\Domain\Organization\AcademicTermStatus;
use Carbon\CarbonImmutable;

final readonly class EnrollmentScheduleSummary
{
    /**
     * @param  list<AudienceAvailability>  $audiences  One entry per `EnrollmentAudience::cases()`, in that order.
     */
    public function __construct(
        public int $academicTermId,
        public AcademicTermStatus $status,
        public ?CarbonImmutable $termOpensAt,
        public ?CarbonImmutable $termClosesAt,
        public array $audiences,
        public ?AudienceAvailability $viewer,
        public AddDropAvailability $addDrop,
    ) {}
}
