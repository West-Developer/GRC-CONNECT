<?php

namespace App\Domain\Scheduling;

use App\Domain\Identity\FacultyEmploymentType;

/**
 * Which maximum applies to a professor, and why (ADR 0033). Highest wins:
 * the professor's own override, then the limit for their employment type, then
 * the college-wide default, then no limit. No number is invented for the last
 * case: an unlimited professor is never flagged as overloaded.
 */
final class EffectiveFacultyLoadLimit
{
    public const SOURCE_OVERRIDE = 'override';

    public const SOURCE_EMPLOYMENT_TYPE = 'employment_type';

    public const SOURCE_COLLEGE_DEFAULT = 'college_default';

    /**
     * @param  array<string, float>  $limitsByType  employment type value => maximum units
     * @return array{max_units: ?float, source: ?string}
     */
    public static function resolve(
        ?FacultyEmploymentType $employmentType,
        array $limitsByType,
        ?float $collegeDefault,
        ?float $override,
    ): array {
        if ($override !== null) {
            return ['max_units' => $override, 'source' => self::SOURCE_OVERRIDE];
        }

        if ($employmentType !== null && isset($limitsByType[$employmentType->value])) {
            return ['max_units' => $limitsByType[$employmentType->value], 'source' => self::SOURCE_EMPLOYMENT_TYPE];
        }

        if ($collegeDefault !== null) {
            return ['max_units' => $collegeDefault, 'source' => self::SOURCE_COLLEGE_DEFAULT];
        }

        return ['max_units' => null, 'source' => null];
    }
}
