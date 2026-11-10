<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Academic\GradeMark;
use App\Domain\Academic\GradeStatus;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AttritionAndHonorsEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function user(UserRole $role, string $email): User
    {
        return User::create(['name' => $role->value, 'email' => $email, 'password' => self::PASSWORD, 'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
    }

    private function token(User $user): string
    {
        return (string) $this->withoutToken()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('data.token');
    }

    /** @return array{0: AcademicTerm, 1: AcademicTerm, 2: Curriculum} */
    private function termsAndCurriculum(): array
    {
        $first = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterClosed]);
        $second = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterOngoing]);
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'college' => CollegeCode::Ccs, 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::create(['program_id' => $program->id, 'name' => 'BSCS', 'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active]);

        return [$first, $second, $curriculum];
    }

    private function student(Curriculum $curriculum, string $number, bool $demo = false): StudentProfile
    {
        $user = $this->user(UserRole::Student, strtolower($number).'@grc.test');

        return StudentProfile::create([
            'user_id' => $user->id, 'student_number' => $number, 'program_id' => $curriculum->program_id,
            'curriculum_id' => $curriculum->id, 'year_level' => 1, 'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good, 'is_demo_account' => $demo,
        ]);
    }

    private function enrolled(StudentProfile $student, AcademicTerm $term): Enrollment
    {
        return Enrollment::create(['student_id' => $student->id, 'academic_term_id' => $term->id, 'status' => EnrollmentStatus::Enrolled, 'enrolled_at' => now()]);
    }

    public function test_attrition_is_aggregate_only_and_excludes_demo_accounts(): void
    {
        [$first, $second, $curriculum] = $this->termsAndCurriculum();
        $retained = $this->student($curriculum, 'S-001');
        $attrited = $this->student($curriculum, 'S-002');
        $demo = $this->student($curriculum, 'S-DEMO', true);
        $this->enrolled($retained, $first);
        $this->enrolled($retained, $second);
        $this->enrolled($attrited, $first);
        $this->enrolled($demo, $first);
        $second->update(['enrollment_closes_at' => now()->subDay()]);

        $response = $this->withToken($this->token($this->user(UserRole::RegistrarHead, 'registrar@grc.test')))
            ->getJson("/api/v1/analytics/attrition?baseline_academic_term_id={$first->id}&comparison_academic_term_id={$second->id}");

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.summary.baseline_count', 2)
            ->assertJsonPath('data.summary.retained_count', 1)
            ->assertJsonPath('data.summary.attrited_count', 1)
            ->assertJsonMissing(['student_number' => 'S-001'])
            ->assertJsonMissing(['student_number' => 'S-002']);
    }

    public function test_attrition_rejects_non_registrar_roles(): void
    {
        [$first, $second] = $this->termsAndCurriculum();
        $dean = $this->token($this->user(UserRole::Dean, 'dean@grc.test'));
        $this->withToken($dean)->getJson("/api/v1/analytics/attrition?baseline_academic_term_id={$first->id}&comparison_academic_term_id={$second->id}")->assertForbidden();
    }

    public function test_attrition_rejects_an_invalid_term_pair(): void
    {
        [$first, $second] = $this->termsAndCurriculum();
        $registrar = $this->token($this->user(UserRole::RegistrarHead, 'registrar2@grc.test'));
        $this->withToken($registrar)->getJson("/api/v1/analytics/attrition?baseline_academic_term_id={$second->id}&comparison_academic_term_id={$first->id}")
            ->assertUnprocessable();
    }

    public function test_dean_honors_requires_complete_submitted_grades_and_excludes_pe_from_gwa(): void
    {
        [, $term, $curriculum] = $this->termsAndCurriculum();
        $student = $this->student($curriculum, 'S-HONOR');
        $enrollment = $this->enrolled($student, $term);
        $ordinary = Subject::create(['code' => 'CS101', 'title' => 'CS', 'units' => 18, 'status' => SubjectStatus::Active]);
        $pe = Subject::create(['code' => ' PE 2 ', 'title' => 'PE', 'units' => 2, 'status' => SubjectStatus::Active]);
        $professor = $this->user(UserRole::Faculty, 'prof@grc.test');

        foreach ([[$ordinary, GradeMark::Excellent], [$pe, GradeMark::Failed]] as [$subject, $mark]) {
            $section = Section::create(['academic_term_id' => $term->id, 'subject_id' => $subject->id, 'section_code' => $subject->code, 'professor_id' => $professor->id, 'capacity' => 40, 'status' => SectionStatus::Published]);
            EnrollmentSubject::create(['enrollment_id' => $enrollment->id, 'section_id' => $section->id, 'status' => EnrollmentSubjectStatus::Enrolled]);
            AcademicGrade::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id, 'academic_term_id' => $term->id, 'mark' => $mark, 'final_grade' => $mark->numericValue(), 'status' => GradeStatus::Submitted, 'encoded_by' => $professor->id, 'submitted_at' => now()]);
        }

        $response = $this->withToken($this->token($this->user(UserRole::Dean, 'dean2@grc.test')))
            ->getJson("/api/v1/reports/honors?academic_term_id={$term->id}&page_size=10");

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('summary.qualifier_count', 1)
            ->assertJsonPath('data.0.gwa', '1.00')
            ->assertJsonPath('data.0.gwa_units', 18)
            ->assertJsonPath('data.0.excluded_subject_count', 1);
    }

    public function test_honors_report_envelope_matches_the_published_v1_contract(): void
    {
        [, $term, $curriculum] = $this->termsAndCurriculum();
        $this->student($curriculum, 'S-001');

        $response = $this->withToken($this->token($this->user(UserRole::Dean, 'dean3@grc.test')))
            ->getJson("/api/v1/reports/honors?academic_term_id={$term->id}");

        // The frontend parses this envelope with a `.strict()` Zod schema
        // (frontend/src/features/schemas/attrition-honors-schema.ts): exactly
        // `data`, `summary`, `meta` at the top level. Laravel's automatic
        // paginated-resource response also injects a top-level `links` key
        // and extra `meta.links`/`meta.path`/`meta.from`/`meta.to` entries;
        // any of that breaks contract parsing on the frontend, so this locks
        // the exact key set rather than only checking a few paths.
        $response->assertOk();
        $body = $response->json();
        self::assertEqualsCanonicalizing(['data', 'summary', 'meta'], array_keys($body));
        self::assertEqualsCanonicalizing(['qualifier_count'], array_keys($body['summary']));
        self::assertEqualsCanonicalizing(['current_page', 'last_page', 'per_page', 'total'], array_keys($body['meta']));
    }

    // --- Attrition = students who will not continue (Doc 14 S19) ----------

    /** @return array<string, mixed> the summary block of the report */
    private function attritionSummary(AcademicTerm $first, AcademicTerm $second): array
    {
        $response = $this->withToken($this->token($this->user(UserRole::RegistrarHead, 'registrar-'.uniqid().'@grc.test')))
            ->getJson("/api/v1/analytics/attrition?baseline_academic_term_id={$first->id}&comparison_academic_term_id={$second->id}");

        $response->assertOk();

        return $response->json('data.summary');
    }

    public function test_a_student_who_has_not_enrolled_yet_is_not_attrition_while_the_window_is_open(): void
    {
        [$first, $second, $curriculum] = $this->termsAndCurriculum();
        $second->update(['enrollment_closes_at' => now()->addWeek()]);
        $this->enrolled($this->student($curriculum, 'S-RET'), $first);
        $this->enrolled($this->student($curriculum, 'S-LATER'), $first);
        $this->enrolled($this->student($curriculum, 'S-RET'.'2'), $first);
        $this->enrolled(StudentProfile::query()->where('student_number', 'S-RET')->firstOrFail(), $second);

        $summary = $this->attritionSummary($first, $second);

        self::assertSame(1, $summary['baseline_count']);
        self::assertSame(1, $summary['retained_count']);
        self::assertSame(0, $summary['attrited_count']);
        self::assertSame(2, $summary['undecided_count']);
    }

    public function test_a_student_who_did_not_enroll_after_the_window_closed_is_attrition(): void
    {
        [$first, $second, $curriculum] = $this->termsAndCurriculum();
        $second->update(['enrollment_closes_at' => now()->subDay()]);
        $this->enrolled($this->student($curriculum, 'S-GONE'), $first);

        $summary = $this->attritionSummary($first, $second);

        self::assertSame(1, $summary['baseline_count']);
        self::assertSame(1, $summary['attrited_count']);
        self::assertSame(0, $summary['undecided_count']);
    }

    public function test_a_student_with_an_enrollment_in_progress_is_never_counted_as_attrition(): void
    {
        [$first, $second, $curriculum] = $this->termsAndCurriculum();
        $second->update(['enrollment_closes_at' => now()->subDay()]);
        $student = $this->student($curriculum, 'S-BUSY');
        $this->enrolled($student, $first);
        Enrollment::create(['student_id' => $student->id, 'academic_term_id' => $second->id, 'status' => EnrollmentStatus::PendingPayment]);

        $summary = $this->attritionSummary($first, $second);

        self::assertSame(0, $summary['baseline_count']);
        self::assertSame(0, $summary['attrited_count']);
        self::assertSame(1, $summary['undecided_count']);
    }

    public function test_a_student_who_withdrew_counts_at_once_even_while_the_window_is_open(): void
    {
        [$first, $second, $curriculum] = $this->termsAndCurriculum();
        $second->update(['enrollment_closes_at' => now()->addWeek()]);
        $left = $this->student($curriculum, 'S-LEFT');
        $left->update(['admission_status' => AdmissionStatus::Withdrawn]);
        $this->enrolled($left, $first);
        $dropped = $this->student($curriculum, 'S-DROPPED');
        $this->enrolled($dropped, $first);
        Enrollment::create(['student_id' => $dropped->id, 'academic_term_id' => $second->id, 'status' => EnrollmentStatus::Withdrawn]);

        $summary = $this->attritionSummary($first, $second);

        self::assertSame(2, $summary['baseline_count']);
        self::assertSame(2, $summary['attrited_count']);
        self::assertSame(0, $summary['undecided_count']);
    }

    public function test_graduates_are_not_attrition(): void
    {
        [$first, $second, $curriculum] = $this->termsAndCurriculum();
        $second->update(['enrollment_closes_at' => now()->subDay()]);
        $graduate = $this->student($curriculum, 'S-GRAD');
        $graduate->update(['graduation_school_year' => '2026-2027']);
        $this->enrolled($graduate, $first);

        $summary = $this->attritionSummary($first, $second);

        self::assertSame(0, $summary['baseline_count']);
        self::assertSame(0, $summary['attrited_count']);
        self::assertSame(1, $summary['graduated_count']);
    }
}
