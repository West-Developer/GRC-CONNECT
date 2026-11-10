<?php

namespace App\Domain\Enrollment;

use App\Domain\Scheduling\ScheduleDayParser;
use App\Models\StudentSchedulePreference;

/**
 * Rates how well a candidate enrollment option's sections fit a student's
 * saved `StudentSchedulePreference` — used to annotate (never filter,
 * reorder, or gate) an `EnrollmentBlock` or `EligibleSubjectEntry` with a
 * `preference_score`/`preference_reasons` pair. This class itself never
 * gates; a single narrow, explicit exception lives in the frontend instead
 * (Stakeholder Doc 17, `eligible-subject-table.tsx`'s `evaluateScheduleFit`
 * check), which disables an irregular student's manual section choice when
 * it would exceed a 1-2 day / single time-block schedule — but only when a
 * same-subject alternative that fits still exists, so a student is never
 * left with zero way to complete their schedule. Pure and persistence-free:
 * the caller resolves both arguments before calling, the same shape as
 * `SectionConflictDetector`/`PrerequisiteCycleDetector`.
 *
 * `$sections` intentionally reuses `SectionConflictDetector::hasConflict()`'s
 * plain-array shape (`schedule_days`, `starts_at_time`) rather than
 * requiring a `Section` model, plus a `modality` key for this scorer's own
 * needs — one row per `Section`, not per day-of-week occurrence.
 *
 * Five independently-scored, all-or-nothing components sum to 100. Each is
 * skipped entirely (no points, no reason) when the student left that
 * dimension unset, or when the candidate has no data to judge it against —
 * an unscheduled candidate is neither rewarded nor penalized for schedule
 * fit:
 *
 *   - day overlap with `preferred_days` ......................... 35
 *   - time-block match (see boundaries below) ................... 30
 *   - distinct days on campus at/under `max_days_on_campus` ...... 20
 *   - no class before 08:00 when `avoid_early_first_class` ....... 10
 *   - modality match ................................................ 5
 *
 * Time-block boundaries are not specified anywhere else in the codebase
 * (`PreferredTimeBlock` itself only labels the cases) — chosen here using
 * the common academic-scheduling convention of a 12:00–17:00 afternoon
 * window:
 *   - morning:   before 12:00
 *   - afternoon: 12:00 (inclusive) to before 17:00
 *   - evening:   17:00 (inclusive) onward
 *
 * "Time-block match" and "no early class" both require *every* section in
 * the candidate to satisfy the rule — one 7 a.m. class among otherwise
 * agreeable sections still means the student's day starts early.
 */
final class SchedulePreferenceScorer
{
    private const EARLY_CUTOFF = '08:00:00';

    private const AFTERNOON_START = '12:00:00';

    private const EVENING_START = '17:00:00';

    /**
     * @param  iterable<array{schedule_days: ?string, starts_at_time: ?string, modality?: ?string}>  $sections
     * @return array{score: ?int, reasons: list<string>}
     */
    public static function score(?StudentSchedulePreference $preference, iterable $sections): array
    {
        if ($preference === null) {
            return ['score' => null, 'reasons' => []];
        }

        $parser = new ScheduleDayParser;

        /** @var array<int, true> $occupiedDays */
        $occupiedDays = [];
        /** @var list<string> $startTimes */
        $startTimes = [];
        /** @var list<string> $modalities */
        $modalities = [];

        foreach ($sections as $section) {
            foreach ($parser->parse($section['schedule_days'] ?? null) as $day) {
                $occupiedDays[$day] = true;
            }

            $startTime = $section['starts_at_time'] ?? null;
            if ($startTime !== null) {
                $startTimes[] = $startTime;
            }

            $modality = $section['modality'] ?? null;
            if ($modality !== null) {
                $modalities[] = $modality;
            }
        }

        $distinctDays = array_keys($occupiedDays);

        $score = 0;
        $reasons = [];

        [$dayPoints, $dayReason] = self::scoreDayOverlap($preference, $distinctDays);
        $score += $dayPoints;
        if ($dayReason !== null) {
            $reasons[] = $dayReason;
        }

        [$timeBlockPoints, $timeBlockReason] = self::scoreTimeBlock($preference, $startTimes);
        $score += $timeBlockPoints;
        if ($timeBlockReason !== null) {
            $reasons[] = $timeBlockReason;
        }

        [$campusPoints, $campusReason] = self::scoreDaysOnCampus($preference, $distinctDays);
        $score += $campusPoints;
        if ($campusReason !== null) {
            $reasons[] = $campusReason;
        }

        [$earlyPoints, $earlyReason] = self::scoreAvoidEarlyClass($preference, $startTimes);
        $score += $earlyPoints;
        if ($earlyReason !== null) {
            $reasons[] = $earlyReason;
        }

        [$modalityPoints, $modalityReason] = self::scoreModality($preference, $modalities);
        $score += $modalityPoints;
        if ($modalityReason !== null) {
            $reasons[] = $modalityReason;
        }

        return ['score' => $score, 'reasons' => $reasons];
    }

