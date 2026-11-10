<?php

namespace App\Actions\Scheduling;

use App\Domain\Scheduling\LectureLabAdjacency;
use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\User;

/**
 * The lecture/lab pairs a Program Head's sections leave apart in time for one
 * term (see `LectureLabAdjacency`). Read-only review information: it never
 * blocks a save or a submission, matching how incomplete assignments are
 * treated (`SaveSectionPlan::submit`).
 */
final readonly class ListLectureLabAdjacencyViolations
{
    public function __construct(private LectureLabAdjacency $rule) {}

    /**
     * @return list<array{section_code: string, first: array{section_id: int, subject_code: string}, second: array{section_id: int, subject_code: string}, reason: string, days: list<string>, message: string}>
     */
    public function execute(AcademicTerm $term, User $actor): array
    {
        $rows = Section::query()
            ->visibleTo($actor)
            ->where('academic_term_id', $term->id)
            ->with('subject')
            ->get()
            ->map(fn (Section $section): array => [
                'section_id' => $section->id,
                'section_code' => $section->section_code,
                'subject_id' => $section->subject_id,
                'subject_code' => $section->subject->code,
                'paired_subject_id' => $section->subject->paired_subject_id,
                'schedule_days' => $section->schedule_days,
                'starts_at_time' => $section->starts_at_time,
                'ends_at_time' => $section->ends_at_time,
            ])
            ->all();

        return array_map(
            fn (array $violation): array => [
                ...$violation,
                'message' => self::message($violation),
            ],
            $this->rule->violations(array_values($rows)),
        );
    }

    /**
     * @param  array{section_code: string, first: array{section_id: int, subject_code: string}, second: array{section_id: int, subject_code: string}, reason: string, days: list<string>}  $violation
     */
    private static function message(array $violation): string
    {
        $pair = sprintf('%s and %s (section %s)', $violation['first']['subject_code'], $violation['second']['subject_code'], $violation['section_code']);

        return $violation['reason'] === LectureLabAdjacency::NO_SHARED_DAY
            ? "{$pair} meet on different days; the lecture and laboratory should be back-to-back."
            : sprintf('%s are not back-to-back on %s.', $pair, implode(', ', $violation['days']));
    }
}
