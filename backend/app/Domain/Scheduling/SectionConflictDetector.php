<?php

namespace App\Domain\Scheduling;

/**
 * Detects a professor schedule conflict (FR-SCH-005): the same professor
 * assigned to two sections, in the same term, whose declared days share at
 * least one day and whose time ranges overlap. Pure and persistence-free —
 * the caller assembles the professor's other sections in the term before
 * calling this class, the same shape as PrerequisiteCycleDetector.
 *
 * Deliberately narrow: only professor double-booking is checked. Room
 * conflicts and faculty-availability matching are not — nothing in the
 * found schema or seed data evidences either as a hard rule, and inventing
 * one would repeat the mistake PRD §17 flags elsewhere.
 */
final class SectionConflictDetector
{
    public function __construct(private readonly ScheduleDayParser $dayParser) {}

    /**
     * @param  array{schedule_days: ?string, starts_at_time: ?string, ends_at_time: ?string}  $proposed
     * @param  list<array{schedule_days: ?string, starts_at_time: ?string, ends_at_time: ?string}>  $existing
     */
    public function hasConflict(array $proposed, array $existing): bool
    {
        if (! $this->isSchedulable($proposed)) {
            return false;
        }

        $proposedDays = $this->dayParser->parse($proposed['schedule_days']);

        foreach ($existing as $slot) {
            if (! $this->isSchedulable($slot)) {
                continue;
            }

            $sharedDays = array_intersect($proposedDays, $this->dayParser->parse($slot['schedule_days']));

            if ($sharedDays !== [] && $this->timesOverlap($proposed, $slot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{schedule_days: ?string, starts_at_time: ?string, ends_at_time: ?string}  $slot
     */
    private function isSchedulable(array $slot): bool
    {
        return $slot['schedule_days'] !== null
            && $slot['starts_at_time'] !== null
            && $slot['ends_at_time'] !== null;
    }

    /**
     * Half-open interval overlap: [start, end) vs [start, end). Two slots
     * that merely touch at the boundary (one ends exactly when the next
     * starts) do not conflict.
     *
     * @param  array{starts_at_time: ?string, ends_at_time: ?string}  $a
     * @param  array{starts_at_time: ?string, ends_at_time: ?string}  $b
     */
    private function timesOverlap(array $a, array $b): bool
    {
        return $a['starts_at_time'] < $b['ends_at_time'] && $b['starts_at_time'] < $a['ends_at_time'];
    }
}