    /**
     * @param  list<int>  $distinctDays
     * @return array{0: int, 1: ?string}
     */
    private static function scoreDayOverlap(StudentSchedulePreference $preference, array $distinctDays): array
    {
        $preferredDays = $preference->preferred_days ?? [];

        if ($preferredDays === [] || $distinctDays === []) {
            return [0, null];
        }

        $overlap = array_intersect($distinctDays, $preferredDays);

        if ($overlap === []) {
            return [0, null];
        }

        return [35, sprintf('Meets on %d of your preferred day(s)', count($overlap))];
    }

    /**
     * @param  list<string>  $startTimes
     * @return array{0: int, 1: ?string}
     */
    private static function scoreTimeBlock(StudentSchedulePreference $preference, array $startTimes): array
    {
        $timeBlock = $preference->preferred_time_block ?? PreferredTimeBlock::Any;

        if ($startTimes === [] || $timeBlock === PreferredTimeBlock::Any) {
            return [0, null];
        }

        foreach ($startTimes as $startTime) {
            if (! self::withinBlock($startTime, $timeBlock)) {
                return [0, null];
            }
        }

        return [30, sprintf('Falls within your preferred %s time block', $timeBlock->label())];
    }

    /**
     * @param  list<int>  $distinctDays
     * @return array{0: int, 1: ?string}
     */
    private static function scoreDaysOnCampus(StudentSchedulePreference $preference, array $distinctDays): array
    {
        $maxDays = $preference->max_days_on_campus;

        if ($maxDays === null || $distinctDays === []) {
            return [0, null];
        }

        $dayCount = count($distinctDays);

        if ($dayCount > $maxDays) {
            return [0, null];
        }

        return [20, sprintf('Meets on %d day(s), within your %d-day campus limit', $dayCount, $maxDays)];
    }

    /**
     * @param  list<string>  $startTimes
     * @return array{0: int, 1: ?string}
     */
    private static function scoreAvoidEarlyClass(StudentSchedulePreference $preference, array $startTimes): array
    {
        if (! $preference->avoid_early_first_class || $startTimes === []) {
            return [0, null];
        }

        foreach ($startTimes as $startTime) {
            if ($startTime < self::EARLY_CUTOFF) {
                return [0, null];
            }
        }

        return [10, 'No class before 8:00 AM'];
    }

    /**
     * @param  list<string>  $modalities
     * @return array{0: int, 1: ?string}
     */
    private static function scoreModality(StudentSchedulePreference $preference, array $modalities): array
    {
        $preferredModality = $preference->preferred_modality;

        if ($preferredModality === null || $modalities === []) {
            return [0, null];
        }

        foreach ($modalities as $modality) {
            if ($modality !== $preferredModality) {
                return [0, null];
            }
        }

        return [5, 'Offered in your preferred modality'];
    }

    private static function withinBlock(string $startTime, PreferredTimeBlock $block): bool
    {
        return match ($block) {
            PreferredTimeBlock::Morning => $startTime < self::AFTERNOON_START,
            PreferredTimeBlock::Afternoon => $startTime >= self::AFTERNOON_START && $startTime < self::EVENING_START,
            PreferredTimeBlock::Evening => $startTime >= self::EVENING_START,
            PreferredTimeBlock::Any => true,
        };
    }
}
