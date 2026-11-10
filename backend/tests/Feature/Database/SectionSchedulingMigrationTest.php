<?php

namespace Tests\Feature\Database;

use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SectionSchedulingMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sections_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('sections'));
        $this->assertTrue(Schema::hasColumns('sections', [
            'id', 'academic_term_id', 'subject_id', 'section_code', 'professor_id',
            'schedule_days', 'starts_at_time', 'ends_at_time', 'room', 'capacity',
            'viability_threshold', 'enrolled_count', 'status', 'created_at', 'updated_at',
        ]));
    }

    public function test_the_same_section_code_cannot_repeat_for_one_subject_in_one_term(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject('CS101');

        Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'section_code' => 'A',
            'capacity' => 40,
            'status' => SectionStatus::Planned,
        ]);

        $this->expectException(QueryException::class);

        Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'section_code' => 'A',
            'capacity' => 35,
            'status' => SectionStatus::Planned,
        ]);
    }

    public function test_the_same_section_code_is_allowed_for_a_different_subject(): void
    {
        $term = $this->makeTerm();

        Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $this->makeSubject('CS101')->id,
            'section_code' => 'A',
            'capacity' => 40,
            'status' => SectionStatus::Planned,
        ]);

        $second = Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $this->makeSubject('CS102')->id,
            'section_code' => 'A',
            'capacity' => 40,
            'status' => SectionStatus::Planned,
        ]);

        $this->assertNotNull($second->id);
    }

    public function test_deleting_the_assigned_professor_nulls_the_section_rather_than_deleting_it(): void
    {
        $professor = User::create([
            'name' => 'Test Faculty',
            'email' => 'faculty.'.uniqid().'@grc.test',
            'password' => 'irrelevant-password',
            'role' => UserRole::Faculty,
            'status' => UserStatus::Active,
        ]);

        $section = Section::create([
            'academic_term_id' => $this->makeTerm()->id,
            'subject_id' => $this->makeSubject('CS101')->id,
            'section_code' => 'A',
            'professor_id' => $professor->id,
            'capacity' => 40,
            'status' => SectionStatus::Planned,
        ]);

        $professor->delete();

        $this->assertNull($section->refresh()->professor_id);
        $this->assertDatabaseHas('sections', ['id' => $section->id]);
    }

    public function test_a_subject_with_a_section_cannot_be_deleted(): void
    {
        $subject = $this->makeSubject('CS101');

        Section::create([
            'academic_term_id' => $this->makeTerm()->id,
            'subject_id' => $subject->id,
            'section_code' => 'A',
            'capacity' => 40,
            'status' => SectionStatus::Planned,
        ]);

        $this->expectException(QueryException::class);

        $subject->delete();
    }

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2026-2027',
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
    }

    private function makeSubject(string $code): Subject
    {
        return Subject::create([
            'code' => $code,
            'title' => 'Test Subject '.$code,
            'units' => 3,
            'status' => SubjectStatus::Active,
        ]);
    }
}
