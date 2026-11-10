<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Analytics\PredictionRunStatus;
use App\Domain\Analytics\PredictionType;
use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\FacultyEmploymentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CapacitySource;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Organization\SectionPlanStatus;
use App\Domain\Scheduling\SectionModality;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Notification;
use App\Models\PredictionRun;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stakeholder Doc 14 S15 (ADR 0033): the Dean monitors teaching load in their
 * own college and may assign or unassign a section's professor there, nothing
 * else.
 */
final class DeanFacultyLoadEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function term(): AcademicTerm
    {
        return AcademicTerm::query()->first()
            ?? AcademicTerm::create(['school_year' => '2027-2028', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
    }

    private function user(UserRole $role, ?CollegeCode $college, ?FacultyEmploymentType $type = null): User
    {
        return User::create([
            'name' => 'Test '.$role->value.' '.uniqid(),
            'email' => $role->value.'.'.uniqid().'@grc.test',
            'password' => 'correct-horse-battery-staple',
            'role' => $role,
            'college' => $college,
            'employment_type' => $type,
            'status' => UserStatus::Active,
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('dean-faculty-load-test')->plainTextToken;
    }

    private function section(string $code, string $college = 'ccs', array $overrides = []): Section
    {
        $term = $this->term();
        $program = Program::query()->where('college', $college)->first()
            ?? Program::create(['code' => 'P'.strtoupper($college), 'name' => 'Program '.$college, 'status' => ProgramStatus::Active, 'college' => CollegeCode::from($college)]);
        $curriculum = Curriculum::query()->where('program_id', $program->id)->first()
            ?? Curriculum::create(['program_id' => $program->id, 'name' => 'Curriculum '.$college, 'effective_school_year' => '2027-2028', 'status' => CurriculumStatus::Active]);
        $plan = AcademicTermSectionPlan::query()->where('college', $college)->first()
            ?? AcademicTermSectionPlan::create(['academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id, 'college' => $college, 'year_level' => 1, 'section_count' => 1, 'students_per_block' => 40, 'status' => SectionPlanStatus::Draft]);
        $subject = Subject::create(['code' => $code, 'college' => CollegeCode::from($college), 'title' => $code, 'units' => 3, 'status' => SubjectStatus::Active]);

        return Section::create($overrides + [
            'academic_term_id' => $term->id,
            'section_plan_id' => $plan->id,
            'subject_id' => $subject->id,
            'section_code' => 'A',
            'schedule_days' => 'MON',
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '10:00:00',
            'room' => 'R'.$code,
            'modality' => SectionModality::FaceToFace,
            'capacity' => 40,
            'capacity_source' => CapacitySource::Plan,
            'status' => SectionStatus::Planned,
        ]);
    }

    // --- monitoring ------------------------------------------------------

    public function test_a_dean_reads_their_colleges_report_including_professors_with_no_classes(): void
    {
        $term = $this->term();
        $busy = $this->user(UserRole::Faculty, CollegeCode::Ccs, FacultyEmploymentType::FullTime);
        $idle = $this->user(UserRole::Faculty, CollegeCode::Ccs, FacultyEmploymentType::PartTime);
        $otherCollege = $this->user(UserRole::Faculty, CollegeCode::Coe);
        $dean = $this->user(UserRole::Dean, CollegeCode::Ccs);
        $this->section('DEAN1', 'ccs', ['professor_id' => $busy->id]);

        $response = $this->withToken($this->token($dean))
            ->getJson("/api/v1/academic-terms/{$term->id}/faculty-load-report");

        $response->assertOk()->assertJsonPath('data.college', 'ccs');
        self::assertSame([$busy->id], array_column($response->json('data.faculty'), 'professor_id'));
        self::assertSame([$idle->id], array_column($response->json('data.idle_faculty'), 'professor_id'));
        self::assertNotContains($otherCollege->id, array_column($response->json('data.idle_faculty'), 'professor_id'));
        self::assertSame('Part-time', $response->json('data.idle_faculty.0.employment_type_label'));
    }

    public function test_the_registrar_head_still_cannot_read_the_report(): void
    {
        $term = $this->term();
        $registrarHead = $this->user(UserRole::RegistrarHead, null);

        $this->withToken($this->token($registrarHead))
            ->getJson("/api/v1/academic-terms/{$term->id}/faculty-load-report")
            ->assertForbidden();
    }

    public function test_a_dean_without_a_college_gets_no_report(): void
    {
        $term = $this->term();
        $dean = $this->user(UserRole::Dean, null);

        $this->withToken($this->token($dean))
            ->getJson("/api/v1/academic-terms/{$term->id}/faculty-load-report")
            ->assertUnprocessable();
    }

    // --- assigning a professor -------------------------------------------

    public function test_a_dean_assigns_a_professor_and_only_the_professor_changes(): void
    {
        $professor = $this->user(UserRole::Faculty, CollegeCode::Ccs);
        $dean = $this->user(UserRole::Dean, CollegeCode::Ccs);
        $section = $this->section('DEAN2');

        $this->withToken($this->token($dean))
            ->putJson("/api/v1/sections/{$section->id}/professor", ['professor_id' => $professor->id])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.professor_id', $professor->id);

        $fresh = $section->fresh();
        self::assertSame($professor->id, $fresh->professor_id);
        self::assertSame('RDEAN2', $fresh->room);
        self::assertSame('MON', $fresh->schedule_days);
        $audit = AuditLog::query()->where('action', AuditAction::SECTION_UPDATED)->first();
        self::assertNotNull($audit);
        self::assertNull($audit->before_values['professor_id']);
        self::assertSame($professor->id, $audit->after_values['professor_id']);
        // First assignment: the professor hears about it.
        self::assertSame(1, Notification::query()->where('user_id', $professor->id)->where('type', NotificationType::SectionAssigned)->count());
    }

    public function test_assigning_the_professor_already_in_place_changes_nothing(): void
    {
        $professor = $this->user(UserRole::Faculty, CollegeCode::Ccs);
        $dean = $this->user(UserRole::Dean, CollegeCode::Ccs);
        $section = $this->section('DEAN3', 'ccs', ['professor_id' => $professor->id]);

        $this->withToken($this->token($dean))
            ->putJson("/api/v1/sections/{$section->id}/professor", ['professor_id' => $professor->id])
            ->assertOk();

        self::assertSame(0, AuditLog::query()->where('action', AuditAction::SECTION_UPDATED)->count());
    }

    public function test_a_dean_can_unassign_the_professor(): void
    {
        $professor = $this->user(UserRole::Faculty, CollegeCode::Ccs);
        $dean = $this->user(UserRole::Dean, CollegeCode::Ccs);
        $section = $this->section('DEAN4', 'ccs', ['professor_id' => $professor->id]);

        $this->withToken($this->token($dean))
            ->putJson("/api/v1/sections/{$section->id}/professor", ['professor_id' => null])
            ->assertOk()
            ->assertJsonPath('data.professor_id', null);

        self::assertNull($section->fresh()->professor_id);
    }

    public function test_the_professor_field_must_be_present(): void
    {
        $dean = $this->user(UserRole::Dean, CollegeCode::Ccs);
        $section = $this->section('DEAN5');

        $this->withToken($this->token($dean))
            ->putJson("/api/v1/sections/{$section->id}/professor", [])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['errors' => ['professor_id']]]);
    }

    public function test_a_professor_already_teaching_at_that_time_is_refused(): void
    {
        $professor = $this->user(UserRole::Faculty, CollegeCode::Ccs);
        $dean = $this->user(UserRole::Dean, CollegeCode::Ccs);
        $this->section('DEAN6A', 'ccs', ['professor_id' => $professor->id, 'section_code' => 'A']);
        $second = $this->section('DEAN6B', 'ccs', ['section_code' => 'B']);

        $this->withToken($this->token($dean))
            ->putJson("/api/v1/sections/{$second->id}/professor", ['professor_id' => $professor->id])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['errors' => ['professor_id']]]);

        self::assertNull($second->fresh()->professor_id);
    }

    public function test_a_dean_cannot_assign_a_professor_of_another_college(): void
    {
        $professor = $this->user(UserRole::Faculty, CollegeCode::Coe);
        $dean = $this->user(UserRole::Dean, CollegeCode::Ccs);
        $section = $this->section('DEAN7');

        $this->withToken($this->token($dean))
            ->putJson("/api/v1/sections/{$section->id}/professor", ['professor_id' => $professor->id])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['errors' => ['professor_id']]]);
    }

    public function test_a_dean_cannot_touch_a_section_of_another_college(): void
    {
        $professor = $this->user(UserRole::Faculty, CollegeCode::Ccs);
        $dean = $this->user(UserRole::Dean, CollegeCode::Ccs);
        $section = $this->section('DEAN8', 'coe');

        $this->withToken($this->token($dean))
            ->putJson("/api/v1/sections/{$section->id}/professor", ['professor_id' => $professor->id])
            ->assertForbidden();

        self::assertNull($section->fresh()->professor_id);
    }

    public function test_a_program_head_cannot_use_the_dean_route(): void
    {
        $professor = $this->user(UserRole::Faculty, CollegeCode::Ccs);
        $head = $this->user(UserRole::ProgramChair, CollegeCode::Ccs);
        $section = $this->section('DEAN9');

        $this->withToken($this->token($head))
            ->putJson("/api/v1/sections/{$section->id}/professor", ['professor_id' => $professor->id])
            ->assertForbidden();
    }

    public function test_changing_the_professor_of_a_published_section_needs_no_approval_but_tells_the_registrar_head(): void
    {
        $old = $this->user(UserRole::Faculty, CollegeCode::Ccs);
        $new = $this->user(UserRole::Faculty, CollegeCode::Ccs);
        $dean = $this->user(UserRole::Dean, CollegeCode::Ccs);
        $registrarHead = $this->user(UserRole::RegistrarHead, null);
        $section = $this->section('DEAN10', 'ccs', ['professor_id' => $old->id, 'status' => SectionStatus::Published]);

        $this->withToken($this->token($dean))
            ->putJson("/api/v1/sections/{$section->id}/professor", ['professor_id' => $new->id])
            ->assertOk();

        self::assertSame($new->id, $section->fresh()->professor_id);
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::SECTION_PROFESSOR_REASSIGNED)->count());
        self::assertSame(1, Notification::query()
            ->where('user_id', $registrarHead->id)
            ->where('type', NotificationType::SectionProfessorReassigned)
            ->count());
    }

    public function test_a_generated_assignment_needs_a_reason_to_be_overridden(): void
    {
        $professor = $this->user(UserRole::Faculty, CollegeCode::Ccs);
        $dean = $this->user(UserRole::Dean, CollegeCode::Ccs);
        $section = $this->section('DEAN11');
        // Marks the section as produced by a schedule generation run.
        $run = PredictionRun::create(['type' => PredictionType::SectionDemand, 'academic_term_id' => $this->term()->id, 'model_version' => 'section-demand-rf-v1', 'feature_schema_version' => 'v1', 'status' => PredictionRunStatus::Succeeded, 'started_at' => now(), 'completed_at' => now()]);
        $section->forceFill(['recommendation_prediction_run_id' => $run->id])->saveQuietly();

        $this->withToken($this->token($dean))
            ->putJson("/api/v1/sections/{$section->id}/professor", ['professor_id' => $professor->id])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['errors' => ['override_reason']]]);
    }
}
