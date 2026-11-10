<?php

namespace Tests\Unit\Models;

use App\Domain\Academic\GradeStatus;
use App\Models\AcademicGrade;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class AcademicGradeTest extends TestCase
{
    public function test_status_and_timestamps_use_their_canonical_casts(): void
    {
        $grade = new AcademicGrade;
        $grade->forceFill([
            'student_id' => 1,
            'subject_id' => 1,
            'academic_term_id' => 1,
            'final_grade' => '2.00',
            'status' => 'locked',
            'encoded_by' => 1,
            'locked_at' => '2026-08-05 09:00:00',
        ]);

        self::assertSame(GradeStatus::Locked, $grade->status);
        self::assertInstanceOf(CarbonImmutable::class, $grade->locked_at);
    }

    public function test_final_grade_deliberately_stays_a_raw_string_not_a_float(): void
    {
        $grade = new AcademicGrade;
        $grade->forceFill([
            'student_id' => 1,
            'subject_id' => 1,
            'academic_term_id' => 1,
            'final_grade' => '2.00',
            'status' => 'draft',
            'encoded_by' => 1,
        ]);

        self::assertIsString($grade->final_grade);
        self::assertSame('2.00', $grade->final_grade);
    }
}
