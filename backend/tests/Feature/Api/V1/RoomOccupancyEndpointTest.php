<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\RoomCatalogEntry;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RoomOccupancyEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function tokenFor(UserRole $role, string $email, ?CollegeCode $college = null): string
    {
        User::create([
            'name' => 'Test '.$role->value,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'college' => $college,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
    }

    private function makePlan(AcademicTerm $term, CollegeCode $college): AcademicTermSectionPlan
    {
        $program = Program::create(['code' => 'PROG-'.$college->value, 'name' => 'Program '.$college->value, 'status' => 'active', 'college' => $college]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'Curriculum '.$college->value,
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);

        return AcademicTermSectionPlan::create([
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'college' => $college->value,
            'year_level' => 1,
        ]);
    }

    private function makeSubject(string $code, ?int $pairedSubjectId = null, ?string $roomRequirement = null): Subject
    {
        return Subject::create([
            'code' => $code,
            'title' => 'Test Subject '.$code,
            'units' => 3,
            'status' => SubjectStatus::Active,
            'paired_subject_id' => $pairedSubjectId,
            'room_requirement' => $roomRequirement,
        ]);
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/room-occupancy?room=3A&academic_term_id=1')->assertUnauthorized();
    }

    public function test_a_non_chair_non_registrar_role_is_forbidden(): void
    {
        $token = $this->tokenFor(UserRole::Faculty, 'faculty.room-occupancy@grc.test');
        $term = $this->makeTerm();
        RoomCatalogEntry::create(['name' => '3A', 'college' => CollegeCode::Ccs->value, 'capacity' => 45, 'room_type' => 'lecture']);

        $this->withToken($token)
            ->getJson("/api/v1/room-occupancy?room=3A&academic_term_id={$term->id}")
            ->assertForbidden();
    }

    public function test_an_unknown_room_name_is_rejected(): void
    {
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.room-occupancy@grc.test', CollegeCode::Ccs);
        $term = $this->makeTerm();

        $this->withToken($token)
            ->getJson("/api/v1/room-occupancy?room=NOT-A-ROOM&academic_term_id={$term->id}")
            ->assertUnprocessable();
    }

    public function test_a_shared_rooms_occupancy_includes_another_colleges_booking(): void
    {
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.ccs.room-occupancy@grc.test', CollegeCode::Ccs);
        $term = $this->makeTerm();
        RoomCatalogEntry::create(['name' => '3A', 'college' => CollegeCode::Ccs->value, 'capacity' => 45, 'room_type' => 'lecture']);
        RoomCatalogEntry::create(['name' => '3A', 'college' => CollegeCode::Coe->value, 'capacity' => 45, 'room_type' => 'lecture']);

        $ccsPlan = $this->makePlan($term, CollegeCode::Ccs);
        $coePlan = $this->makePlan($term, CollegeCode::Coe);
        $ccsSubject = $this->makeSubject('IT101');
        $coeSubject = $this->makeSubject('ELEM101');

        Section::create([
            'academic_term_id' => $term->id,
            'section_plan_id' => $ccsPlan->id,
            'subject_id' => $ccsSubject->id,
            'section_code' => 'IT101',
            'schedule_days' => 'MON/WED',
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '10:00:00',
            'room' => '3A',
            'capacity' => 40,
            'status' => SectionStatus::Planned,
        ]);
        Section::create([
            'academic_term_id' => $term->id,
            'section_plan_id' => $coePlan->id,
            'subject_id' => $coeSubject->id,
            'section_code' => 'ELEM101',
            'schedule_days' => 'TUE/THU',
            'starts_at_time' => '13:00:00',
            'ends_at_time' => '15:00:00',
            'room' => '3A',
            'capacity' => 40,
            'status' => SectionStatus::Planned,
        ]);

        $response = $this->withToken($token)
            ->getJson("/api/v1/room-occupancy?room=3A&academic_term_id={$term->id}")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $rows = $response->json('data');
        self::assertCount(2, $rows);
        $byCode = collect($rows)->keyBy('section_code');
        self::assertTrue($byCode['IT101']['is_own_college']);
        self::assertFalse($byCode['ELEM101']['is_own_college']);
        self::assertSame('coe', $byCode['ELEM101']['college']);
    }

    public function test_it_marks_a_paired_lecture_component_so_the_frontend_can_hide_it(): void
    {
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.lecture-flag@grc.test', CollegeCode::Ccs);
        $term = $this->makeTerm();
        RoomCatalogEntry::create(['name' => 'LAB 1', 'college' => CollegeCode::Ccs->value, 'capacity' => 45, 'room_type' => 'laboratory']);
        $plan = $this->makePlan($term, CollegeCode::Ccs);
        $lab = $this->makeSubject('PROG1L', null, 'laboratory');
        $lecture = $this->makeSubject('PROG1', $lab->id, 'lecture');
        $lab->update(['paired_subject_id' => $lecture->id]);

        Section::create([
            'academic_term_id' => $term->id,
            'section_plan_id' => $plan->id,
            'subject_id' => $lecture->id,
            'section_code' => 'IT101',
            'schedule_days' => 'MON',
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '09:00:00',
            'room' => 'LAB 1',
            'capacity' => 40,
            'status' => SectionStatus::Planned,
        ]);
        Section::create([
            'academic_term_id' => $term->id,
            'section_plan_id' => $plan->id,
            'subject_id' => $lab->id,
            'section_code' => 'IT101',
            'schedule_days' => 'WED',
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '11:00:00',
            'room' => 'LAB 1',
            'capacity' => 40,
            'status' => SectionStatus::Planned,
        ]);

        $response = $this->withToken($token)
            ->getJson("/api/v1/room-occupancy?room=LAB 1&academic_term_id={$term->id}")
            ->assertOk();

        $bySubject = collect($response->json('data'))->keyBy('subject_code');
        self::assertTrue($bySubject['PROG1']['is_lecture_component']);
        self::assertFalse($bySubject['PROG1L']['is_lecture_component']);
    }

    public function test_the_registrar_head_sees_every_colleges_booking_system_wide(): void
    {
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar.room-occupancy@grc.test');
        $term = $this->makeTerm();
        RoomCatalogEntry::create(['name' => '5A', 'college' => CollegeCode::Coa->value, 'capacity' => 45, 'room_type' => 'lecture']);
        $plan = $this->makePlan($term, CollegeCode::Coa);
        $subject = $this->makeSubject('ACC101');

        Section::create([
            'academic_term_id' => $term->id,
            'section_plan_id' => $plan->id,
            'subject_id' => $subject->id,
            'section_code' => 'ACC101',
            'schedule_days' => 'FRI',
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '10:00:00',
            'room' => '5A',
            'capacity' => 40,
            'status' => SectionStatus::Planned,
        ]);

        $response = $this->withToken($token)
            ->getJson("/api/v1/room-occupancy?room=5A&academic_term_id={$term->id}")
            ->assertOk();

        self::assertCount(1, $response->json('data'));
        self::assertSame('coa', $response->json('data.0.college'));
        // The Registrar Head carries no college of their own, so a
        // college-scoped booking is never "their own" — they can view every
        // college's occupancy, but `SectionPolicy::update()` still permits
        // assignment edits only to a matching-college Program Chair.
        self::assertFalse($response->json('data.0.is_own_college'));
    }
}
