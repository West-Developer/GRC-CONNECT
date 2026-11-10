<?php

namespace Tests\Unit\Models;

use App\Domain\Curriculum\SubjectStatus;
use App\Models\Subject;
use PHPUnit\Framework\TestCase;

final class SubjectTest extends TestCase
{
    public function test_status_attribute_uses_the_canonical_enum_cast(): void
    {
        $subject = new Subject;
        $subject->forceFill([
            'code' => 'CS101',
            'title' => 'Intro to Programming',
            'units' => 3,
            'status' => 'active',
        ]);

        self::assertSame(SubjectStatus::Active, $subject->status);
        self::assertSame(3.0, $subject->units);
    }

    public function test_is_lecture_component_prefers_the_persisted_room_requirement(): void
    {
        $lecture = new Subject;
        $lecture->forceFill([
            'code' => 'PROG1', 'title' => 'Computer Programming 1 LEC', 'units' => 3,
            'status' => 'active', 'paired_subject_id' => 2, 'room_requirement' => 'lecture',
        ]);
        $lab = new Subject;
        $lab->forceFill([
            'code' => 'PROG1L', 'title' => 'Computer Programming 1 LAB', 'units' => 1,
            'status' => 'active', 'paired_subject_id' => 1, 'room_requirement' => 'laboratory',
        ]);

        self::assertTrue($lecture->isLectureComponent());
        self::assertFalse($lab->isLectureComponent());
    }

    public function test_is_lecture_component_falls_back_to_the_title_suffix_when_room_requirement_is_null(): void
    {
        $lecture = new Subject;
        $lecture->forceFill([
            'code' => 'PROG1', 'title' => 'Computer Programming 1 LEC', 'units' => 3,
            'status' => 'active', 'paired_subject_id' => 2, 'room_requirement' => null,
        ]);
        $lab = new Subject;
        $lab->forceFill([
            'code' => 'PROG1L', 'title' => 'Computer Programming 1 LAB', 'units' => 1,
            'status' => 'active', 'paired_subject_id' => 1, 'room_requirement' => null,
        ]);

        self::assertTrue($lecture->isLectureComponent());
        self::assertFalse($lab->isLectureComponent());
    }

    public function test_is_lecture_component_is_false_for_an_unpaired_subject(): void
    {
        $subject = new Subject;
        $subject->forceFill([
            'code' => 'ITPLUS3', 'title' => 'IT Elective 3 LAB', 'units' => 1,
            'status' => 'active', 'paired_subject_id' => null, 'room_requirement' => 'laboratory',
        ]);

        self::assertFalse($subject->isLectureComponent());
    }
}
