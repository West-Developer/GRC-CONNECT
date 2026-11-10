<?php

namespace Tests\Feature\Actions\Academic;

use App\Actions\Academic\PromoteEligibleStudents;
use App\Domain\Academic\GradeMark;
use App\Domain\Academic\GradeStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Notification;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Year-level promotion (ADR 0028): a student moves up once every required
 * subject of their current year has a final locked grade. A failed subject
 * does NOT block promotion - it becomes a back subject that the standing
 * classifier picks up - but a missing grade, or an INC/DRP mark that is not
 * final yet, still holds the student back.
 */
final class PromoteEligibleStudentsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A Year-1 student in a curriculum whose Year 1 has two required subjects
     * (one per semester). Returns everything a test needs to grade them.
     *
     * @return array{term: AcademicTerm, student: StudentProfile, sub1: Subject, sub2: Subject, faculty: User}
     */
    private function makeYearOneStudent(string $suffix = '1'): array
    {
        $term = AcademicTerm::firstOrCreate(
            ['school_year' => '2026-2027', 'semester' => '1st'],
            ['status' => AcademicTermStatus::SemesterOngoing],
        );

        $program = Program::create([
            'code' => 'BSIT'.$suffix,
            'name' => 'BS Information Technology '.$suffix,
            'college' => 'ccs',
            'status' => ProgramStatus::Active,
        ]);

        $curriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSIT 2026 '.$suffix,
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);

        $sub1 = Subject::create([
            'code' => 'IT101'.$suffix,
            'title' => 'Intro to IT',
            'units' => 3,
            'college' => 'ccs',
            'status' => 'active',
        ]);
        $sub2 = Subject::create([
            'code' => 'IT102'.$suffix,
            'title' => 'Programming 1',
            'units' => 3,
            'college' => 'ccs',
            'status' => 'active',
        ]);

        CurriculumSubject::create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $sub1->id,
            'year_level' => 1,
            'semester' => '1st',
            'is_required' => true,
        ]);
        CurriculumSubject::create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $sub2->id,
            'year_level' => 1,
            'semester' => '2nd',
            'is_required' => true,
        ]);

        $user = User::create([
            'name' => 'Test Student '.$suffix,
            'email' => "student{$suffix}@grc.edu.ph",
            'password' => 'secret123',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ]);
        $student = StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => '2026-000'.$suffix,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);

        $faculty = User::create([
            'name' => 'Prof Instructor '.$suffix,
            'email' => "faculty{$suffix}@grc.edu.ph",
            'password' => 'secret123',
            'role' => UserRole::Faculty,
            'status' => UserStatus::Active,
        ]);

        return compact('term', 'student', 'sub1', 'sub2', 'faculty');
    }

    private function lockGrade(StudentProfile $student, Subject $subject, AcademicTerm $term, User $encoder, GradeMark $mark): AcademicGrade
    {
        return AcademicGrade::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'academic_term_id' => $term->id,
            'mark' => $mark,
            'final_grade' => $mark->numericValue() === null ? null : $mark->value,
            'status' => GradeStatus::Locked,
            'encoded_by' => $encoder->id,
            'locked_at' => now(),
        ]);
    }

    public function test_promotes_student_who_passed_all_required_subjects_for_their_year_level(): void
    {
        ['term' => $term, 'student' => $student, 'sub1' => $sub1, 'sub2' => $sub2, 'faculty' => $faculty] = $this->makeYearOneStudent();
        $this->lockGrade($student, $sub1, $term, $faculty, GradeMark::Excellent);
        $this->lockGrade($student, $sub2, $term, $faculty, GradeMark::VeryGood);

        $result = app(PromoteEligibleStudents::class)->execute();

        $this->assertSame(1, $result['promoted_count']);
        $this->assertSame(2, $student->fresh()->year_level);
    }

    public function test_promotes_a_student_who_failed_a_subject_so_it_becomes_a_back_subject(): void
    {
        // The stakeholder case (student 2026-06-01067): every Year-1 subject
        // is graded, ETHICS came out 5.00. The student is 2nd Year, not stuck.
        ['term' => $term, 'student' => $student, 'sub1' => $sub1, 'sub2' => $sub2, 'faculty' => $faculty] = $this->makeYearOneStudent();
        $this->lockGrade($student, $sub1, $term, $faculty, GradeMark::Failed);
        $this->lockGrade($student, $sub2, $term, $faculty, GradeMark::Excellent);

        $result = app(PromoteEligibleStudents::class)->execute();

        $this->assertSame(1, $result['promoted_count']);
        $this->assertSame(2, $student->fresh()->year_level);
        $this->assertSame(1, Notification::query()->where('user_id', $student->user_id)->count());
    }

    public function test_a_not_complete_mark_on_a_completion_only_subject_does_not_block_promotion(): void
    {
        ['term' => $term, 'student' => $student, 'sub1' => $sub1, 'sub2' => $sub2, 'faculty' => $faculty] = $this->makeYearOneStudent();
        $this->lockGrade($student, $sub1, $term, $faculty, GradeMark::NotComplete);
        $this->lockGrade($student, $sub2, $term, $faculty, GradeMark::Complete);

        $result = app(PromoteEligibleStudents::class)->execute();

        $this->assertSame(1, $result['promoted_count']);
        $this->assertSame(2, $student->fresh()->year_level);
    }

    public function test_does_not_promote_a_student_with_a_missing_grade(): void
    {
        ['term' => $term, 'student' => $student, 'sub1' => $sub1, 'faculty' => $faculty] = $this->makeYearOneStudent();
        $this->lockGrade($student, $sub1, $term, $faculty, GradeMark::Excellent);
        // sub2 (2nd semester) has no grade yet.

        $result = app(PromoteEligibleStudents::class)->execute();

        $this->assertSame(0, $result['promoted_count']);
        $this->assertSame(1, $student->fresh()->year_level);
    }

    public function test_does_not_promote_while_a_subject_is_incomplete_or_dropped(): void
    {
        ['term' => $term, 'student' => $student, 'sub1' => $sub1, 'sub2' => $sub2, 'faculty' => $faculty] = $this->makeYearOneStudent('1');
        $this->lockGrade($student, $sub1, $term, $faculty, GradeMark::Excellent);
        $this->lockGrade($student, $sub2, $term, $faculty, GradeMark::Incomplete);

        ['term' => $term2, 'student' => $student2, 'sub1' => $b1, 'sub2' => $b2, 'faculty' => $faculty2] = $this->makeYearOneStudent('2');
        $this->lockGrade($student2, $b1, $term2, $faculty2, GradeMark::Excellent);
        $this->lockGrade($student2, $b2, $term2, $faculty2, GradeMark::Dropped);

        $result = app(PromoteEligibleStudents::class)->execute();

        $this->assertSame(0, $result['promoted_count']);
        $this->assertSame(1, $student->fresh()->year_level, 'INC is not a final mark');
        $this->assertSame(1, $student2->fresh()->year_level, 'DRP is not a final mark');
    }

    public function test_an_unlocked_grade_is_not_final(): void
    {
        ['term' => $term, 'student' => $student, 'sub1' => $sub1, 'sub2' => $sub2, 'faculty' => $faculty] = $this->makeYearOneStudent();
        $this->lockGrade($student, $sub1, $term, $faculty, GradeMark::Excellent);
        AcademicGrade::create([
            'student_id' => $student->id,
            'subject_id' => $sub2->id,
            'academic_term_id' => $term->id,
            'mark' => GradeMark::Excellent,
            'status' => GradeStatus::Submitted,
            'encoded_by' => $faculty->id,
        ]);

        $result = app(PromoteEligibleStudents::class)->execute();

        $this->assertSame(0, $result['promoted_count']);
        $this->assertSame(1, $student->fresh()->year_level);
    }

    public function test_a_passing_retake_of_a_failed_subject_counts_as_complete(): void
    {
        ['term' => $term, 'student' => $student, 'sub1' => $sub1, 'sub2' => $sub2, 'faculty' => $faculty] = $this->makeYearOneStudent();
        $laterTerm = AcademicTerm::create([
            'school_year' => '2026-2027',
            'semester' => '2nd',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $this->lockGrade($student, $sub1, $term, $faculty, GradeMark::Failed);
        $this->lockGrade($student, $sub1, $laterTerm, $faculty, GradeMark::Good);
        $this->lockGrade($student, $sub2, $laterTerm, $faculty, GradeMark::Good);

        $result = app(PromoteEligibleStudents::class)->execute();

        $this->assertSame(1, $result['promoted_count']);
        $this->assertSame(2, $student->fresh()->year_level);
    }

    public function test_promote_student_moves_one_student_and_is_idempotent(): void
    {
        ['term' => $term, 'student' => $student, 'sub1' => $sub1, 'sub2' => $sub2, 'faculty' => $faculty] = $this->makeYearOneStudent();
        $this->lockGrade($student, $sub1, $term, $faculty, GradeMark::Failed);
        $this->lockGrade($student, $sub2, $term, $faculty, GradeMark::Good);
        $promoter = app(PromoteEligibleStudents::class);

        $first = $promoter->promoteStudent($student);
        $second = $promoter->promoteStudent($student);

        $this->assertNotNull($first);
        $this->assertSame(1, $first['old_year_level']);
        $this->assertSame(2, $first['new_year_level']);
        $this->assertSame(2, $student->year_level, 'the in-memory model is kept in step for the reclassifier that runs next');
        $this->assertNull($second, 'Year 2 has no placements, so a second call must not promote again');
        $this->assertSame(2, $student->fresh()->year_level);
        $this->assertSame(1, Notification::query()->where('user_id', $student->user_id)->count());
    }

    public function test_dry_run_reports_without_writing(): void
    {
        ['term' => $term, 'student' => $student, 'sub1' => $sub1, 'sub2' => $sub2, 'faculty' => $faculty] = $this->makeYearOneStudent();
        $this->lockGrade($student, $sub1, $term, $faculty, GradeMark::Failed);
        $this->lockGrade($student, $sub2, $term, $faculty, GradeMark::Good);

        $result = app(PromoteEligibleStudents::class)->execute(dryRun: true);

        $this->assertSame(1, $result['promoted_count']);
        $this->assertSame(1, $student->fresh()->year_level);
        $this->assertSame(0, Notification::query()->count());
    }
}
