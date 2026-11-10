<?php

namespace Tests\Feature\Actions\Scheduling;

use App\Actions\Scheduling\ApplyDemandForecastToDraft;
use App\Domain\Analytics\PredictionRunStatus;
use App\Domain\Analytics\PredictionType;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CapacitySource;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Organization\SectionPlanStatus;
use App\Domain\Scheduling\ScheduleGenerationStatus;
use App\Domain\Scheduling\SectionModality;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\PredictionRun;
use App\Models\Program;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionDemandForecast;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApplyDemandForecastToDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_editable_predictive_blocks_from_the_highest_subject_demand(): void
    {
        [$term, $curriculum, $firstSubject, $secondSubject, $generationRun, $predictionRun] = $this->createForecastContext();

        SectionDemandForecast::create([
            'prediction_run_id' => $predictionRun->id,
            'academic_term_id' => $term->id,
            'subject_id' => $firstSubject->id,
            'predicted_demand' => 74,
            'suggested_section_count' => 2,
            'confidence_lower' => 70,
            'confidence_upper' => 78,
        ]);
        SectionDemandForecast::create([
            'prediction_run_id' => $predictionRun->id,
            'academic_term_id' => $term->id,
            'subject_id' => $secondSubject->id,
            'predicted_demand' => 108,
            'suggested_section_count' => 3,
            'confidence_lower' => 100,
            'confidence_upper' => 115,
        ]);

        app(ApplyDemandForecastToDraft::class)->execute($generationRun, $predictionRun);

        $plan = AcademicTermSectionPlan::query()->sole();
        $this->assertSame($curriculum->id, $plan->curriculum_id);
        $this->assertSame(2, $plan->year_level);
        $this->assertSame(3, $plan->section_count);
        $this->assertSame('predictive', $plan->recommendation_source);
        $this->assertSame(3, $plan->recommended_section_count);
        $this->assertFalse($plan->recommendation_is_overridden);
        $this->assertSame($predictionRun->id, $plan->recommendation_prediction_run_id);
        $this->assertSame(6, Section::query()->count());
        $this->assertSame(3, Section::query()->where('subject_id', $firstSubject->id)->count());
        $this->assertSame(3, Section::query()->where('subject_id', $secondSubject->id)->count());
        $this->assertTrue(Section::query()->get()->every(fn (Section $section): bool => $section->recommendation_source === 'predictive'));
        $this->assertTrue(Section::query()->get()->every(fn (Section $section): bool => $section->is_block_exclusive));
    }

    public function test_it_records_the_latest_recommendation_without_overwriting_manual_plan_or_section_values(): void
    {
        [$term, $curriculum, $firstSubject, , $generationRun, $predictionRun] = $this->createForecastContext();
        $chair = User::query()->where('email', 'chair.predictive@grc.test')->sole();
        $plan = AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'college' => CollegeCode::Ccs,
            'year_level' => 2,
            'section_count' => 1,
            'students_per_block' => 29,
            'status' => SectionPlanStatus::Draft,
        ]);
        $section = Section::create([
            'academic_term_id' => $term->id,
            'section_plan_id' => $plan->id,
            'subject_id' => $firstSubject->id,
            'section_code' => 'IT201',
            'professor_id' => $chair->id,
            'schedule_days' => 'Monday',
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '10:00:00',
            'room' => 'Lab 1',
            'modality' => SectionModality::FaceToFace,
            'capacity' => 29,
            'capacity_source' => CapacitySource::Manual,
            'is_block_exclusive' => true,
            'status' => SectionStatus::Planned,
        ]);
        SectionDemandForecast::create([
            'prediction_run_id' => $predictionRun->id,
            'academic_term_id' => $term->id,
            'subject_id' => $firstSubject->id,
            'predicted_demand' => 108,
            'suggested_section_count' => 3,
            'confidence_lower' => 100,
            'confidence_upper' => 115,
        ]);

        app(ApplyDemandForecastToDraft::class)->execute($generationRun, $predictionRun);

        $plan->refresh();
        $section->refresh();
        $this->assertSame(1, $plan->section_count);
        $this->assertSame(29, $plan->students_per_block);
        $this->assertSame('predictive', $plan->recommendation_source);
        $this->assertSame(3, $plan->recommended_section_count);
        $this->assertTrue($plan->recommendation_is_overridden);
        $this->assertSame($predictionRun->id, $plan->recommendation_prediction_run_id);
        $this->assertSame(1, Section::query()->count());
        $this->assertSame(29, $section->capacity);
        $this->assertSame(CapacitySource::Manual, $section->capacity_source);
        $this->assertSame($chair->id, $section->professor_id);
        $this->assertSame('Monday', $section->schedule_days);
        $this->assertSame('08:00:00', $section->starts_at_time);
        $this->assertSame('10:00:00', $section->ends_at_time);
        $this->assertSame('Lab 1', $section->room);
        $this->assertSame(SectionModality::FaceToFace, $section->modality);
    }

    public function test_it_honors_curriculum_specific_cohort_recommendations_for_an_old_curriculum(): void
    {
        [$term, $currentCurriculum, $firstSubject, , $generationRun, $predictionRun] = $this->createForecastContext();
        $oldCurriculum = Curriculum::create([
            'program_id' => $currentCurriculum->program_id,
            'name' => 'BSIT 2018 Curriculum',
            'effective_school_year' => '2018-2019',
            'status' => CurriculumStatus::Archived,
        ]);
        CurriculumSubject::create([
            'curriculum_id' => $oldCurriculum->id,
            'subject_id' => $firstSubject->id,
            'year_level' => 4,
            'semester' => '2nd',
            'is_required' => true,
        ]);

        SectionDemandForecast::create([
            'prediction_run_id' => $predictionRun->id,
            'academic_term_id' => $term->id,
            'curriculum_id' => $currentCurriculum->id,
            'subject_id' => $firstSubject->id,
            'year_level' => 2,
            'predicted_demand' => 180,
            'suggested_section_count' => 5,
            'confidence_lower' => 170,
            'confidence_upper' => 185,
        ]);
        SectionDemandForecast::create([
            'prediction_run_id' => $predictionRun->id,
            'academic_term_id' => $term->id,
            'curriculum_id' => $oldCurriculum->id,
            'subject_id' => $firstSubject->id,
            'year_level' => 4,
            'predicted_demand' => 72,
            'suggested_section_count' => 2,
            'confidence_lower' => 65,
            'confidence_upper' => 75,
        ]);

        app(ApplyDemandForecastToDraft::class)->execute($generationRun, $predictionRun);

        $this->assertDatabaseHas('academic_term_section_plans', [
            'academic_term_id' => $term->id,
            'curriculum_id' => $currentCurriculum->id,
            'year_level' => 2,
            'section_count' => 5,
        ]);
        $this->assertDatabaseHas('academic_term_section_plans', [
            'academic_term_id' => $term->id,
            'curriculum_id' => $oldCurriculum->id,
            'year_level' => 4,
            'section_count' => 2,
        ]);
    }

    /** @return array{AcademicTerm, Curriculum, Subject, Subject, ScheduleGenerationRun, PredictionRun} */
    /**
     * A retired curriculum version can legitimately share the same subject
     * code as its program's current catalog (e.g. both the archived
     * 2012-2017 HRM curriculum and the active 2024-2029 one place "HR301").
     * A forecast keyed only by subject_id must not fan out drafts to every
     * curriculum that happens to place that subject — only the currently
     * active one has real students to plan a section for.
     */
    public function test_it_ignores_a_forecasted_subjects_placement_in_an_archived_curriculum(): void
    {
        [$term, $activeCurriculum, $firstSubject, , $generationRun, $predictionRun] = $this->createForecastContext();
        $archivedCurriculum = Curriculum::create([
            'program_id' => $activeCurriculum->program_id,
            'name' => 'Old BSIT Curriculum',
            'effective_school_year' => '2012-2017',
            'status' => CurriculumStatus::Archived,
        ]);
        CurriculumSubject::create(['curriculum_id' => $archivedCurriculum->id, 'subject_id' => $firstSubject->id, 'year_level' => 2, 'semester' => '2nd', 'is_required' => true]);
        SectionDemandForecast::create([
            'prediction_run_id' => $predictionRun->id,
            'academic_term_id' => $term->id,
            'subject_id' => $firstSubject->id,
            'predicted_demand' => 74,
            'suggested_section_count' => 2,
            'confidence_lower' => 70,
            'confidence_upper' => 78,
        ]);

        app(ApplyDemandForecastToDraft::class)->execute($generationRun, $predictionRun);

        $this->assertSame(0, AcademicTermSectionPlan::query()->where('curriculum_id', $archivedCurriculum->id)->count());
        $this->assertSame(1, AcademicTermSectionPlan::query()->count());
        $plan = AcademicTermSectionPlan::query()->sole();
        $this->assertSame($activeCurriculum->id, $plan->curriculum_id);
    }

    /**
     * The Teacher Certificate Program is a one-year intake, not a 4-year
     * degree, and is out of the automation's documented 1st-4th year
     * scope — its placements should never draft sections even if they
     * happen to share a subject_id with an in-scope forecast.
     */
    public function test_it_ignores_a_forecasted_subjects_placement_in_the_teacher_certificate_program(): void
    {
        [$term, $activeCurriculum, $firstSubject, , $generationRun, $predictionRun] = $this->createForecastContext();
        $tcpProgram = Program::create(['code' => 'TCP', 'name' => 'Teacher Certificate Program', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Ccs]);
        $tcpCurriculum = Curriculum::create([
            'program_id' => $tcpProgram->id,
            'name' => 'TCP Curriculum',
            'effective_school_year' => '2024-2025',
            'status' => CurriculumStatus::Active,
        ]);
        CurriculumSubject::create(['curriculum_id' => $tcpCurriculum->id, 'subject_id' => $firstSubject->id, 'year_level' => 1, 'semester' => '2nd', 'is_required' => true]);
        SectionDemandForecast::create([
            'prediction_run_id' => $predictionRun->id,
            'academic_term_id' => $term->id,
            'subject_id' => $firstSubject->id,
            'predicted_demand' => 74,
            'suggested_section_count' => 2,
            'confidence_lower' => 70,
            'confidence_upper' => 78,
        ]);

        app(ApplyDemandForecastToDraft::class)->execute($generationRun, $predictionRun);

        $this->assertSame(0, AcademicTermSectionPlan::query()->where('curriculum_id', $tcpCurriculum->id)->count());
        $this->assertSame(1, AcademicTermSectionPlan::query()->count());
        $plan = AcademicTermSectionPlan::query()->sole();
        $this->assertSame($activeCurriculum->id, $plan->curriculum_id);
    }

    private function createForecastContext(): array
    {
        $term = AcademicTerm::create(['school_year' => '2027-2028', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterOngoing]);
        $program = Program::create(['code' => 'BSIT', 'name' => 'BS IT', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Ccs]);
        $curriculum = Curriculum::create(['program_id' => $program->id, 'name' => 'BSIT Curriculum', 'effective_school_year' => '2024-2025', 'status' => CurriculumStatus::Active]);
        $firstSubject = Subject::create(['code' => 'IT201', 'college' => CollegeCode::Ccs, 'title' => 'Data Structures', 'units' => 3, 'status' => 'active']);
        $secondSubject = Subject::create(['code' => 'IT202', 'college' => CollegeCode::Ccs, 'title' => 'Database Systems', 'units' => 3, 'status' => 'active']);
        CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_id' => $firstSubject->id, 'year_level' => 2, 'semester' => '2nd', 'is_required' => true]);
        CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_id' => $secondSubject->id, 'year_level' => 2, 'semester' => '2nd', 'is_required' => true]);
        $chair = User::create(['name' => 'Chair', 'email' => 'chair.predictive@grc.test', 'password' => 'password', 'role' => UserRole::ProgramChair, 'college' => CollegeCode::Ccs, 'status' => UserStatus::Active]);
        $predictionRun = PredictionRun::create(['type' => PredictionType::SectionDemand, 'academic_term_id' => $term->id, 'model_version' => 'section-demand-rf-v1', 'feature_schema_version' => 'v1', 'status' => PredictionRunStatus::Succeeded, 'started_at' => now(), 'completed_at' => now()]);
        $generationRun = ScheduleGenerationRun::create(['academic_term_id' => $term->id, 'prediction_run_id' => $predictionRun->id, 'college' => CollegeCode::Ccs, 'initiated_by' => $chair->id, 'status' => ScheduleGenerationStatus::Succeeded]);

        return [$term, $curriculum, $firstSubject, $secondSubject, $generationRun, $predictionRun];
    }
}
