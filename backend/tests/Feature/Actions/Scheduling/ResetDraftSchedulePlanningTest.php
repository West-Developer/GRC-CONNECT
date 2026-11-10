<?php

namespace Tests\Feature\Actions\Scheduling;

use App\Actions\Scheduling\ResetDraftSchedulePlanning;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Organization\SectionPlanStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ResetDraftSchedulePlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_removes_only_safe_draft_planning_rows_for_the_requested_college(): void
    {
        $term = $this->term();
        [$ccsPlan, $ccsSection] = $this->draftPlanWithSection($term, CollegeCode::Ccs);
        [$coePlan, $coeSection] = $this->draftPlanWithSection($term, CollegeCode::Coe);

        $result = app(ResetDraftSchedulePlanning::class)->execute($term, CollegeCode::Ccs);

        self::assertSame(['sections' => 1, 'plans' => 1], $result);
        $this->assertDatabaseMissing('sections', ['id' => $ccsSection->id]);
        $this->assertDatabaseMissing('academic_term_section_plans', ['id' => $ccsPlan->id]);
        $this->assertDatabaseHas('sections', ['id' => $coeSection->id]);
        $this->assertDatabaseHas('academic_term_section_plans', ['id' => $coePlan->id]);
    }

    public function test_it_refuses_to_reset_when_the_college_has_submitted_or_enrolled_schedule_data(): void
    {
        $term = $this->term();
        [$plan, $section] = $this->draftPlanWithSection($term, CollegeCode::Ccs);
        $section->update(['enrolled_count' => 1]);

        $this->expectException(ValidationException::class);

        app(ResetDraftSchedulePlanning::class)->execute($term, CollegeCode::Ccs);

        $this->assertDatabaseHas('academic_term_section_plans', ['id' => $plan->id]);
    }

    /** @return array{0: AcademicTermSectionPlan, 1: Section} */
    private function draftPlanWithSection(AcademicTerm $term, CollegeCode $college): array
    {
        $program = Program::create([
            'code' => 'BS'.strtoupper($college->value),
            'name' => 'Program '.strtoupper($college->value),
            'college' => $college,
            'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'Curriculum '.strtoupper($college->value),
            'effective_school_year' => '2027-2028',
            'status' => CurriculumStatus::Active,
        ]);
        $subject = Subject::create([
            'code' => strtoupper($college->value).'101',
            'title' => 'Planning subject',
            'college' => $college,
            'units' => 3,
            'status' => 'active',
        ]);
        $plan = AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'college' => $college,
            'year_level' => 1,
            'section_count' => 1,
            'students_per_block' => 40,
            'status' => SectionPlanStatus::Draft,
        ]);
        $section = Section::create([
            'academic_term_id' => $term->id,
            'section_plan_id' => $plan->id,
            'subject_id' => $subject->id,
            'section_code' => '1A',
            'capacity' => 40,
            'status' => SectionStatus::Planned,
        ]);

        return [$plan, $section];
    }

    private function term(): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2027-2028',
            'semester' => '2nd',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
    }
}
