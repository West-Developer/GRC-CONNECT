<?php

namespace Tests\Feature\Actions\Analytics;

use App\Actions\Analytics\GenerateSectionDemandForecasts;
use App\Domain\Analytics\PredictionRunStatus;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\ScheduleGenerationStatus;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Program;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionDemandObservation;
use App\Models\Subject;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class GenerateSectionDemandForecastsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_the_cohort_history_and_persists_an_advisory_forecast(): void
    {
        $targetTerm = AcademicTerm::create(['school_year' => '2027-2028', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterOngoing]);
        $historyTerm = AcademicTerm::create(['school_year' => '2027-2028', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $program = Program::create(['code' => 'BSIT', 'name' => 'BS IT', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Ccs]);
        $curriculum = Curriculum::create(['program_id' => $program->id, 'name' => 'BSIT Curriculum', 'effective_school_year' => '2024-2025', 'status' => CurriculumStatus::Active]);
        $subject = Subject::create(['code' => 'IT301', 'college' => CollegeCode::Ccs, 'title' => 'Systems Analysis', 'units' => 3, 'status' => 'active']);
        CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_id' => $subject->id, 'year_level' => 3, 'semester' => '2nd', 'is_required' => true]);
        $student = User::create(['name' => 'Student', 'email' => 'student.history@grc.test', 'password' => 'password', 'role' => UserRole::Student, 'college' => CollegeCode::Ccs, 'status' => UserStatus::Active]);
        StudentProfile::create(['user_id' => $student->id, 'student_number' => '2027-history', 'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'entry_year' => 2025, 'year_level' => 3, 'admission_status' => AdmissionStatus::Enrolled, 'academic_standing' => AcademicStanding::Good]);
        SectionDemandObservation::create(['academic_term_id' => $historyTerm->id, 'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id, 'college' => CollegeCode::Ccs, 'year_level' => 3, 'cohort_size' => 45, 'enrolled_count' => 42, 'section_count' => 2, 'offered_capacity' => 80, 'source' => 'derived_from_enrollments']);
        $chair = User::create(['name' => 'Chair', 'email' => 'chair.forecast@grc.test', 'password' => 'password', 'role' => UserRole::ProgramChair, 'college' => CollegeCode::Ccs, 'status' => UserStatus::Active]);
        $run = ScheduleGenerationRun::create(['academic_term_id' => $targetTerm->id, 'college' => 'ccs', 'initiated_by' => $chair->id, 'status' => ScheduleGenerationStatus::Queued]);

        Http::fake([
            'http://127.0.0.1:8100/internal/v1/section-demand/predict' => Http::response(['data' => [
                'feature_schema_version' => 'v2',
                'model_version' => 'section-demand-rf-v2',
                'strategy' => 'historical_baseline',
                'metrics' => [
                    'training_observation_count' => 1,
                    'validation_observation_count' => 0,
                    'mae' => null,
                    'rmse' => null,
                ],
                'forecasts' => [[
                    'key' => "{$curriculum->id}:3",
                    'predicted_demand' => 48.0,
                    'confidence_lower' => 40.0,
                    'confidence_upper' => 54.0,
                    'suggested_section_count' => 2,
                ]],
            ]]),
        ]);

        app(GenerateSectionDemandForecasts::class)->execute($run);

        $run->refresh();
        $this->assertSame(ScheduleGenerationStatus::Succeeded, $run->status);
        $this->assertNotNull($run->prediction_run_id);
        $this->assertDatabaseHas('section_demand_forecasts', ['prediction_run_id' => $run->prediction_run_id, 'academic_term_id' => $targetTerm->id, 'subject_id' => $subject->id, 'suggested_section_count' => 2]);
        $this->assertSame(PredictionRunStatus::Succeeded, $run->predictionRun->status);
        $this->assertSame('historical_baseline', $run->predictionRun->metrics['strategy']);
        $this->assertSame(1, $run->predictionRun->metrics['observation_count']);
        $this->assertSame(0, $run->predictionRun->metrics['service_fallback_count']);
        Http::assertSent(function ($request) use ($curriculum, $subject): bool {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame([
                'cohort_size' => 45,
                'enrolled_count' => 42,
                'section_count' => 2,
                'offered_capacity' => 80,
                'year_level' => 3,
                'semester' => '1st',
            ], $payload['data']['observations'][0]);
            $this->assertSame([
                'key' => "{$curriculum->id}:3",
                'cohort_size' => 1,
                'section_count' => 2,
                'recommended_capacity' => 40,
                'year_level' => 3,
                'semester' => '2nd',
            ], $payload['data']['targets'][0]);

            return true;
        });
    }

    public function test_it_uses_real_cohort_history_and_the_random_forest_block_recommendation(): void
    {
        $targetTerm = AcademicTerm::create(['school_year' => '2027-2028', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterOngoing]);
        $historyTerm = AcademicTerm::create(['school_year' => '2027-2028', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $program = Program::create(['code' => 'BSIT', 'name' => 'BS IT', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Ccs]);
        $curriculum = Curriculum::create(['program_id' => $program->id, 'name' => 'BSIT Curriculum', 'effective_school_year' => '2024-2025', 'status' => CurriculumStatus::Active]);
        $subject = Subject::create(['code' => 'IT101', 'college' => CollegeCode::Ccs, 'title' => 'Programming 1', 'units' => 3, 'status' => 'active']);
        $syntheticSubject = Subject::create(['code' => 'IT102', 'college' => CollegeCode::Ccs, 'title' => 'Synthetic-only evidence', 'units' => 3, 'status' => 'active']);
        CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_id' => $subject->id, 'year_level' => 1, 'semester' => '2nd', 'is_required' => true]);

        for ($number = 1; $number <= 5; $number++) {
            $student = User::create(['name' => "Student {$number}", 'email' => "student.{$number}@grc.test", 'password' => 'password', 'role' => UserRole::Student, 'college' => CollegeCode::Ccs, 'status' => UserStatus::Active]);
            StudentProfile::create([
                'user_id' => $student->id,
                'student_number' => "2027-{$number}",
                'program_id' => $program->id,
                'curriculum_id' => $curriculum->id,
                'entry_year' => 2027,
                'year_level' => 1,
                'admission_status' => AdmissionStatus::Enrolled,
                'academic_standing' => AcademicStanding::Good,
            ]);
        }

        SectionDemandObservation::create(['academic_term_id' => $historyTerm->id, 'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id, 'college' => CollegeCode::Ccs, 'year_level' => 1, 'cohort_size' => 271, 'enrolled_count' => 268, 'section_count' => 9, 'offered_capacity' => 360, 'source' => 'derived_from_enrollments']);
        SectionDemandObservation::create(['academic_term_id' => $historyTerm->id, 'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'subject_id' => $syntheticSubject->id, 'college' => CollegeCode::Ccs, 'year_level' => 1, 'cohort_size' => 48, 'enrolled_count' => 45, 'section_count' => 2, 'offered_capacity' => 80, 'source' => 'local_synthetic_aggregate']);
        $chair = User::create(['name' => 'Chair', 'email' => 'chair.cohort-forecast@grc.test', 'password' => 'password', 'role' => UserRole::ProgramChair, 'college' => CollegeCode::Ccs, 'status' => UserStatus::Active]);
        $run = ScheduleGenerationRun::create(['academic_term_id' => $targetTerm->id, 'college' => CollegeCode::Ccs, 'initiated_by' => $chair->id, 'status' => ScheduleGenerationStatus::Queued]);

        Http::fake([
            'http://127.0.0.1:8100/internal/v1/section-demand/predict' => Http::response(['data' => [
                'feature_schema_version' => 'v2',
                'model_version' => 'section-demand-rf-v2',
                'strategy' => 'random_forest',
                'metrics' => ['training_observation_count' => 1, 'validation_observation_count' => 0, 'mae' => null, 'rmse' => null],
                'forecasts' => [[
                    'key' => "{$curriculum->id}:1",
                    'predicted_demand' => 270.0,
                    'confidence_lower' => 260.0,
                    'confidence_upper' => 275.0,
                    'suggested_section_count' => 9,
                ]],
            ]]),
        ]);

        app(GenerateSectionDemandForecasts::class)->execute($run);

        $this->assertDatabaseHas('section_demand_forecasts', [
            'academic_term_id' => $targetTerm->id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => 1,
            'suggested_section_count' => 9,
        ]);
        Http::assertSent(function ($request) use ($curriculum): bool {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame('v2', $payload['data']['feature_schema_version']);
            $this->assertSame([[
                'cohort_size' => 271,
                'enrolled_count' => 268,
                'section_count' => 9,
                'offered_capacity' => 360,
                'year_level' => 1,
                'semester' => '1st',
            ]], $payload['data']['observations']);
            $this->assertSame([[
                'key' => "{$curriculum->id}:1",
                'cohort_size' => 5,
                'section_count' => 9,
                'recommended_capacity' => 40,
                'year_level' => 1,
                'semester' => '2nd',
            ]], $payload['data']['targets']);

            return true;
        });
    }

    public function test_it_creates_an_editable_historical_baseline_when_the_prediction_service_is_unavailable(): void
    {
        $targetTerm = AcademicTerm::create(['school_year' => '2027-2028', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterOngoing]);
        $historyTerm = AcademicTerm::create(['school_year' => '2027-2028', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $program = Program::create(['code' => 'BSIT', 'name' => 'BS IT', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Ccs]);
        $curriculum = Curriculum::create(['program_id' => $program->id, 'name' => 'BSIT Curriculum', 'effective_school_year' => '2024-2025', 'status' => CurriculumStatus::Active]);
        $subject = Subject::create(['code' => 'IT102', 'college' => CollegeCode::Ccs, 'title' => 'Programming 2', 'units' => 3, 'status' => 'active']);
        CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_id' => $subject->id, 'year_level' => 1, 'semester' => '2nd', 'is_required' => true]);
        $student = User::create(['name' => 'Student', 'email' => 'student.fallback@grc.test', 'password' => 'password', 'role' => UserRole::Student, 'college' => CollegeCode::Ccs, 'status' => UserStatus::Active]);
        StudentProfile::create(['user_id' => $student->id, 'student_number' => '2027-fallback', 'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'entry_year' => 2027, 'year_level' => 1, 'admission_status' => AdmissionStatus::Enrolled, 'academic_standing' => AcademicStanding::Good]);
        SectionDemandObservation::create(['academic_term_id' => $historyTerm->id, 'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id, 'college' => CollegeCode::Ccs, 'year_level' => 1, 'cohort_size' => 40, 'enrolled_count' => 38, 'section_count' => 3, 'offered_capacity' => 120, 'source' => 'derived_from_enrollments']);
        $chair = User::create(['name' => 'Chair', 'email' => 'chair.fallback@grc.test', 'password' => 'password', 'role' => UserRole::ProgramChair, 'college' => CollegeCode::Ccs, 'status' => UserStatus::Active]);
        $run = ScheduleGenerationRun::create(['academic_term_id' => $targetTerm->id, 'college' => CollegeCode::Ccs, 'initiated_by' => $chair->id, 'status' => ScheduleGenerationStatus::Queued]);

        Http::fake([
            'http://127.0.0.1:8100/internal/v1/section-demand/predict' => Http::failedConnection(),
        ]);

        app(GenerateSectionDemandForecasts::class)->execute($run);

        $run->refresh();
        $this->assertSame(ScheduleGenerationStatus::Succeeded, $run->status);
        $this->assertDatabaseHas('section_demand_forecasts', ['academic_term_id' => $targetTerm->id, 'subject_id' => $subject->id, 'suggested_section_count' => 3]);
        $this->assertSame('section-demand-local-baseline-v1', $run->predictionRun->model_version);
        $this->assertSame('historical_baseline', $run->predictionRun->metrics['strategy']);
        $this->assertContains('prediction_service_unavailable', array_column($run->warnings, 'type'));
    }

    public function test_it_completes_with_an_insufficient_history_warning_without_calling_ml(): void
    {
        $term = AcademicTerm::create(['school_year' => '2027-2028', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterOngoing]);
        $program = Program::create(['code' => 'BEED', 'name' => 'BEED', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Coe]);
        $curriculum = Curriculum::create(['program_id' => $program->id, 'name' => 'BEED Curriculum', 'effective_school_year' => '2024-2025', 'status' => CurriculumStatus::Active]);
        $subject = Subject::create(['code' => 'ED101', 'college' => CollegeCode::Coe, 'title' => 'Teaching Foundations', 'units' => 3, 'status' => 'active']);
        CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_id' => $subject->id, 'year_level' => 1, 'semester' => '2nd', 'is_required' => true]);
        $student = User::create(['name' => 'Student', 'email' => 'student.empty-history@grc.test', 'password' => 'password', 'role' => UserRole::Student, 'college' => CollegeCode::Coe, 'status' => UserStatus::Active]);
        StudentProfile::create(['user_id' => $student->id, 'student_number' => '2027-empty-history', 'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'entry_year' => 2027, 'year_level' => 1, 'admission_status' => AdmissionStatus::Enrolled, 'academic_standing' => AcademicStanding::Good]);
        $chair = User::create(['name' => 'COE Chair', 'email' => 'chair.empty-history@grc.test', 'password' => 'password', 'role' => UserRole::ProgramChair, 'college' => CollegeCode::Coe, 'status' => UserStatus::Active]);
        $run = ScheduleGenerationRun::create(['academic_term_id' => $term->id, 'college' => 'coe', 'initiated_by' => $chair->id, 'status' => ScheduleGenerationStatus::Queued]);

        Http::fake();

        app(GenerateSectionDemandForecasts::class)->execute($run);

        $run->refresh();
        $this->assertSame(ScheduleGenerationStatus::Succeeded, $run->status);
        $this->assertSame(['Insufficient validated historical demand data for this college and term.'], array_column($run->warnings, 'message'));
        $this->assertSame(['insufficient_history'], array_column($run->warnings, 'type'));
        $this->assertSame([null], array_column($run->warnings, 'entity_id'));
        $this->assertSame(0, $run->predictionRun->metrics['observation_count']);
        Http::assertNothingSent();
    }

    /**
     * A retired curriculum version (e.g. a program's old 2012-2017 catalog,
     * still `archived` in the database because it once graduated students)
     * has no current students and no `reference_*` schedule data — planning
     * sections against it is pure noise nobody can ever complete. Only the
     * college's currently `active` curriculum should ever reach the
     * predictor.
     */
    public function test_it_ignores_placements_from_an_archived_curriculum(): void
    {
        $term = AcademicTerm::create(['school_year' => '2027-2028', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterOngoing]);
        $program = Program::create(['code' => 'BSHRM', 'name' => 'BS HRM', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Cbae]);
        $archivedCurriculum = Curriculum::create(['program_id' => $program->id, 'name' => 'Old HRM Curriculum', 'effective_school_year' => '2012-2017', 'status' => CurriculumStatus::Archived]);
        $subject = Subject::create(['code' => 'HR101', 'college' => CollegeCode::Cbae, 'title' => 'Retired Subject', 'units' => 3, 'status' => 'active']);
        CurriculumSubject::create(['curriculum_id' => $archivedCurriculum->id, 'subject_id' => $subject->id, 'year_level' => 1, 'semester' => '2nd', 'is_required' => true]);
        $chair = User::create(['name' => 'CBAE Chair', 'email' => 'chair.archived-curriculum@grc.test', 'password' => 'password', 'role' => UserRole::ProgramChair, 'college' => CollegeCode::Cbae, 'status' => UserStatus::Active]);
        $run = ScheduleGenerationRun::create(['academic_term_id' => $term->id, 'college' => 'cbae', 'initiated_by' => $chair->id, 'status' => ScheduleGenerationStatus::Queued]);

        Http::fake();

        app(GenerateSectionDemandForecasts::class)->execute($run);

        $run->refresh();
        $this->assertSame(ScheduleGenerationStatus::Succeeded, $run->status);
        $this->assertSame(['No current-term student cohorts were found for this college.'], array_column($run->warnings, 'message'));
        $this->assertSame(['no_curriculum_subjects'], array_column($run->warnings, 'type'));
        $this->assertSame([null], array_column($run->warnings, 'entity_id'));
        Http::assertNothingSent();
    }

    /**
     * The Teacher Certificate Program is a one-year intake, not a 4-year
     * degree — the seeded dataset's own cohort mapping treats "1st year"
     * and "TCP" as the same single-term entry point. Per product
     * direction, the six-step automation's scope is the 1st-4th year
     * degree-program process only; TCP was never part of that
     * documented scope, so its placements should never reach the
     * predictor at all rather than surface as a permanent "can't
     * complete" curriculum.
     */
    public function test_it_ignores_placements_from_the_teacher_certificate_program(): void
    {
        $term = AcademicTerm::create(['school_year' => '2027-2028', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterOngoing]);
        $program = Program::create(['code' => 'TCP', 'name' => 'Teacher Certificate Program', 'status' => ProgramStatus::Active, 'college' => CollegeCode::Coe]);
        $curriculum = Curriculum::create(['program_id' => $program->id, 'name' => 'TCP Curriculum', 'effective_school_year' => '2024-2025', 'status' => CurriculumStatus::Active]);
        $subject = Subject::create(['code' => 'TCP101', 'college' => CollegeCode::Coe, 'title' => 'TCP Subject', 'units' => 3, 'status' => 'active']);
        CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_id' => $subject->id, 'year_level' => 1, 'semester' => '2nd', 'is_required' => true]);
        $chair = User::create(['name' => 'COE Chair', 'email' => 'chair.tcp@grc.test', 'password' => 'password', 'role' => UserRole::ProgramChair, 'college' => CollegeCode::Coe, 'status' => UserStatus::Active]);
        $run = ScheduleGenerationRun::create(['academic_term_id' => $term->id, 'college' => 'coe', 'initiated_by' => $chair->id, 'status' => ScheduleGenerationStatus::Queued]);

        Http::fake();

        app(GenerateSectionDemandForecasts::class)->execute($run);

        $run->refresh();
        $this->assertSame(ScheduleGenerationStatus::Succeeded, $run->status);
        $this->assertSame(['No current-term student cohorts were found for this college.'], array_column($run->warnings, 'message'));
        $this->assertSame(['no_curriculum_subjects'], array_column($run->warnings, 'type'));
        $this->assertSame([null], array_column($run->warnings, 'entity_id'));
        Http::assertNothingSent();
    }
}
