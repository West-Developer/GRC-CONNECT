<?php

namespace App\Domain\Academic;

/**
 * The outcome of evaluating one prerequisite edge, carrying the
 * human-readable reason FR-ENR-005 requires alongside it.
 */
final readonly class PrerequisiteVerdict
{
    private function __construct(
        public PrerequisiteVerdictStatus $status,
        public string $reason,
    ) {}

    public static function satisfied(string $reason): self
    {
        return new self(PrerequisiteVerdictStatus::Satisfied, $reason);
    }

    public static function notSatisfied(string $reason): self
    {
        return new self(PrerequisiteVerdictStatus::NotSatisfied, $reason);
    }

    public static function needsVerification(string $reason): self
    {
        return new self(PrerequisiteVerdictStatus::NeedsVerification, $reason);
    }

    public function isSatisfied(): bool
    {
        return $this->status === PrerequisiteVerdictStatus::Satisfied;
    }
}
