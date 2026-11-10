<?php

namespace Tests\Feature\Database;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FR-ENR-011's schema-only mechanism: see the migration's own docblock.
 * These columns are nullable with no default, so the migration reversibility
 * test in CurriculumCatalogMigrationTest (a full rollback/re-migrate of the
 * whole schema) already exercises this migration's down(); these tests cover
 * only the columns' existence and their required nullability.
 */
final class BlockSectionEligibilityMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sections_table_has_the_nullable_block_exclusive_column(): void
    {
        $this->assertTrue(Schema::hasColumn('sections', 'is_block_exclusive'));

        $term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $subject = Subject::create(['code' => 'CS101', 'title' => 'Intro to Programming', 'units' => 3, 'status' => SubjectStatus::Active]);

        $section = Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'section_code' => 'A',
            'capacity' => 40,
            'status' => SectionStatus::Planned,
        ]);

        self::assertNull($section->is_block_exclusive);
    }

    public function test_student_profiles_table_has_the_nullable_enrollment_category_column(): void
    {
        $this->assertTrue(Schema::hasColumn('student_profiles', 'enrollment_category'));

        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
        $user = User::create([
            'name' => 'Student', 'email' => 'block-mechanism@grc.test',
            'password' => 'correct-horse-battery-staple', 'role' => UserRole::Student, 'status' => UserStatus::Active,
        ]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);

        $profile = StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => '2026-0001',
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);

        self::assertNull($profile->enrollment_category);
    }
}
