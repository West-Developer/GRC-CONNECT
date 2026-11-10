<?php

namespace App\Domain\Enrollment;

use App\Models\Section;

/**
 * One block a regular student can enrol into as a unit: every section that
 * shares the block's section code, generated together by the Program
 * Chair's section plan for the student's year level and curriculum.
 *
 * `seatsRemaining` is the MIN across every section in the block — the
 * binding constraint, since enrolling in the block means enrolling in
 * every subject at once. A block with one full section is not selectable
 * even if its other six sections have plenty of seats.
 *
 * `preferenceScore`/`preferenceReasons` are purely informational —
 * `SchedulePreferenceScorer::score()`'s rating of this block's sections
 * against the caller's `StudentSchedulePreference`. They never affect
 * `isSelectable`, `seatsRemaining`, or block membership; `preferenceScore`
 * is `null` when the student has no saved preference.
 */
final readonly class EnrollmentBlock
{
    /**
     * @param  list<Section>  $sections
     * @param  list<array{code: string, message: string}>  $reasons
     * @param  list<string>  $preferenceReasons
     */
    public function __construct(
        public string $blockCode,
        public int $yearLevel,
        public int $curriculumId,
        public ?int $sectionPlanId,
        public array $sections,
        public float $totalUnits,
        public int $seatsRemaining,
        public bool $isSelectable,
        public array $reasons,
        public ?int $preferenceScore = null,
        public array $preferenceReasons = [],
    ) {}
}
