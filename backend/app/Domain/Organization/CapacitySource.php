<?php

namespace App\Domain\Organization;

/**
 * Which authority owns a section's seat capacity.
 *
 * `Plan` means the section still tracks its year level's
 * `academic_term_section_plans.students_per_block`, so regenerating the
 * plan may update it. `Manual` means a Program Chair deliberately typed a
 * capacity for this one section, and regeneration must leave it alone.
 */
enum CapacitySource: string
{
    case Plan = 'plan';
    case Manual = 'manual';
}
