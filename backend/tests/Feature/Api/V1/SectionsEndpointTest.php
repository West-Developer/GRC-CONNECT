<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Scheduling\SectionModality;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SectionsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function tokenFor(UserRole $role, string $email): string
    {
        User::create([
            'name' => 'Test '.$role->value,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            // Program Chairs are college-scoped (ADR 0018); SectionPolicy
            // denies section writes to a chair with no college.
            'college' => $role === UserRole::ProgramChair ? CollegeCode::Ccs : null,
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

    private function makeSubject(string $code): Subject
    {
        return Subject::create(['code' => $code, 'title' => 'Test Subject '.$code, 'units' => 3, 'status' => SubjectStatus::Active]);
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/sections')->assertUnauthorized();
        $this->postJson('/api/v1/sections', [])->assertUnauthorized();
    }

    public function test_a_learner_scoped_role_does_not_see_a_planned_section(): void
    {
        $term = $this->makeTerm();
        Section::create(['academic_term_id' => $term->id, 'subject_id' => $this->makeSubject('CS101')->id, 'section_code' => 'A', 'capacity' => 40, 'status' => SectionStatus::Published]);
        Section::create(['academic_term_id' => $term->id, 'subject_id' => $this->makeSubject('CS102')->id, 'section_code' => 'A', 'capacity' => 40, 'status' => SectionStatus::Planned]);
        $token = $this->tokenFor(UserRole::Student, 'student.sections@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/sections');

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        self::assertCount(1, $response->json('data'));
    }

    public function test_section_responses_use_canonical_three_letter_day_codes(): void
    {
        $term = $this->makeTerm();
        Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $this->makeSubject('DAY-CODES')->id,
            'section_code' => 'A',
            'schedule_days' => 'TUES/THURS',
            'capacity' => 40,
            'status' => SectionStatus::Published,
        ]);

        $response = $this->withToken($this->tokenFor(UserRole::Student, 'student.day-codes@grc.test'))
            ->getJson('/api/v1/sections');

        $response->assertOk()->assertJsonPath('data.0.schedule_days', 'TUE/THU');
    }

    /**
     * PRD §8.1 / ADR 0029: `SectionResource` reads each section's professor, so
     * the list must eager-load it. Up to ~630 extra queries per call used to run
     * on every Program Chair page; the count must stay flat in the row count.
     */
    public function test_listing_sections_runs_a_constant_number_of_queries_regardless_of_row_count(): void
    {
        $term = $this->makeTerm();
        $token = $this->tokenFor(UserRole::Student, 'student.sections.querycount@grc.test');
        $this->withToken($token)->getJson('/api/v1/sections')->assertOk(); // warm-up

        $this->seedPublishedSectionsWithDistinctProfessors($term, 0, 2);
        $few = $this->countQueriesDuring(fn () => $this->withToken($token)
            ->getJson('/api/v1/sections')->assertOk()->assertJsonCount(2, 'data'));

        $this->seedPublishedSectionsWithDistinctProfessors($term, 2, 8);
        $many = $this->countQueriesDuring(fn () => $this->withToken($token)
            ->getJson('/api/v1/sections')->assertOk()->assertJsonCount(8, 'data'));

        self::assertSame($few, $many, "Section list issued {$few} queries for 2 rows but {$many} for 8 (N+1).");
    }

    private function seedPublishedSectionsWithDistinctProfessors(AcademicTerm $term, int $from, int $to): void
    {
        for ($i = $from; $i < $to; $i++) {
            $professor = User::create([
                'name' => 'Prof '.$i, 'email' => "prof.sectionquery{$i}@grc.test",
                'password' => self::PASSWORD, 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
            ]);
            Section::create([
                'academic_term_id' => $term->id, 'subject_id' => $this->makeSubject('SQ'.(100 + $i))->id,
                'section_code' => 'A', 'professor_id' => $professor->id, 'capacity' => 40,
                'status' => SectionStatus::Published,
            ]);
        }
    }

    private function countQueriesDuring(callable $request): int
    {
        $this->app['auth']->forgetGuards();
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $request();

        return $count;
    }

    public function test_a_faculty_member_sees_only_their_own_published_or_closed_sections(): void
    {
        $term = $this->makeTerm();
        $faculty = User::create([
            'name' => 'Faculty Owner',
            'email' => 'faculty.owner.sections@grc.test',
            'password' => self::PASSWORD,
            'role' => UserRole::Faculty,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);
        $otherFaculty = User::create([
            'name' => 'Other Faculty',
            'email' => 'faculty.other.sections@grc.test',
            'password' => self::PASSWORD,
            'role' => UserRole::Faculty,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);

        $ownPublished = Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $this->makeSubject('CS201')->id,
            'section_code' => 'OWN-PUBLISHED',
            'professor_id' => $faculty->id,
            'capacity' => 40,
            'status' => SectionStatus::Published,
        ]);
        $ownClosed = Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $this->makeSubject('CS202')->id,
            'section_code' => 'OWN-CLOSED',
            'professor_id' => $faculty->id,
            'capacity' => 40,
            'status' => SectionStatus::Closed,
        ]);
        Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $this->makeSubject('CS203')->id,
            'section_code' => 'OTHER-PUBLISHED',
            'professor_id' => $otherFaculty->id,
            'capacity' => 40,
            'status' => SectionStatus::Published,
        ]);
        Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $this->makeSubject('CS204')->id,
            'section_code' => 'OTHER-CLOSED',
            'professor_id' => $otherFaculty->id,
            'capacity' => 40,
            'status' => SectionStatus::Closed,
        ]);

        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $faculty->email,
            'password' => self::PASSWORD,
        ])->json('data.token');

        $response = $this->withToken($token)->getJson('/api/v1/sections');

        $response->assertOk()->assertJsonCount(2, 'data');
        self::assertSame(
            [$ownPublished->id, $ownClosed->id],
            array_column($response->json('data'), 'id'),
        );
    }

    public function test_an_executive_director_receives_only_published_sections(): void
    {
        $term = $this->makeTerm();
        $published = Section::create(['academic_term_id' => $term->id, 'subject_id' => $this->makeSubject('EXEC-PUBLISHED')->id, 'section_code' => 'PUBLISHED', 'capacity' => 40, 'status' => SectionStatus::Published]);
        Section::create(['academic_term_id' => $term->id, 'subject_id' => $this->makeSubject('EXEC-PLANNED')->id, 'section_code' => 'PLANNED', 'capacity' => 40, 'status' => SectionStatus::Planned]);
        Section::create(['academic_term_id' => $term->id, 'subject_id' => $this->makeSubject('EXEC-CLOSED')->id, 'section_code' => 'CLOSED', 'capacity' => 40, 'status' => SectionStatus::Closed]);
        Section::create(['academic_term_id' => $term->id, 'subject_id' => $this->makeSubject('EXEC-CANCELLED')->id, 'section_code' => 'CANCELLED', 'capacity' => 40, 'status' => SectionStatus::Cancelled]);

        $response = $this->withToken($this->tokenFor(UserRole::ExecutiveDirector, 'executive.sections@grc.test'))
            ->getJson('/api/v1/sections');

        $response->assertOk()->assertJsonCount(1, 'data');
        self::assertSame([$published->id], array_column($response->json('data'), 'id'));
    }

    public function test_a_program_chair_can_create_a_section(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject('CS101');
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.create@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/sections', [
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'section_code' => 'A',
            'capacity' => 40,
            'status' => 'planned',
        ]);

        $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.section_code', 'A');
        $response->assertJsonPath('data.remaining_seats', 40);
        $this->assertDatabaseHas('sections', ['section_code' => 'A', 'capacity' => 40]);
        self::assertSame(
            AuditAction::SECTION_CREATED,
            AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action,
        );
    }

    public function test_section_writes_store_canonical_three_letter_day_codes(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject('CANONICAL-DAYS');
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.canonical-days@grc.test');

        $created = $this->withToken($token)->postJson('/api/v1/sections', [
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'section_code' => 'A',
            'schedule_days' => 'THIRS',
            'capacity' => 40,
            'status' => 'planned',
        ])->assertCreated();

        $sectionId = $created->json('data.id');
        $this->assertDatabaseHas('sections', ['id' => $sectionId, 'schedule_days' => 'THU']);

        $this->withToken($token)->patchJson("/api/v1/sections/{$sectionId}", [
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'section_code' => 'A',
            'schedule_days' => 'TUES/THURS',
            'capacity' => 40,
            'status' => 'planned',
        ])->assertOk();

        $this->assertDatabaseHas('sections', ['id' => $sectionId, 'schedule_days' => 'TUE/THU']);
    }

    public function test_a_non_program_chair_role_cannot_create_a_section(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject('CS101');
        $token = $this->tokenFor(UserRole::Dean, 'dean.create@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/sections', [
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'section_code' => 'A',
            'capacity' => 40,
            'status' => 'planned',
        ]);

        $response->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertDatabaseMissing('sections', ['section_code' => 'A']);
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_the_same_section_code_cannot_repeat_for_one_subject_in_one_term(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject('CS101');
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.dup@grc.test');

        $this->withToken($token)->postJson('/api/v1/sections', [
            'academic_term_id' => $term->id, 'subject_id' => $subject->id,
            'section_code' => 'A', 'capacity' => 40, 'status' => 'planned',
        ])->assertCreated();

        $auditCountBeforeRejection = AuditLog::query()->count();

        $response = $this->withToken($token)->postJson('/api/v1/sections', [
            'academic_term_id' => $term->id, 'subject_id' => $subject->id,
            'section_code' => 'A', 'capacity' => 35, 'status' => 'planned',
        ]);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertSame($auditCountBeforeRejection, AuditLog::query()->count());
    }

    public function test_a_program_chair_cannot_double_book_a_professor(): void
    {
        $term = $this->makeTerm();
        $professor = User::create([
            'name' => 'Professor', 'email' => 'professor.conflict@grc.test',
            'password' => 'irrelevant', 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.conflict@grc.test');

        $this->withToken($token)->postJson('/api/v1/sections', [
            'academic_term_id' => $term->id, 'subject_id' => $this->makeSubject('CS101')->id,
            'section_code' => 'A', 'professor_id' => $professor->id,
            'schedule_days' => 'MWF', 'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00',
            'capacity' => 40, 'status' => 'planned',
        ])->assertCreated();

        $auditCountBeforeRejection = AuditLog::query()->count();

        $response = $this->withToken($token)->postJson('/api/v1/sections', [
            'academic_term_id' => $term->id, 'subject_id' => $this->makeSubject('CS102')->id,
            'section_code' => 'A', 'professor_id' => $professor->id,
            'schedule_days' => 'MWF', 'starts_at_time' => '08:30:00', 'ends_at_time' => '09:30:00',
            'capacity' => 40, 'status' => 'planned',
        ]);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertSame($auditCountBeforeRejection, AuditLog::query()->count());
    }

    public function test_the_same_professor_may_teach_non_overlapping_sections(): void
    {
        $term = $this->makeTerm();
        $professor = User::create([
            'name' => 'Professor', 'email' => 'professor.nonoverlap@grc.test',
            'password' => 'irrelevant', 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.nonoverlap@grc.test');

        $this->withToken($token)->postJson('/api/v1/sections', [
            'academic_term_id' => $term->id, 'subject_id' => $this->makeSubject('CS101')->id,
            'section_code' => 'A', 'professor_id' => $professor->id,
            'schedule_days' => 'MWF', 'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00',
            'capacity' => 40, 'status' => 'planned',
        ])->assertCreated();

        $response = $this->withToken($token)->postJson('/api/v1/sections', [
            'academic_term_id' => $term->id, 'subject_id' => $this->makeSubject('CS102')->id,
            'section_code' => 'A', 'professor_id' => $professor->id,
            'schedule_days' => 'MWF', 'starts_at_time' => '09:00:00', 'ends_at_time' => '10:00:00',
            'capacity' => 40, 'status' => 'planned',
        ]);

        $response->assertCreated();
    }

    public function test_updating_a_section_fully_replaces_its_fields(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject('CS101');
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.update@grc.test');

        $created = $this->withToken($token)->postJson('/api/v1/sections', [
            'academic_term_id' => $term->id, 'subject_id' => $subject->id,
            'section_code' => 'A', 'capacity' => 40, 'status' => 'planned',
        ]);
        $sectionId = $created->json('data.id');

        $response = $this->withToken($token)->patchJson("/api/v1/sections/{$sectionId}", [
            'academic_term_id' => $term->id, 'subject_id' => $subject->id,
            'section_code' => 'A', 'capacity' => 45, 'status' => 'published',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.capacity', 45);
        $response->assertJsonPath('data.status', 'published');
        self::assertSame(
            1,
            AuditLog::query()->where('action', AuditAction::SECTION_UPDATED)->count(),
        );
    }

    public function test_updating_a_section_does_not_conflict_with_itself(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject('CS101');
        $professor = User::create([
            'name' => 'Professor', 'email' => 'professor.selfupdate@grc.test',
            'password' => 'irrelevant', 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.selfupdate@grc.test');

        $created = $this->withToken($token)->postJson('/api/v1/sections', [
            'academic_term_id' => $term->id, 'subject_id' => $subject->id,
            'section_code' => 'A', 'professor_id' => $professor->id,
            'schedule_days' => 'MWF', 'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00',
            'capacity' => 40, 'status' => 'planned',
        ]);
        $sectionId = $created->json('data.id');

        $response = $this->withToken($token)->patchJson("/api/v1/sections/{$sectionId}", [
            'academic_term_id' => $term->id, 'subject_id' => $subject->id,
            'section_code' => 'A', 'professor_id' => $professor->id,
            'schedule_days' => 'MWF', 'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00',
            'capacity' => 45, 'status' => 'planned',
        ]);

        $response->assertOk()->assertJsonPath('data.capacity', 45);
    }

    public function test_a_non_program_chair_role_cannot_update_a_section(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject('CS101');
        $section = Section::create(['academic_term_id' => $term->id, 'subject_id' => $subject->id, 'section_code' => 'A', 'capacity' => 40, 'status' => SectionStatus::Planned]);
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar-head.update@grc.test');

        $response = $this->withToken($token)->patchJson("/api/v1/sections/{$section->id}", [
            'academic_term_id' => $term->id, 'subject_id' => $subject->id,
            'section_code' => 'A', 'capacity' => 999, 'status' => 'planned',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('sections', ['id' => $section->id, 'capacity' => 40]);
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_cannot_assign_overlapping_schedule_to_different_subjects_in_same_block_section(): void
    {
        $term = $this->makeTerm();
        $subject1 = $this->makeSubject('CAPS2');
        $subject2 = $this->makeSubject('CAPS2L');
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.intraconflict@grc.test');

        // Subject 1 in IT401: Monday 07:30 - 09:00
        $sec1 = Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $subject1->id,
            'section_code' => 'IT401',
            'schedule_days' => 'M',
            'starts_at_time' => '07:30:00',
            'ends_at_time' => '09:00:00',
            'room' => '5D',
            'modality' => SectionModality::FaceToFace,
            'capacity' => 40,
            'status' => SectionStatus::Planned,
        ]);

        // Subject 2 in IT401: try to schedule also Monday 07:30 - 09:00 (Room LAB 3)
        $sec2 = Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $subject2->id,
            'section_code' => 'IT401',
            'capacity' => 40,
            'status' => SectionStatus::Planned,
        ]);

        $response = $this->withToken($token)->patchJson("/api/v1/sections/{$sec2->id}", [
            'academic_term_id' => $term->id,
            'subject_id' => $subject2->id,
            'section_code' => 'IT401',
            'schedule_days' => 'M',
            'starts_at_time' => '07:30:00',
            'ends_at_time' => '09:00:00',
            'room' => 'LAB 3',
            'modality' => 'f2f',
            'capacity' => 40,
            'status' => 'planned',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_cannot_assign_overlapping_schedule_to_same_room(): void
    {
        $term = $this->makeTerm();
        $subject1 = $this->makeSubject('CS101');
        $subject2 = $this->makeSubject('CS102');
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.roomconflict@grc.test');

        Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $subject1->id,
            'section_code' => 'IT101',
            'schedule_days' => 'M',
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '10:00:00',
            'room' => 'Room 101',
            'modality' => SectionModality::FaceToFace,
            'capacity' => 40,
            'status' => SectionStatus::Planned,
        ]);

        $sec2 = Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $subject2->id,
            'section_code' => 'IT102',
            'capacity' => 40,
            'status' => SectionStatus::Planned,
        ]);

        $response = $this->withToken($token)->patchJson("/api/v1/sections/{$sec2->id}", [
            'academic_term_id' => $term->id,
            'subject_id' => $subject2->id,
            'section_code' => 'IT102',
            'schedule_days' => 'M',
            'starts_at_time' => '09:00:00',
            'ends_at_time' => '11:00:00',
            'room' => 'Room 101',
            'modality' => 'f2f',
            'capacity' => 40,
            'status' => 'planned',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }
}
