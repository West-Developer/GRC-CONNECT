<?php

namespace App\Domain\Academic;

final readonly class CreditMappingStatusResult
{
    public function __construct(
        public bool $isCompleted,
        public bool $requiresCreditMapping,
        public ?string $reason = null,
        public int $totalCredits = 0,
        public int $pendingCredits = 0,
        public int $endorsedCredits = 0,
        public int $approvedCredits = 0,
        public int $rejectedCredits = 0,
        public bool $hasCurriculumMigration = false,
    ) {}
}
