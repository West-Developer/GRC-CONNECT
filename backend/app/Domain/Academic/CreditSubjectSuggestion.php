<?php

namespace App\Domain\Academic;

/**
 * One candidate GRC subject for a transferee credit, with how well it matches
 * and why. Computed on demand by `SuggestCreditSubjects` and never stored: a
 * suggestion is advice for the Program Chair, not a mapping.
 */
final readonly class CreditSubjectSuggestion
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public int $subjectId,
        public string $subjectCode,
        public string $subjectTitle,
        public float $units,
        public int $yearLevel,
        public string $semester,
        public float $score,
        public array $reasons,
    ) {}
}
