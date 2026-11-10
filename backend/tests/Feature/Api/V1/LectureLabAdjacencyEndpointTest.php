<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stakeholder Doc 14: a Program Head is warned when a lecture and its
 * laboratory are not back-to-back. Review information only; nothing blocks.
 */
final class LectureLabAdjacencyEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private AcademicTerm $term;

    private Subject $lecture;

    private Subject $lab;

    private Curriculum $curriculum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->term = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $program = Program::create(['code' => 'BSIT', 'name' => 'BS IT', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Ccs]);
        $this->curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSIT Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
        $this->lecture = Subject::create(['code' => 'IT101', 'title' => 'Programming', 'units' => 2.0, 'status' => SubjectStatus::Active]);
        $this->lab = Subject::create([
            'code' => 'IT101L', 'title' => 'Programming Lab', 'units' => 1.0, 'status' => SubjectStatus::Active,
            'paired_subject_id' => $this->lecture->id,
        ]);
        $this->lecture->update(['paired_subject_id' => $this->lab->id]);
    }

    private function headToken(CollegeCode $college, string $email): string
    {
        User::create([
            'name' => 'Program Head', 'email' => $email, 'college' => $college,
            'password' => self::PASSWORD, 'role' => UserRole::ProgramChair, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function section(Subject $subject, string $days, string $start, string $end, string $code = 'A', ?AcademicTermSectionPlan $plan = null): Section
    {
        return Section::create([
            'academic_term_id' => $this->term->id, 'subject_id' => $subject->id, 'section_code' => $code,
            'section_plan_id' => $plan?->id, 'schedule_days' => $days, 'starts_at_time' => $start, 'ends_at_time' => $end,
            'capacity' => 40, 'status' => SectionStatus::Planned,
        ]);
    }

    private function url(): string
    {
        return "/api/v1/academic-terms/{$this->term->id}/lecture-lab-adjacency";
    }

    public function test_a_gap_between_lecture_and_lab_is_reported_with_a_readable_message(): void
    {
        $this->section($this->lecture, 'MW', '08:00:00', '09:30:00');
        $this->section($this->lab, 'MW', '10:30:00', '12:00:00');
        $token = $this->headToken(CollegeCode::Ccs, 'head.adjacent@grc.test');

        $response = $this->withToken($token)->getJson($this->url());

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.reason', 'not_back_to_back');
        $response->assertJsonPath('data.0.days', ['Mon', 'Wed']);
        $this->assertStringContainsString('IT101 and IT101L (section A) are not back-to-back on Mon, Wed', $response->json('data.0.message'));
    }

    public function test_a_back_to_back_pair_is_not_reported(): void
    {
        $this->section($this->lecture, 'MW', '08:00:00', '09:30:00');
        $this->section($this->lab, 'MW', '09:30:00', '11:00:00');
        $token = $this->headToken(CollegeCode::Ccs, 'head.fine@grc.test');

        $this->withToken($token)->getJson($this->url())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_another_colleges_sections_are_not_listed(): void
    {
        $otherPlan = AcademicTermSectionPlan::create([
            'academic_term_id' => $this->term->id, 'curriculum_id' => $this->curriculum->id,
            'college' => 'coe', 'year_level' => 1, 'section_count' => 1,
            'students_per_block' => 40, 'status' => 'draft',
        ]);
        $this->section($this->lecture, 'MW', '08:00:00', '09:30:00', 'A', $otherPlan);
        $this->section($this->lab, 'MW', '13:00:00', '14:30:00', 'A', $otherPlan);
        $token = $this->headToken(CollegeCode::Ccs, 'head.scope@grc.test');

        $this->withToken($token)->getJson($this->url())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_only_a_program_head_with_a_college_can_read_it(): void
    {
        $this->assertSame(401, $this->getJson($this->url())->status());

        $noCollege = User::create([
            'name' => 'No College', 'email' => 'head.nocollege.adj@grc.test', 'college' => null,
            'password' => self::PASSWORD, 'role' => UserRole::ProgramChair, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $noCollege->email, 'password' => self::PASSWORD,
        ])->json('data.token');

        $this->withToken($token)->getJson($this->url())->assertUnprocessable();
    }

    public function test_a_student_cannot_read_it(): void
    {
        $student = User::create([
            'name' => 'Student', 'email' => 'student.adjacency@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $student->email, 'password' => self::PASSWORD,
        ])->json('data.token');

        $this->withToken($token)->getJson($this->url())->assertForbidden();
    }
}
