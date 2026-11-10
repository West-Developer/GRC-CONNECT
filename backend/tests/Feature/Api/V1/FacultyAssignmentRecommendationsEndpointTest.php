<?php

namespace Tests\Feature\Api\V1;

use App\Actions\Scheduling\GenerateFacultyAssignmentRecommendations;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Faculty\SpecializationProficiency;
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
use App\Http\Resources\Api\V1\FacultyAssignmentRecommendationResource;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\FacultyAssignmentRecommendation;
use App\Models\FacultyAvailability;
use App\Models\FacultyCurriculumSubjectPreference;
use App\Models\FacultySpecialization;
use App\Models\Program;
use App\Models\RoomCatalogEntry;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

final class FacultyAssignmentRecommendationsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_primary_specialization_outranks_a_bare_preference(): void
    {
        [$section, $run, $barePreferenceProfessor, $primarySpecialist] = $this->recommendationContext();

        app(GenerateFacultyAssignmentRecommendations::class)->execute($run);

        $recommendation = FacultyAssignmentRecommendation::query()
            ->where('schedule_generation_run_id', $run->id)
            ->where('section_id', $section->id)
            ->sole();

        self::assertSame($primarySpecialist->id, $recommendation->recommended_professor_id);
        self::assertSame(SpecializationProficiency::Primary, $recommendation->specialization_match);
        self::assertSame('primary', (new FacultyAssignmentRecommendationResource($recommendation))
            ->resolve(Request::create('/'))['specialization_match']);
        self::assertNotSame($barePreferenceProfessor->id, $recommendation->recommended_professor_id);
    }

    /**
     * When no active faculty member in the section's college has declared
     * any preference for its subject, `$candidates` stays empty and the
     * planner recommends `professor_id: null` — proving `$selected` (looked
     * up from that empty list) can legitimately be `null` on a real,
     * unremarkable roster, not just a contrived edge case.
     */
    public function test_a_section_with_no_qualifying_candidate_is_recorded_without_a_recommendation(): void
    {
        $term = AcademicTerm::create([
            'school_year' => '2027-2028',
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $program = Program::create([
            'code' => 'BSIT', 'name' => 'Bachelor of Science in Information Technology',
            'college' => CollegeCode::Ccs, 'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSIT Curriculum',
            'effective_school_year' => '2024-2025', 'status' => CurriculumStatus::Active,
        ]);
        $subject = Subject::create([
            'code' => 'IT202', 'college' => CollegeCode::Ccs, 'title' => 'Algorithms',
            'units' => 3, 'status' => 'active',
        ]);
        CurriculumSubject::create([
            'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
            'year_level' => 2, 'semester' => '1st', 'is_required' => true,
        ]);
        $chair = $this->faculty('chair.unqualified@grc.test', UserRole::ProgramChair);
        // An active CCS faculty member exists but has declared no preference
        // for IT202 at all — the exact shape that leaves $candidates empty.
        $this->faculty('uninterested@grc.test');
        $plan = AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id,
            'college' => CollegeCode::Ccs, 'year_level' => 2, 'section_count' => 1,
            'students_per_block' => 40, 'status' => SectionPlanStatus::Draft,
        ]);
        $section = Section::create([
            'academic_term_id' => $term->id, 'section_plan_id' => $plan->id, 'subject_id' => $subject->id,
            'section_code' => 'IT202-A', 'schedule_days' => 'M', 'starts_at_time' => '08:00:00',
            'ends_at_time' => '11:00:00', 'capacity' => 40, 'capacity_source' => CapacitySource::Plan,
            'status' => SectionStatus::Planned,
        ]);
        $run = ScheduleGenerationRun::create([
            'academic_term_id' => $term->id, 'college' => CollegeCode::Ccs,
            'initiated_by' => $chair->id, 'status' => ScheduleGenerationStatus::Succeeded,
        ]);

        $warnings = app(GenerateFacultyAssignmentRecommendations::class)->execute($run);

        $recommendation = FacultyAssignmentRecommendation::query()
            ->where('schedule_generation_run_id', $run->id)
            ->where('section_id', $section->id)
            ->sole();
        self::assertNull($recommendation->recommended_professor_id);
        self::assertNull($recommendation->specialization_match);
        self::assertContains("No available preferred faculty could be recommended for section {$section->id}.", array_column($warnings, 'message'));
        self::assertContains([
            'type' => 'faculty_unavailable',
            'message' => "No available preferred faculty could be recommended for section {$section->id}.",
            'entity_id' => $section->id,
        ], $warnings);
    }

    /**
     * GRC's schedule taxonomy only has three modalities (F2F, Hyflex A,
     * Hyflex B) — a legacy "ONLINE" curriculum reference (from before the
     * current Hyflex split) has no direct equivalent, so filling a
     * section's schedule from it should resolve to Hyflex A rather than
     * leaving the field unresolved, and should not copy "ONLINE" itself in
     * as if it were a real room name.
     */
    public function test_a_legacy_online_reference_resolves_to_hyflex_a_without_a_placeholder_room(): void
    {
        [$section, $run] = $this->recommendationContext();
        $subject = $section->subject;
        CurriculumSubject::query()
            ->where('subject_id', $subject->id)
            ->update(['reference_room' => 'ONLINE', 'reference_modality' => 'ONLINE']);

        app(GenerateFacultyAssignmentRecommendations::class)->execute($run);

        $section->refresh();
        self::assertSame(SectionModality::HyflexA, $section->modality);
        self::assertNull($section->room);
    }

    /**
     * Per product direction, a real day/time reference with no modality
     * value at all — the actual seeded shape for every non-CCS college's
     * rows in curriculum-2024-2029-schedule-references.csv, which only
     * CCS's rows carry a modality for — defaults to ordinary face-to-face
     * rather than staying an unresolved gap.
     */
    public function test_a_reference_with_no_modality_value_at_all_defaults_to_f2f(): void
    {
        [$section, $run] = $this->recommendationContext();
        CurriculumSubject::query()
            ->where('subject_id', $section->subject_id)
            ->update(['reference_day' => 'M']);

        app(GenerateFacultyAssignmentRecommendations::class)->execute($run);

        self::assertSame(SectionModality::FaceToFace, $section->refresh()->modality);
    }

    /**
     * A small number of placements have a real reference_day but no
     * recorded start/end time at all — genuinely missing source data. Per
     * product direction, this defaults to the most common real time block
     * already present in the seeded roster (07:30-10:30) rather than
     * staying unresolved.
     */
    public function test_a_reference_with_no_time_value_at_all_defaults_to_the_common_morning_block(): void
    {
        [$section, $run] = $this->recommendationContext();
        $section->update(['starts_at_time' => null, 'ends_at_time' => null]);
        CurriculumSubject::query()
            ->where('subject_id', $section->subject_id)
            ->update(['reference_day' => 'M']);

        app(GenerateFacultyAssignmentRecommendations::class)->execute($run);

        $section->refresh();
        self::assertSame('07:30:00', $section->starts_at_time);
        self::assertSame('10:30:00', $section->ends_at_time);
    }

    public function test_it_does_not_double_book_a_shared_room_already_occupied_by_another_college(): void
    {
        [$term, $run, $section] = $this->roomAssignmentContext(
            starts: '08:00:00',
            ends: '10:00:00',
        );
        RoomCatalogEntry::create(['name' => '3A', 'college' => 'ccs', 'capacity' => 45, 'room_type' => 'lecture']);
        $this->makeOtherCollegeSection($term, room: '3A', starts: '09:00:00', ends: '11:00:00');

        app(GenerateFacultyAssignmentRecommendations::class)->execute($run);

        // "3A" is the only CCS-registered candidate and it is already
        // occupied by another college at an overlapping time — rooms are
        // shared campus-wide, so this must be visible even though the
        // occupying section belongs to a different college's plan.
        self::assertNull($section->refresh()->room);
    }

    public function test_it_skips_a_room_already_booked_by_another_college_and_picks_the_next_candidate(): void
    {
        [$term, $run, $section] = $this->roomAssignmentContext(
            starts: '08:00:00',
            ends: '10:00:00',
        );
        RoomCatalogEntry::create(['name' => '3A', 'college' => 'ccs', 'capacity' => 45, 'room_type' => 'lecture']);
        RoomCatalogEntry::create(['name' => '3B', 'college' => 'ccs', 'capacity' => 45, 'room_type' => 'lecture']);
        $this->makeOtherCollegeSection($term, room: '3A', starts: '09:00:00', ends: '11:00:00');

        app(GenerateFacultyAssignmentRecommendations::class)->execute($run);

        self::assertSame('3B', $section->refresh()->room);
    }

    public function test_it_assigns_a_shared_room_only_occupied_via_a_complementary_hyflex_pattern(): void
    {
        [$term, $run, $section] = $this->roomAssignmentContext(
            starts: '08:00:00',
            ends: '10:00:00',
            modality: SectionModality::HyflexA,
        );
        RoomCatalogEntry::create(['name' => '3A', 'college' => 'ccs', 'capacity' => 45, 'room_type' => 'lecture']);
        $this->makeOtherCollegeSection($term, room: '3A', starts: '08:00:00', ends: '10:00:00', modality: SectionModality::HyflexB);

        app(GenerateFacultyAssignmentRecommendations::class)->execute($run);

        self::assertSame('3A', $section->refresh()->room);
    }

    /**
     * Every block section of one subject inherits the same
     * `reference_room`/day/time from the curriculum placement. Trusting
     * that verbatim used to drop all of them into one room at one time —
     * the observed "3 bookings" cluster on the room calendar.
     */
    public function test_it_spreads_block_sections_sharing_one_reference_room_across_free_rooms(): void
    {
        $term = AcademicTerm::create([
            'school_year' => '2027-2028', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $program = Program::create([
            'code' => 'BSIT', 'name' => 'BS Information Technology', 'college' => CollegeCode::Ccs, 'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSIT Curriculum', 'effective_school_year' => '2024-2025', 'status' => CurriculumStatus::Active,
        ]);
        $subject = Subject::create([
            'code' => 'PATHFIT4', 'college' => CollegeCode::Ccs, 'title' => 'Physical Fitness 4', 'units' => 2,
            'status' => 'active', 'room_requirement' => 'lecture',
        ]);
        CurriculumSubject::create([
            'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
            'year_level' => 2, 'semester' => '1st', 'is_required' => true,
            'reference_day' => 'MON', 'reference_start_time' => '14:30:00', 'reference_end_time' => '16:30:00',
            'reference_room' => '3A', 'reference_modality' => 'f2f',
        ]);
        foreach (['3A', '3B', '3C'] as $room) {
            RoomCatalogEntry::create(['name' => $room, 'college' => 'ccs', 'capacity' => 45, 'room_type' => 'lecture']);
        }
        $plan = AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id, 'college' => CollegeCode::Ccs,
            'year_level' => 2, 'section_count' => 3, 'students_per_block' => 40, 'status' => SectionPlanStatus::Draft,
        ]);
        foreach (['HR201', 'HR202', 'HR203'] as $code) {
            Section::create([
                'academic_term_id' => $term->id, 'section_plan_id' => $plan->id, 'subject_id' => $subject->id,
                'section_code' => $code, 'capacity' => 40, 'capacity_source' => CapacitySource::Plan,
                'status' => SectionStatus::Planned,
            ]);
        }
        $run = ScheduleGenerationRun::create([
            'academic_term_id' => $term->id, 'college' => CollegeCode::Ccs,
            'initiated_by' => $this->faculty('chair.block-spread@grc.test', UserRole::ProgramChair)->id,
            'status' => ScheduleGenerationStatus::Succeeded,
        ]);

        app(GenerateFacultyAssignmentRecommendations::class)->execute($run);

        $rooms = Section::query()->where('section_plan_id', $plan->id)->pluck('room')->all();
        self::assertCount(3, $rooms);
        self::assertNotContains(null, $rooms, 'every block section should still receive a room');
        self::assertSame($rooms, array_unique($rooms), 'block sections sharing a time must not share a room');
    }

    /**
     * A professor's commitments in a plan that is already submitted are
     * outside this run's draft-scoped section list, but are just as real a
     * double-booking.
     */
    public function test_it_does_not_double_book_a_professor_already_committed_in_a_submitted_plan(): void
    {
        $term = AcademicTerm::create([
            'school_year' => '2027-2028', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $program = Program::create([
            'code' => 'BSIT', 'name' => 'BS Information Technology', 'college' => CollegeCode::Ccs, 'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSIT Curriculum', 'effective_school_year' => '2024-2025', 'status' => CurriculumStatus::Active,
        ]);
        $subject = Subject::create([
            'code' => 'IT301', 'college' => CollegeCode::Ccs, 'title' => 'Networking', 'units' => 3,
            'status' => 'active', 'room_requirement' => 'lecture',
        ]);
        CurriculumSubject::create([
            'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
            'year_level' => 3, 'semester' => '1st', 'is_required' => true,
        ]);
        $professor = $this->faculty('only.candidate@grc.test');
        FacultyAvailability::create([
            'professor_id' => $professor->id, 'day_of_week' => 3,
            'starts_at_time' => '07:00:00', 'ends_at_time' => '18:00:00', 'origin' => 'declared',
        ]);
        FacultyCurriculumSubjectPreference::create([
            'professor_id' => $professor->id, 'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
            'semester' => '1st', 'rank' => 1, 'origin' => 'declared',
        ]);

        // Already committed WED 09:00-11:00 under a SUBMITTED plan, which
        // this run's own draft-scoped query never sees.
        $submittedPlan = AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id, 'college' => CollegeCode::Ccs,
            'year_level' => 4, 'section_count' => 1, 'students_per_block' => 40, 'status' => SectionPlanStatus::Submitted,
        ]);
        Section::create([
            'academic_term_id' => $term->id, 'section_plan_id' => $submittedPlan->id, 'subject_id' => $subject->id,
            'section_code' => 'IT401', 'professor_id' => $professor->id,
            'schedule_days' => 'WED', 'starts_at_time' => '09:00:00', 'ends_at_time' => '11:00:00',
            'room' => '5A', 'modality' => SectionModality::FaceToFace,
            'capacity' => 40, 'capacity_source' => CapacitySource::Plan, 'status' => SectionStatus::Planned,
        ]);

        $draftPlan = AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id, 'college' => CollegeCode::Ccs,
            'year_level' => 3, 'section_count' => 1, 'students_per_block' => 40, 'status' => SectionPlanStatus::Draft,
        ]);
        $target = Section::create([
            'academic_term_id' => $term->id, 'section_plan_id' => $draftPlan->id, 'subject_id' => $subject->id,
            'section_code' => 'IT301-A', 'schedule_days' => 'WED', 'starts_at_time' => '10:00:00', 'ends_at_time' => '12:00:00',
            'modality' => SectionModality::FaceToFace,
            'capacity' => 40, 'capacity_source' => CapacitySource::Plan, 'status' => SectionStatus::Planned,
        ]);
        $run = ScheduleGenerationRun::create([
            'academic_term_id' => $term->id, 'college' => CollegeCode::Ccs,
            'initiated_by' => $this->faculty('chair.submitted-plan@grc.test', UserRole::ProgramChair)->id,
            'status' => ScheduleGenerationStatus::Succeeded,
        ]);

        app(GenerateFacultyAssignmentRecommendations::class)->execute($run);

        self::assertNull(
            $target->refresh()->professor_id,
            'the only candidate already teaches an overlapping WED slot, so no professor should be assigned',
        );
    }

    /**
     * The end-to-end guarantee, across colleges: two colleges generating
     * into the same shared room pool, every section wanting the identical
     * reference day/time, must end with every section roomed and no room
     * used twice in that slot.
     */
    public function test_two_colleges_generating_into_one_shared_room_pool_never_collide(): void
    {
        $term = AcademicTerm::create([
            'school_year' => '2027-2028', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        // Eight lecture rooms, each shared by both colleges — the real
        // catalog's shape since rooms became campus-wide.
        $sharedRooms = ['3A', '3B', '3C', '3D', '3E', '3F', '3G', '4A'];
        foreach ($sharedRooms as $room) {
            foreach (['ccs', 'coe'] as $college) {
                RoomCatalogEntry::create(['name' => $room, 'college' => $college, 'capacity' => 45, 'room_type' => 'lecture']);
            }
        }

        $runs = [];
        foreach ([[CollegeCode::Ccs, 'BSIT', 'IT'], [CollegeCode::Coe, 'BEED', 'ELEM']] as [$college, $programCode, $prefix]) {
            $program = Program::create([
                'code' => $programCode, 'name' => 'Program '.$programCode, 'college' => $college, 'status' => ProgramStatus::Active,
            ]);
            $curriculum = Curriculum::create([
                'program_id' => $program->id, 'name' => $programCode.' Curriculum',
                'effective_school_year' => '2024-2025', 'status' => CurriculumStatus::Active,
            ]);
            $subject = Subject::create([
                'code' => $prefix.'-SHARED', 'college' => $college, 'title' => $prefix.' Shared Subject',
                'units' => 3, 'status' => 'active', 'room_requirement' => 'lecture',
            ]);
            // Every block section inherits this one reference slot.
            CurriculumSubject::create([
                'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
                'year_level' => 1, 'semester' => '1st', 'is_required' => true,
                'reference_day' => 'MON', 'reference_start_time' => '08:00:00', 'reference_end_time' => '10:00:00',
                'reference_room' => '3A', 'reference_modality' => 'f2f',
            ]);
            $plan = AcademicTermSectionPlan::create([
                'academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id, 'college' => $college,
                'year_level' => 1, 'section_count' => 4, 'students_per_block' => 40, 'status' => SectionPlanStatus::Draft,
            ]);
            foreach (range(1, 4) as $n) {
                Section::create([
                    'academic_term_id' => $term->id, 'section_plan_id' => $plan->id, 'subject_id' => $subject->id,
                    'section_code' => $prefix.'10'.$n, 'capacity' => 40, 'capacity_source' => CapacitySource::Plan,
                    'status' => SectionStatus::Planned,
                ]);
            }
            $runs[] = ScheduleGenerationRun::create([
                'academic_term_id' => $term->id, 'college' => $college,
                'initiated_by' => $this->faculty('chair.'.strtolower($programCode).'.shared@grc.test', UserRole::ProgramChair)->id,
                'status' => ScheduleGenerationStatus::Succeeded,
            ]);
        }

        foreach ($runs as $run) {
            app(GenerateFacultyAssignmentRecommendations::class)->execute($run);
        }

        $scheduled = Section::query()->where('academic_term_id', $term->id)->get();
        self::assertCount(8, $scheduled);
        self::assertNotContains(null, $scheduled->pluck('room')->all(), 'every section must receive a room');

        // All eight share MON 08:00-10:00, so all eight rooms must differ.
        $rooms = $scheduled->pluck('room')->all();
        self::assertSame(
            count($rooms),
            count(array_unique($rooms)),
            'two sections in the same slot were placed in the same room: '.implode(',', $rooms),
        );
    }

    /** @return array{AcademicTerm, ScheduleGenerationRun, Section} */
    private function roomAssignmentContext(
        string $starts,
        string $ends,
        SectionModality $modality = SectionModality::FaceToFace,
    ): array {
        $term = AcademicTerm::create([
            'school_year' => '2027-2028', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $program = Program::create([
            'code' => 'BSIT', 'name' => 'BS Information Technology', 'college' => CollegeCode::Ccs, 'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSIT Curriculum', 'effective_school_year' => '2024-2025', 'status' => CurriculumStatus::Active,
        ]);
        $subject = Subject::create([
            'code' => 'IT301', 'college' => CollegeCode::Ccs, 'title' => 'Networking', 'units' => 3,
            'status' => 'active', 'room_requirement' => 'lecture',
        ]);
        $plan = AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id, 'college' => CollegeCode::Ccs,
            'year_level' => 3, 'section_count' => 1, 'students_per_block' => 40, 'status' => SectionPlanStatus::Draft,
        ]);
        $section = Section::create([
            'academic_term_id' => $term->id, 'section_plan_id' => $plan->id, 'subject_id' => $subject->id,
            'section_code' => 'IT301-A', 'schedule_days' => 'WED', 'starts_at_time' => $starts, 'ends_at_time' => $ends,
            'modality' => $modality, 'capacity' => 40, 'capacity_source' => CapacitySource::Plan, 'status' => SectionStatus::Planned,
        ]);
        $run = ScheduleGenerationRun::create([
            'academic_term_id' => $term->id, 'college' => CollegeCode::Ccs,
            'initiated_by' => $this->faculty('chair.room-assignment@grc.test', UserRole::ProgramChair)->id,
            'status' => ScheduleGenerationStatus::Succeeded,
        ]);

        return [$term, $run, $section];
    }

    private function makeOtherCollegeSection(
        AcademicTerm $term,
        string $room,
        string $starts,
        string $ends,
        SectionModality $modality = SectionModality::FaceToFace,
    ): Section {
        $program = Program::create([
            'code' => 'BEED', 'name' => 'Bachelor of Elementary Education', 'college' => CollegeCode::Coe, 'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BEED Curriculum', 'effective_school_year' => '2024-2025', 'status' => CurriculumStatus::Active,
        ]);
        $subject = Subject::create([
            'code' => 'ELEM1', 'college' => CollegeCode::Coe, 'title' => 'Elementary 1', 'units' => 3, 'status' => 'active',
        ]);
        $plan = AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id, 'college' => CollegeCode::Coe,
            'year_level' => 1, 'section_count' => 1, 'students_per_block' => 40, 'status' => SectionPlanStatus::Draft,
        ]);

        return Section::create([
            'academic_term_id' => $term->id, 'section_plan_id' => $plan->id, 'subject_id' => $subject->id,
            'section_code' => 'ELEM1-A', 'schedule_days' => 'WED', 'starts_at_time' => $starts, 'ends_at_time' => $ends,
            'room' => $room, 'modality' => $modality, 'capacity' => 40, 'capacity_source' => CapacitySource::Plan,
            'status' => SectionStatus::Planned,
        ]);
    }

    /** @return array{Section, ScheduleGenerationRun, User, User} */
    private function recommendationContext(): array
    {
        $term = AcademicTerm::create([
            'school_year' => '2027-2028',
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $program = Program::create([
            'code' => 'BSIT',
            'name' => 'Bachelor of Science in Information Technology',
            'college' => CollegeCode::Ccs,
            'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSIT Curriculum',
            'effective_school_year' => '2024-2025',
            'status' => CurriculumStatus::Active,
        ]);
        $subject = Subject::create([
            'code' => 'IT201',
            'college' => CollegeCode::Ccs,
            'title' => 'Data Structures',
            'units' => 3,
            'status' => 'active',
        ]);
        CurriculumSubject::create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => 2,
            'semester' => '1st',
            'is_required' => true,
        ]);
        $chair = $this->faculty('chair.recommendations@grc.test', UserRole::ProgramChair);
        $barePreferenceProfessor = $this->faculty('bare.preference@grc.test');
        $primarySpecialist = $this->faculty('primary.specialist@grc.test');
        $plan = AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'college' => CollegeCode::Ccs,
            'year_level' => 2,
            'section_count' => 1,
            'students_per_block' => 40,
            'status' => SectionPlanStatus::Draft,
        ]);
        $section = Section::create([
            'academic_term_id' => $term->id,
            'section_plan_id' => $plan->id,
            'subject_id' => $subject->id,
            'section_code' => 'IT201-A',
            'schedule_days' => 'M',
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '11:00:00',
            'capacity' => 40,
            'capacity_source' => CapacitySource::Plan,
            'status' => SectionStatus::Planned,
        ]);
        $run = ScheduleGenerationRun::create([
            'academic_term_id' => $term->id,
            'college' => CollegeCode::Ccs,
            'initiated_by' => $chair->id,
            'status' => ScheduleGenerationStatus::Succeeded,
        ]);

        foreach ([$barePreferenceProfessor, $primarySpecialist] as $professor) {
            FacultyAvailability::create([
                'professor_id' => $professor->id,
                'day_of_week' => 1,
                'starts_at_time' => '08:00:00',
                'ends_at_time' => '17:00:00',
                'origin' => 'declared',
            ]);
        }
        FacultyCurriculumSubjectPreference::create([
            'professor_id' => $barePreferenceProfessor->id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'semester' => '1st',
            'rank' => 1,
            'origin' => 'declared',
        ]);
        FacultyCurriculumSubjectPreference::create([
            'professor_id' => $primarySpecialist->id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'semester' => '1st',
            'rank' => 3,
            'origin' => 'declared',
        ]);
        FacultySpecialization::create([
            'professor_id' => $primarySpecialist->id,
            'subject_id' => $subject->id,
            'proficiency' => SpecializationProficiency::Primary,
            'source' => 'declared',
        ]);

        return [$section, $run, $barePreferenceProfessor, $primarySpecialist];
    }

    private function faculty(string $email, UserRole $role = UserRole::Faculty): User
    {
        return User::create([
            'name' => 'Recommendation Test User',
            'email' => $email,
            'password' => 'correct-horse-battery-staple',
            'role' => $role,
            'college' => CollegeCode::Ccs,
            'status' => UserStatus::Active,
        ]);
    }
}
