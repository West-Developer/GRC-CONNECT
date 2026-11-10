<?php

namespace App\Domain\Scheduling;

/**
 * Applies the physical-week room rule without treating a HyFlex online week
 * as a room booking. Complementary HyFlex A/B patterns may share a room and
 * time; any F2F overlap or matching HyFlex pattern may not.
 */
final class RoomConflictDetector
{
    public function __construct(private readonly ScheduleDayParser $dayParser) {}

    /**
     * @param  array{schedule_days: ?string, starts_at_time: ?string, ends_at_time: ?string, modality: ?string}  $proposed
     * @param  list<array{schedule_days: ?string, starts_at_time: ?string, ends_at_time: ?string, modality: ?string}>  $existing
     */
    public function hasConflict(array $proposed, array $existing): bool
    {
        if (! $this->isSchedulable($proposed) || ! $this->usesPhysicalWeek($proposed['modality'])) {
            return false;
        }

        $proposedDays = $this->dayParser->parse($proposed['schedule_days']);
        foreach ($existing as $slot) {
            if (! $this->isSchedulable($slot) || ! $this->usesPhysicalWeek($slot['modality'])) {
                continue;
            }

            if (
                array_intersect($proposedDays, $this->dayParser->parse($slot['schedule_days'])) !== []
                && $this->timesOverlap($proposed, $slot)
                && ! $this->hasComplementaryHyflexPattern($proposed['modality'], $slot['modality'])
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param array{schedule_days: ?string, starts_at_time: ?string, ends_at_time: ?string, modality: ?string} $slot */
    private function isSchedulable(array $slot): bool
    {
        return $slot['schedule_days'] !== null
            && $slot['starts_at_time'] !== null
            && $slot['ends_at_time'] !== null;
    }

    private function usesPhysicalWeek(?string $modality): bool
    {
        return in_array($modality, [
            SectionModality::FaceToFace->value,
            SectionModality::HyflexA->value,
            SectionModality::HyflexB->value,
        ], true);
    }

    private function hasComplementaryHyflexPattern(?string $first, ?string $second): bool
    {
        return ($first === SectionModality::HyflexA->value && $second === SectionModality::HyflexB->value)
            || ($first === SectionModality::HyflexB->value && $second === SectionModality::HyflexA->value);
    }

    /**
     * @param  array{starts_at_time: ?string, ends_at_time: ?string}  $first
     * @param  array{starts_at_time: ?string, ends_at_time: ?string}  $second
     */
    private function timesOverlap(array $first, array $second): bool
    {
        return $first['starts_at_time'] < $second['ends_at_time']
            && $second['starts_at_time'] < $first['ends_at_time'];
    }
}
