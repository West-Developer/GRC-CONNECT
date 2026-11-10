<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\FacultyEmploymentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CapacitySource;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Organization\SectionPlanStatus;
use App\Domain\Scheduling\EffectiveFacultyLoadLimit;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\FacultyLoadLimit;
use App\Models\FacultyLoadOverride;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stakeholder Doc 14 S14 (ADR 0033): teaching-load limits per employment type
 * and per-professor overrides, set by the Program Head or the Dean for their
 * own college. No number is invented: with nothing configured, nobody is
 * flagged.
 */
final class FacultyLoadLimitsEndpointTest extends TestCase
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
        return $user->createToken('faculty-load-limits-test')->plainTextToken;
    }

    /** A CCS section of the given units taught by the professor. */
    private function teach(User $professor, float $units, string $code): void
    {
        $term = $this->term();
        $program = Program::query()->first()
            ?? Program::create(['code' => 'BSIT', 'name' => 'BS IT', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Ccs]);
        $curriculum = Curriculum::query()->first()
            ?? Curriculum::create(['program_id' => $program->id, 'name' => 'BSIT Curriculum', 'effective_school_year' => '2027-2028', 'status' => CurriculumStatus::Active]);
        $plan = AcademicTermSectionPlan::query()->first()
            ?? AcademicTermSectionPlan::create(['academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id, 'college' => 'ccs', 'year_level' => 1, 'section_count' => 1, 'students_per_block' => 40, 'status' => SectionPlanStatus::Draft]);
        $subject = Subject::create(['code' => $code, 'college' => CollegeCode::Ccs, 'title' => $code, 'units' => $units, 'status' => SubjectStatus::Active]);
        Section::create(['academic_term_id' => $term->id, 'section_plan_id' => $plan->id, 'subject_id' => $subject->id, 'section_code' => 'A', 'professor_id' => $professor->id, 'capacity' => 40, 'capacity_source' => CapacitySource::Plan, 'status' => SectionStatus::Planned]);
    }

    // --- pure precedence -------------------------------------------------

    public function test_the_effective_limit_prefers_override_then_type_then_college_default_then_none(): void
    {
        $limits = ['full_time' => 24.0];

        self::assertSame(
            ['max_units' => 30.0, 'source' => 'override'],
            EffectiveFacultyLoadLimit::resolve(FacultyEmploymentType::FullTime, $limits, 18.0, 30.0),
        );
        self::assertSame(
            ['max_units' => 24.0, 'source' => 'employment_type'],
            EffectiveFacultyLoadLimit::resolve(FacultyEmploymentType::FullTime, $limits, 18.0, null),
        );
        self::assertSame(
            ['max_units' => 18.0, 'source' => 'college_default'],
            EffectiveFacultyLoadLimit::resolve(FacultyEmploymentType::PartTime, $limits, 18.0, null),
        );
        self::assertSame(
            ['max_units' => null, 'source' => null],
            EffectiveFacultyLoadLimit::resolve(FacultyEmploymentType::PartTime, $limits, null, null),
        );
        self::assertSame(
            ['max_units' => null, 'source' => null],
            EffectiveFacultyLoadLimit::resolve(null, [], null, null),
        );
    }

    // --- limits per employment type -------------------------------------

    public function test_a_program_head_sets_a_limit_per_employment_type_once_and_it_is_audited(): void
    {
        $term = $this->term();
        $head = $this->user(UserRole::ProgramChair, CollegeCode::Ccs);
        $token = $this->token($head);

        $this->withToken($token)
            ->putJson("/api/v1/academic-terms/{$term->id}/faculty-load-limits/full_time", ['max_units' => 24])
            ->assertOk()
            ->assertJsonPath('data.employment_type', 'full_time')
            ->assertJsonPath('data.college', 'ccs')
            ->assertJsonPath('data.max_units', 24);
        // Saving the same value again is a no-op: no second audit row.
        $this->withToken($token)
            ->putJson("/api/v1/academic-terms/{$term->id}/faculty-load-limits/full_time", ['max_units' => 24])
            ->assertOk();
        $this->withToken($token)
            ->putJson("/api/v1/academic-terms/{$term->id}/faculty-load-limits/full_time", ['max_units' => 21])
            ->assertOk();

        self::assertSame(1, FacultyLoadLimit::query()->count());
        $rows = AuditLog::query()->where('action', AuditAction::FACULTY_LOAD_LIMIT_UPDATED)->orderBy('id')->get();
        self::assertCount(2, $rows);
        self::assertNull($rows[0]->before_values);
        self::assertEquals(24, $rows[0]->after_values['max_units']);
        self::assertEquals(24, $rows[1]->before_values['max_units']);
        self::assertEquals(21, $rows[1]->after_values['max_units']);
    }

    public function test_a_dean_sets_a_limit_for_their_own_college(): void
    {
        $term = $this->term();
        $dean = $this->user(UserRole::Dean, CollegeCode::Coe);

        $this->withToken($this->token($dean))
            ->putJson("/api/v1/academic-terms/{$term->id}/faculty-load-limits/part_time", ['max_units' => 12])
            ->assertOk()
            ->assertJsonPath('data.college', 'coe');

        $this->assertDatabaseHas('faculty_load_limits', ['college' => 'coe', 'employment_type' => 'part_time']);
    }

    public function test_an_unknown_employment_type_is_not_found(): void
    {
        $term = $this->term();
        $head = $this->user(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->token($head))
            ->putJson("/api/v1/academic-terms/{$term->id}/faculty-load-limits/contractual", ['max_units' => 12])
            ->assertNotFound();
    }

    public function test_a_limit_must_be_a_positive_number(): void
    {
        $term = $this->term();
        $head = $this->user(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->token($head))
            ->putJson("/api/v1/academic-terms/{$term->id}/faculty-load-limits/full_time", ['max_units' => 0])
            ->assertUnprocessable();
    }

    public function test_the_registrar_head_cannot_set_a_limit(): void
    {
        $term = $this->term();
        $registrarHead = $this->user(UserRole::RegistrarHead, null);

        $this->withToken($this->token($registrarHead))
            ->putJson("/api/v1/academic-terms/{$term->id}/faculty-load-limits/full_time", ['max_units' => 24])
            ->assertForbidden();
        self::assertSame(0, FacultyLoadLimit::query()->count());
    }

    public function test_a_dean_without_a_college_cannot_set_a_limit(): void
    {
        $term = $this->term();
        $dean = $this->user(UserRole::Dean, null);

        $this->withToken($this->token($dean))
            ->putJson("/api/v1/academic-terms/{$term->id}/faculty-load-limits/full_time", ['max_units' => 24])
            ->assertForbidden();
    }

    // --- per-professor overrides ----------------------------------------

    public function test_a_program_head_sets_an_override_with_a_reason_and_it_is_audited(): void
    {
        $term = $this->term();
        $professor = $this->user(UserRole::Faculty, CollegeCode::Ccs, FacultyEmploymentType::FullTime);
        $head = $this->user(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->token($head))
            ->putJson("/api/v1/academic-terms/{$term->id}/faculty-load-overrides/{$professor->id}", [
                'max_units' => 30,
                'reason' => 'No other professor can teach Networking this term.',
            ])
            ->assertOk()
            ->assertJsonPath('data.max_units', 30)
            ->assertJsonPath('data.professor_id', $professor->id);

        $audit = AuditLog::query()->where('action', AuditAction::FACULTY_LOAD_OVERRIDE_SET)->first();
        self::assertNotNull($audit);
        self::assertNull($audit->before_values);
        self::assertEquals(30, $audit->after_values['max_units']);
        self::assertSame('No other professor can teach Networking this term.', $audit->reason);
    }

    public function test_an_override_needs_a_reason(): void
    {
        $term = $this->term();
        $professor = $this->user(UserRole::Faculty, CollegeCode::Ccs);
        $head = $this->user(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->token($head))
            ->putJson("/api/v1/academic-terms/{$term->id}/faculty-load-overrides/{$professor->id}", ['max_units' => 30])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['errors' => ['reason']]]);
    }

    public function test_a_dean_cannot_override_a_professor_of_another_college(): void
    {
        $term = $this->term();
        $professor = $this->user(UserRole::Faculty, CollegeCode::Coe);
        $dean = $this->user(UserRole::Dean, CollegeCode::Ccs);

        $this->withToken($this->token($dean))
            ->putJson("/api/v1/academic-terms/{$term->id}/faculty-load-overrides/{$professor->id}", ['max_units' => 30, 'reason' => 'Need more classes.'])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['errors' => ['professor_id']]]);
        self::assertSame(0, FacultyLoadOverride::query()->count());
    }

    public function test_an_override_cannot_target_someone_who_is_not_a_professor(): void
    {
        $term = $this->term();
        $student = $this->user(UserRole::Student, null);
        $head = $this->user(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->token($head))
            ->putJson("/api/v1/academic-terms/{$term->id}/faculty-load-overrides/{$student->id}", ['max_units' => 30, 'reason' => 'Trying it.'])
            ->assertUnprocessable();
    }

    public function test_an_override_can_be_removed_and_removing_it_again_changes_nothing(): void
    {
        $term = $this->term();
        $professor = $this->user(UserRole::Faculty, CollegeCode::Ccs);
        $head = $this->user(UserRole::ProgramChair, CollegeCode::Ccs);
        FacultyLoadOverride::create(['academic_term_id' => $term->id, 'professor_id' => $professor->id, 'max_units' => 30, 'reason' => 'Extra classes.', 'set_by' => $head->id]);
        $token = $this->token($head);

        $this->withToken($token)->deleteJson("/api/v1/academic-terms/{$term->id}/faculty-load-overrides/{$professor->id}")->assertNoContent();
        $this->withToken($token)->deleteJson("/api/v1/academic-terms/{$term->id}/faculty-load-overrides/{$professor->id}")->assertNoContent();

        self::assertSame(0, FacultyLoadOverride::query()->count());
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::FACULTY_LOAD_OVERRIDE_CLEARED)->count());
    }

    // --- the report applies the precedence ------------------------------

    public function test_the_report_flags_overload_by_the_effective_limit_and_says_where_it_came_from(): void
    {
        $term = $this->term();
        $fullTime = $this->user(UserRole::Faculty, CollegeCode::Ccs, FacultyEmploymentType::FullTime);
        $partTime = $this->user(UserRole::Faculty, CollegeCode::Ccs, FacultyEmploymentType::PartTime);
        $overridden = $this->user(UserRole::Faculty, CollegeCode::Ccs, FacultyEmploymentType::PartTime);
        $head = $this->user(UserRole::ProgramChair, CollegeCode::Ccs);
        // Each carries 9 units.
        foreach ([$fullTime, $partTime, $overridden] as $index => $professor) {
            $this->teach($professor, 9, 'LOAD'.$index);
        }
        FacultyLoadLimit::create(['academic_term_id' => $term->id, 'college' => 'ccs', 'employment_type' => 'full_time', 'max_units' => 12]);
        FacultyLoadLimit::create(['academic_term_id' => $term->id, 'college' => 'ccs', 'employment_type' => 'part_time', 'max_units' => 6]);
        FacultyLoadOverride::create(['academic_term_id' => $term->id, 'professor_id' => $overridden->id, 'max_units' => 12, 'reason' => 'Extra classes.', 'set_by' => $head->id]);

        $response = $this->withToken($this->token($head))
            ->getJson("/api/v1/academic-terms/{$term->id}/faculty-load-report");

        $response->assertOk()
            ->assertJsonPath('data.limits.0.employment_type', 'full_time')
            ->assertJsonPath('data.limits.0.max_units', 12)
            ->assertJsonPath('data.limits.1.employment_type', 'part_time')
            ->assertJsonPath('data.limits.1.max_units', 6)
            ->assertJsonPath('data.overloaded_count', 1);
        $byId = collect($response->json('data.faculty'))->keyBy('professor_id');

        self::assertFalse($byId[$fullTime->id]['overloaded']);
        self::assertSame('employment_type', $byId[$fullTime->id]['limit_source']);
        self::assertSame(12, $byId[$fullTime->id]['max_units']);
        self::assertSame('Full-time', $byId[$fullTime->id]['employment_type_label']);

        self::assertTrue($byId[$partTime->id]['overloaded']);
        self::assertSame(6, $byId[$partTime->id]['max_units']);

        self::assertFalse($byId[$overridden->id]['overloaded']);
        self::assertSame('override', $byId[$overridden->id]['limit_source']);
        self::assertSame(12, $byId[$overridden->id]['override']['max_units']);
        self::assertSame('Extra classes.', $byId[$overridden->id]['override']['reason']);
    }

    public function test_with_nothing_configured_nobody_is_flagged_and_no_limit_is_invented(): void
    {
        $term = $this->term();
        $professor = $this->user(UserRole::Faculty, CollegeCode::Ccs, FacultyEmploymentType::FullTime);
        $head = $this->user(UserRole::ProgramChair, CollegeCode::Ccs);
        $this->teach($professor, 60, 'HUGE');

        $response = $this->withToken($this->token($head))
            ->getJson("/api/v1/academic-terms/{$term->id}/faculty-load-report");

        $response->assertOk()->assertJsonPath('data.overloaded_count', 0);
        self::assertNull($response->json('data.faculty.0.max_units'));
        self::assertNull($response->json('data.faculty.0.limit_source'));
        self::assertNull($response->json('data.limits.0.max_units'));
        self::assertNull($response->json('data.limits.1.max_units'));
    }
}
