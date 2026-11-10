<?php

namespace Tests\Feature\Actions\Enrollment;

use App\Actions\Enrollment\SubmitEnrollment;
use App\Domain\Audit\AuditRequestContext;
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
use App\Models\AcademicTermSectionPlan;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Covers `SubmitEnrollment`'s in-transaction seat lock (Phase 6): the
 * Form Request's pool-based check is a fast pre-check, not authoritative,
 * so this exercises the write path directly, past that pre-check, to prove
 * the transaction itself refuses to oversell.
 */
final class SubmitEnrollmentCapacityTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
    }

    private function makeCurriculum(): Curriculum
    {
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);

        return Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
    }

    private function makeStudent(Curriculum $curriculum, string $email): StudentProfile
    {
        $user = User::create([
            'name' => 'Test Student', 'email' => $email,
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => 'STU-'.$user->id,
            'program_id' => $curriculum->program_id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    /**
     * @return array{0: array<int, Section>, 1: list<int>} [tightSection to loose section keyed by name, sectionIds]
     */
    private function makeTightBlock(AcademicTerm $term, Curriculum $curriculum): array
    {
        $plan = AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id,
            'college' => 'ccs', 'year_level' => 1, 'section_count' => 1,
            'students_per_block' => 40, 'status' => 'submitted',
        ]);

        $makeFaculty = fn (string $email): int => User::create([
            'name' => 'Prof', 'email' => $email,
            'password' => self::PASSWORD, 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ])->id;

        $tightSubject = Subject::create(['code' => 'CS101', 'title' => 'CS101 Title', 'units' => 3, 'status' => SubjectStatus::Active]);
        CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_id' => $tightSubject->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true]);
        $tightSection = Section::create([
            'academic_term_id' => $term->id, 'section_plan_id' => $plan->id, 'subject_id' => $tightSubject->id,
            'section_code' => 'IT101', 'professor_id' => $makeFaculty('cs101.prof@grc.test'),
            'schedule_days' => 'MWF', 'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00',
            'room' => 'LAB-1', 'capacity' => 1, 'enrolled_count' => 0, 'is_block_exclusive' => true, 'status' => SectionStatus::Published,
        ]);

        $looseSubject = Subject::create(['code' => 'GE101', 'title' => 'GE101 Title', 'units' => 3, 'status' => SubjectStatus::Active]);
        CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_id' => $looseSubject->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true]);
        $looseSection = Section::create([
            'academic_term_id' => $term->id, 'section_plan_id' => $plan->id, 'subject_id' => $looseSubject->id,
            'section_code' => 'IT101', 'professor_id' => $makeFaculty('ge101.prof@grc.test'),
            'schedule_days' => 'MWF', 'starts_at_time' => '09:00:00', 'ends_at_time' => '10:00:00',
            'room' => 'LAB-1', 'capacity' => 40, 'enrolled_count' => 0, 'is_block_exclusive' => true, 'status' => SectionStatus::Published,
        ]);

        return [
            ['tight' => $tightSection, 'loose' => $looseSection],
            [$tightSection->id, $looseSection->id],
        ];
    }

    private function tokenFor(StudentProfile $student): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $student->user->email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    public function test_a_second_submission_for_the_last_seat_in_a_block_is_rejected_and_writes_nothing(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        [$named] = $this->makeTightBlock($term, $curriculum);

        $firstStudent = $this->makeStudent($curriculum, 'first.capacity@grc.test');
        $secondStudent = $this->makeStudent($curriculum, 'second.capacity@grc.test');

        $firstResponse = $this->withToken($this->tokenFor($firstStudent))->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'block_code' => 'IT101',
        ]);
        $firstResponse->assertCreated();

        self::assertSame(1, $named['tight']->refresh()->enrolled_count);

        $secondResponse = $this->withToken($this->tokenFor($secondStudent))->postJson('/api/v1/enrollments', [
            'academic_term_id' => $term->id,
            'block_code' => 'IT101',
        ]);

        $secondResponse->assertUnprocessable();
        $this->assertDatabaseCount('enrollments', 1);

        // The whole block is all-or-nothing: the second student's rejected
        // submission must leave no stray row in the loose section either,
        // even though the first student's own valid enrollment legitimately
        // has one.
        $this->assertDatabaseCount('enrollment_subjects', 2);
        self::assertSame(1, $named['tight']->refresh()->enrolled_count);
        self::assertSame(1, $named['loose']->refresh()->enrolled_count);
    }

    public function test_the_action_itself_rejects_a_section_that_lost_its_seat_after_the_pool_was_built(): void
    {
        // Calls `SubmitEnrollment` directly rather than through the HTTP
        // endpoint, bypassing `StoreEnrollmentRequest`'s pool-based
        // pre-check entirely. This is the scenario that pre-check cannot
        // cover on its own: a section that had a seat when the pool was
        // read, but lost it by the time the write actually runs. Only the
        // in-transaction lock inside `SubmitEnrollment` can catch this.
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        [$named, $sectionIds] = $this->makeTightBlock($term, $curriculum);
        $named['tight']->update(['enrolled_count' => 1]);

        $student = $this->makeStudent($curriculum, 'raced.capacity@grc.test');
        $actor = $student->user;

        $this->expectException(ValidationException::class);

        try {
            app(SubmitEnrollment::class)->execute(
                $student,
                $term,
                $sectionIds,
                $actor,
                new AuditRequestContext('capacity-race-request', null),
                'IT101',
            );
        } finally {
            $this->assertDatabaseCount('enrollments', 0);
            $this->assertDatabaseCount('enrollment_subjects', 0);
            self::assertSame(0, $named['loose']->refresh()->enrolled_count);
        }
    }
}
