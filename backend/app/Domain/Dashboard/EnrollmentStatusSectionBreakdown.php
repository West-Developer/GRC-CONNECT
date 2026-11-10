<?php

namespace App\Domain\Dashboard;

/**
 * The sections of one department, each with its students grouped by
 * `EnrollmentStatusGroup`. A student belongs to exactly one section: the
 * section code covering most of their current enrollment's subjects (ties
 * broken by section code), so section totals add up to the department total.
 * A `null` section code is the "No section yet" row.
 */
final readonly class EnrollmentStatusSectionBreakdown
{
    /**
     * @param  list<array{section_code: ?string, total: int, groups: array<string, int>}>  $sections
     */
    public function __construct(
        public int $academicTermId,
        public ?string $department,
        public array $sections,
    ) {}
}
