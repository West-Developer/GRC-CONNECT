<?php

namespace Tests\Unit\Domain\Scheduling;

use App\Domain\Scheduling\LectureLabAdjacency;
use App\Domain\Scheduling\ScheduleDayParser;
use PHPUnit\Framework\TestCase;

final class LectureLabAdjacencyTest extends TestCase
{
    private function rule(): LectureLabAdjacency
    {
        return new LectureLabAdjacency(new ScheduleDayParser);
    }

    /**
     * @return array{section_id: int, section_code: string, subject_id: int, subject_code: string, paired_subject_id: ?int, schedule_days: ?string, starts_at_time: ?string, ends_at_time: ?string}
     */
    private function row(int $sectionId, string $subjectCode, int $subjectId, ?int $pairedWith, ?string $days, ?string $start, ?string $end, string $sectionCode = 'A'): array
    {
        return [
            'section_id' => $sectionId,
            'section_code' => $sectionCode,
            'subject_id' => $subjectId,
            'subject_code' => $subjectCode,
            'paired_subject_id' => $pairedWith,
            'schedule_days' => $days,
            'starts_at_time' => $start,
            'ends_at_time' => $end,
        ];
    }

    public function test_a_lecture_and_lab_back_to_back_on_the_same_days_are_fine(): void
    {
        $violations = $this->rule()->violations([
            $this->row(1, 'IT101', 10, 11, 'MW', '08:00:00', '09:30:00'),
            $this->row(2, 'IT101L', 11, 10, 'MW', '09:30:00', '11:00:00'),
        ]);

        $this->assertSame([], $violations);
    }

    public function test_the_lab_may_come_first(): void
    {
        $violations = $this->rule()->violations([
            $this->row(1, 'IT101', 10, 11, 'TTh', '10:00:00', '11:30:00'),
            $this->row(2, 'IT101L', 11, 10, 'TTh', '08:30:00', '10:00:00'),
        ]);

        $this->assertSame([], $violations);
    }

    public function test_a_gap_between_them_is_reported_with_the_days(): void
    {
        $violations = $this->rule()->violations([
            $this->row(1, 'IT101', 10, 11, 'MW', '08:00:00', '09:30:00'),
            $this->row(2, 'IT101L', 11, 10, 'MW', '10:00:00', '11:30:00'),
        ]);

        $this->assertCount(1, $violations);
        $this->assertSame(LectureLabAdjacency::NOT_BACK_TO_BACK, $violations[0]['reason']);
        $this->assertSame(['Mon', 'Wed'], $violations[0]['days']);
        $this->assertSame('A', $violations[0]['section_code']);
        $this->assertSame(['section_id' => 1, 'subject_code' => 'IT101'], $violations[0]['first']);
        $this->assertSame(['section_id' => 2, 'subject_code' => 'IT101L'], $violations[0]['second']);
    }

    public function test_overlapping_times_are_not_back_to_back_either(): void
    {
        $violations = $this->rule()->violations([
            $this->row(1, 'IT101', 10, 11, 'MW', '08:00:00', '09:30:00'),
            $this->row(2, 'IT101L', 11, 10, 'MW', '09:00:00', '10:30:00'),
        ]);

        $this->assertCount(1, $violations);
    }

    public function test_meeting_on_different_days_is_reported(): void
    {
        $violations = $this->rule()->violations([
            $this->row(1, 'IT101', 10, 11, 'MW', '08:00:00', '09:30:00'),
            $this->row(2, 'IT101L', 11, 10, 'TTh', '08:00:00', '09:30:00'),
        ]);

        $this->assertCount(1, $violations);
        $this->assertSame(LectureLabAdjacency::NO_SHARED_DAY, $violations[0]['reason']);
        $this->assertSame([], $violations[0]['days']);
    }

    public function test_every_shared_day_has_to_be_back_to_back(): void
    {
        // Lecture MWF at 8, lab on M only right after: Monday is fine, but
        // the lab and lecture also share nothing else, so it passes; a lab on
        // MW where only Monday touches must be reported for Wednesday alone.
        $violations = $this->rule()->violations([
            $this->row(1, 'IT101', 10, 11, 'MW', '08:00:00', '09:30:00'),
            $this->row(2, 'IT101L', 11, 10, 'MW', '09:30:00', '11:00:00'),
            $this->row(3, 'CS201', 20, 21, 'MW', '08:00:00', '09:00:00', 'B'),
            $this->row(4, 'CS201L', 21, 20, 'MW', '13:00:00', '14:00:00', 'B'),
        ]);

        $this->assertCount(1, $violations);
        $this->assertSame('B', $violations[0]['section_code']);
    }

    public function test_an_unscheduled_section_is_not_judged(): void
    {
        $violations = $this->rule()->violations([
            $this->row(1, 'IT101', 10, 11, 'MW', '08:00:00', '09:30:00'),
            $this->row(2, 'IT101L', 11, 10, null, null, null),
        ]);

        $this->assertSame([], $violations);
    }

    public function test_sections_with_different_section_codes_are_not_a_pair(): void
    {
        $violations = $this->rule()->violations([
            $this->row(1, 'IT101', 10, 11, 'MW', '08:00:00', '09:30:00', 'A'),
            $this->row(2, 'IT101L', 11, 10, 'TTh', '13:00:00', '14:30:00', 'B'),
        ]);

        $this->assertSame([], $violations);
    }

    public function test_a_subject_without_a_pair_is_ignored(): void
    {
        $violations = $this->rule()->violations([
            $this->row(1, 'GE101', 30, null, 'MW', '08:00:00', '09:30:00'),
            $this->row(2, 'GE102', 31, null, 'MW', '13:00:00', '14:30:00'),
        ]);

        $this->assertSame([], $violations);
    }

    public function test_seconds_do_not_matter_when_comparing_times(): void
    {
        $violations = $this->rule()->violations([
            $this->row(1, 'IT101', 10, 11, 'MW', '08:00', '09:30'),
            $this->row(2, 'IT101L', 11, 10, 'MW', '09:30:00', '11:00:00'),
        ]);

        $this->assertSame([], $violations);
    }

    public function test_each_pair_is_reported_once_even_though_both_subjects_name_the_other(): void
    {
        $violations = $this->rule()->violations([
            $this->row(2, 'IT101L', 11, 10, 'MW', '13:00:00', '14:00:00'),
            $this->row(1, 'IT101', 10, 11, 'MW', '08:00:00', '09:30:00'),
        ]);

        $this->assertCount(1, $violations);
        $this->assertSame('IT101', $violations[0]['first']['subject_code']);
    }
}
