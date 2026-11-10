<?php

namespace Tests\Unit\Domain\Enrollment;

use App\Domain\Enrollment\ProfessorVisibility;
use App\Domain\Organization\AcademicTermStatus;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class ProfessorVisibilityTest extends TestCase
{
    private function at(string $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment, 'UTC');
    }

    public function test_the_professor_is_hidden_while_enrollment_is_still_open(): void
    {
        $this->assertTrue(ProfessorVisibility::hiddenFromStudents(
            AcademicTermStatus::SemesterOngoing,
            $this->at('2026-08-20 00:00'),
            $this->at('2026-09-05 00:00'),
            $this->at('2026-08-10 00:00'),
        ));
    }

    public function test_the_professor_stays_hidden_through_the_add_drop_window(): void
    {
        $this->assertTrue(ProfessorVisibility::hiddenFromStudents(
            AcademicTermStatus::SemesterOngoing,
            $this->at('2026-08-20 00:00'),
            $this->at('2026-09-05 00:00'),
            $this->at('2026-08-30 00:00'),
        ));
    }

    public function test_the_professor_is_revealed_once_the_later_deadline_has_passed(): void
    {
        $this->assertFalse(ProfessorVisibility::hiddenFromStudents(
            AcademicTermStatus::SemesterOngoing,
            $this->at('2026-08-20 00:00'),
            $this->at('2026-09-05 00:00'),
            $this->at('2026-09-05 00:00:01'),
        ));
    }

    public function test_the_later_of_the_two_dates_decides_whichever_column_holds_it(): void
    {
        // An add/drop deadline earlier than the enrollment close (unusual, but
        // the later date still rules).
        $this->assertTrue(ProfessorVisibility::hiddenFromStudents(
            AcademicTermStatus::SemesterOngoing,
            $this->at('2026-09-10 00:00'),
            $this->at('2026-09-01 00:00'),
            $this->at('2026-09-05 00:00'),
        ));
    }

    public function test_a_single_configured_date_is_enough(): void
    {
        $this->assertFalse(ProfessorVisibility::hiddenFromStudents(
            AcademicTermStatus::SemesterOngoing,
            null,
            $this->at('2026-09-05 00:00'),
            $this->at('2026-09-06 00:00'),
        ));
        $this->assertTrue(ProfessorVisibility::hiddenFromStudents(
            AcademicTermStatus::SemesterOngoing,
            $this->at('2026-09-05 00:00'),
            null,
            $this->at('2026-09-04 00:00'),
        ));
    }

    public function test_with_no_dates_the_professor_stays_hidden_until_the_term_closes(): void
    {
        foreach ([AcademicTermStatus::Draft, AcademicTermStatus::ForDeanApproval, AcademicTermStatus::SemesterOngoing] as $status) {
            $this->assertTrue(ProfessorVisibility::hiddenFromStudents($status, null, null, $this->at('2026-09-01 00:00')));
        }
    }

    public function test_a_closed_or_archived_term_always_shows_the_professor(): void
    {
        foreach ([AcademicTermStatus::SemesterClosed, AcademicTermStatus::Archived] as $status) {
            $this->assertFalse(ProfessorVisibility::hiddenFromStudents(
                $status,
                $this->at('2099-01-01 00:00'),
                $this->at('2099-02-01 00:00'),
                $this->at('2026-09-01 00:00'),
            ));
        }
    }
}
