<?php

namespace App\Domain\Scheduling;

/**
 * The lecture and the laboratory of one subject pair, in the same section, must
 * sit next to each other in time (stakeholder Doc 14: "Lecture and lab always
 * magkatabi"). "Next to each other" means: they share at least one day, and on
 * EVERY shared day one starts exactly when the other ends.
 *
 * Pure and persistence-free, like `SectionConflictDetector`. The pairing comes
 * from `subjects.paired_subject_id` (symmetric: each row names the other). A
 * pair is only judged when BOTH sections are fully scheduled; a missing day or
 * time is an incomplete assignment, which the schedule already reports as
 * review information, not an adjacency problem.
 *
 * Existing data will not all comply. This class only reports; nothing here
 * moves a section.
 */
final class LectureLabAdjacency
{
    public const NO_SHARED_DAY = 'no_shared_day';

    public const NOT_BACK_TO_BACK = 'not_back_to_back';

    public function __construct(private readonly ScheduleDayParser $dayParser) {}

    /**
     * @param  list<array{section_id: int, section_code: string, subject_id: int, subject_code: string, paired_subject_id: ?int, schedule_days: ?string, starts_at_time: ?string, ends_at_time: ?string}>  $sections
     * @return list<array{section_code: string, first: array{section_id: int, subject_code: string}, second: array{section_id: int, subject_code: string}, reason: string, days: list<string>}>
     */
    public function violations(array $sections): array
    {
        $bySectionCode = [];
        foreach ($sections as $section) {
            $bySectionCode[$section['section_code']][] = $section;
        }

        $violations = [];

        foreach ($bySectionCode as $sectionCode => $group) {
            foreach ($group as $first) {
                foreach ($group as $second) {
                    // Each pair once: the lower subject id is "first".
                    if ($first['paired_subject_id'] !== $second['subject_id'] || $first['subject_id'] >= $second['subject_id']) {
                        continue;
                    }

                    $violation = $this->judge($first, $second);

                    if ($violation !== null) {
                        $violations[] = [
                            'section_code' => (string) $sectionCode,
                            'first' => ['section_id' => $first['section_id'], 'subject_code' => $first['subject_code']],
                            'second' => ['section_id' => $second['section_id'], 'subject_code' => $second['subject_code']],
                            'reason' => $violation['reason'],
                            'days' => $violation['days'],
                        ];
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array{schedule_days: ?string, starts_at_time: ?string, ends_at_time: ?string}  $first
     * @param  array{schedule_days: ?string, starts_at_time: ?string, ends_at_time: ?string}  $second
     * @return array{reason: string, days: list<string>}|null
     */
    private function judge(array $first, array $second): ?array
    {
        foreach ([$first, $second] as $slot) {
            if ($slot['schedule_days'] === null || $slot['starts_at_time'] === null || $slot['ends_at_time'] === null) {
                return null;
            }
        }

        $shared = array_values(array_intersect(
            $this->dayParser->parse($first['schedule_days']),
            $this->dayParser->parse($second['schedule_days']),
        ));

        if ($shared === []) {
            return ['reason' => self::NO_SHARED_DAY, 'days' => []];
        }

        $apart = $this->touches($first, $second) ? [] : $shared;

        return $apart === [] ? null : ['reason' => self::NOT_BACK_TO_BACK, 'days' => array_map($this->dayName(...), $apart)];
    }

    /**
     * One ends exactly when the other starts, in either order.
     *
     * @param  array{starts_at_time: ?string, ends_at_time: ?string}  $first
     * @param  array{starts_at_time: ?string, ends_at_time: ?string}  $second
     */
    private function touches(array $first, array $second): bool
    {
        return $this->minute($first['ends_at_time']) === $this->minute($second['starts_at_time'])
            || $this->minute($second['ends_at_time']) === $this->minute($first['starts_at_time']);
    }

    /** "08:30:00" and "08:30" are the same moment. */
    private function minute(?string $time): string
    {
        return substr((string) $time, 0, 5);
    }

    private function dayName(int $isoDay): string
    {
        return match ($isoDay) {
            1 => 'Mon',
            2 => 'Tue',
            3 => 'Wed',
            4 => 'Thu',
            5 => 'Fri',
            6 => 'Sat',
            default => 'Sun',
        };
    }
}
