<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\FacultyCurriculumSubjectPreference;
use App\Models\FacultySpecialization;
use App\Models\FacultySubjectPreference;
use App\Models\Program;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class FacultySubjectPreferencesEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function tokenFor(UserRole $role, string $email): array
    {
        $user = User::create([
            'name' => 'Test '.$role->value,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);

        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->json('data.token');

        return [$user, $token];
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
        $this->getJson('/api/v1/faculty-subject-preferences')->assertUnauthorized();
        $this->postJson('/api/v1/faculty-subject-preferences', [])->assertUnauthorized();
    }

    public function test_a_faculty_member_can_rank_a_subject(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject('CS101');
        [$professor, $token] = $this->tokenFor(UserRole::Faculty, 'professor.create@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/faculty-subject-preferences', [
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'rank' => 1,
        ]);

        $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.professor_id', $professor->id);
        $this->assertDatabaseHas('faculty_subject_preferences', ['professor_id' => $professor->id, 'subject_id' => $subject->id]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $professor->id,
            'action' => AuditAction::FACULTY_SUBJECT_PREFERENCE_CREATED,
            'auditable_id' => $response->json('data.id'),
        ]);
        self::assertSame(1, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_a_non_faculty_role_cannot_create_a_preference(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject('CS101');
        [, $token] = $this->tokenFor(UserRole::RegistrarStaff, 'registrar.create@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/faculty-subject-preferences', [
            'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'rank' => 1,
        ]);

        $response->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_ranking_the_same_subject_twice_in_one_term_is_rejected_with_a_clean_422(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject('CS101');
        $otherSubject = $this->makeSubject('CS102');
        [, $token] = $this->tokenFor(UserRole::Faculty, 'professor.dup-subject@grc.test');

        $this->withToken($token)->postJson('/api/v1/faculty-subject-preferences', [
            'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'rank' => 1,
        ])->assertCreated();

        $response = $this->withToken($token)->postJson('/api/v1/faculty-subject-preferences', [
            'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'rank' => 2,
        ]);
        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $rankResponse = $this->withToken($token)->postJson('/api/v1/faculty-subject-preferences', [
            'academic_term_id' => $term->id, 'subject_id' => $otherSubject->id, 'rank' => 1,
        ]);
        $rankResponse->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_a_faculty_member_sees_only_their_own_preferences_in_the_index(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject('CS101');
        [$professorA, $tokenA] = $this->tokenFor(UserRole::Faculty, 'professor.a@grc.test');
        [$professorB] = $this->tokenFor(UserRole::Faculty, 'professor.b@grc.test');

        FacultySubjectPreference::create([
            'professor_id' => $professorA->id, 'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'rank' => 1,
        ]);
        FacultySubjectPreference::create([
            'professor_id' => $professorB->id, 'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'rank' => 1,
        ]);

        $response = $this->withToken($tokenA)->getJson('/api/v1/faculty-subject-preferences');

        $response->assertOk();
        self::assertSame([$professorA->id], collect($response->json('data'))->pluck('professor_id')->all());
    }

    public function test_a_faculty_member_cannot_delete_another_professors_preference(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject('CS101');
        [$owner] = $this->tokenFor(UserRole::Faculty, 'owner@grc.test');
        [, $otherToken] = $this->tokenFor(UserRole::Faculty, 'other@grc.test');

        $preference = FacultySubjectPreference::create([
            'professor_id' => $owner->id, 'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'rank' => 1,
        ]);

        $response = $this->withToken($otherToken)->deleteJson("/api/v1/faculty-subject-preferences/{$preference->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('faculty_subject_preferences', ['id' => $preference->id]);
    }

    public function test_a_faculty_member_can_update_their_own_preference_rank(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject('CS101');
        [$owner, $token] = $this->tokenFor(UserRole::Faculty, 'owner.update@grc.test');

        $preference = FacultySubjectPreference::create([
            'professor_id' => $owner->id, 'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'rank' => 1,
        ]);

        $response = $this->withToken($token)->patchJson("/api/v1/faculty-subject-preferences/{$preference->id}", [
            'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'rank' => 2,
        ]);

        $response->assertOk()->assertJsonPath('data.rank', 2);
    }

    public function test_a_faculty_member_can_save_and_read_a_curriculum_semester_preference_without_exposing_another_college_catalog(): void
    {
        $subject = $this->makeSubject('CS201');
        $otherSubject = $this->makeSubject('ED201');
        $ccsProgram = Program::create([
            'code' => 'BSIT', 'name' => 'Information Technology', 'college' => CollegeCode::Ccs, 'status' => ProgramStatus::Active,
        ]);
        $coeProgram = Program::create([
            'code' => 'BSED', 'name' => 'Secondary Education', 'college' => CollegeCode::Coe, 'status' => ProgramStatus::Active,
        ]);
        $ccsCurriculum = Curriculum::create([
            'program_id' => $ccsProgram->id, 'name' => '2024–2029', 'effective_school_year' => '2024-2029',
            'effective_start_year' => 2024, 'effective_end_year' => 2029, 'status' => CurriculumStatus::Active,
        ]);
        $coeCurriculum = Curriculum::create([
            'program_id' => $coeProgram->id, 'name' => '2024–2029', 'effective_school_year' => '2024-2029',
            'effective_start_year' => 2024, 'effective_end_year' => 2029, 'status' => CurriculumStatus::Active,
        ]);
        CurriculumSubject::create(['curriculum_id' => $ccsCurriculum->id, 'subject_id' => $subject->id, 'year_level' => 2, 'semester' => '1st', 'is_required' => true]);
        CurriculumSubject::create(['curriculum_id' => $coeCurriculum->id, 'subject_id' => $otherSubject->id, 'year_level' => 2, 'semester' => '1st', 'is_required' => true]);
        [$faculty, $token] = $this->tokenFor(UserRole::Faculty, 'professor.catalog@grc.test');
        $faculty->update(['college' => CollegeCode::Ccs]);

        $catalog = $this->withToken($token)->getJson('/api/v1/faculty-preference-catalog');
        $catalog->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.curriculum_id', $ccsCurriculum->id);

        $response = $this->withToken($token)->postJson('/api/v1/faculty-curriculum-subject-preferences', [
            'curriculum_id' => $ccsCurriculum->id,
            'semester' => '1st',
            'subject_id' => $subject->id,
            'rank' => 1,
        ]);

        $response->assertCreated()->assertJsonPath('data.origin', 'declared');
        $this->assertDatabaseHas('faculty_curriculum_subject_preferences', [
            'professor_id' => $faculty->id, 'curriculum_id' => $ccsCurriculum->id, 'subject_id' => $subject->id, 'semester' => '1st',
        ]);
        $this->withToken($token)->getJson('/api/v1/faculty-curriculum-subject-preferences')
            ->assertOk()->assertJsonPath('data.0.professor_id', $faculty->id);
        $this->withToken($token)->getJson('/api/v1/faculty-teaching-history')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(1, FacultyCurriculumSubjectPreference::query()->count());
    }

    public function test_omitting_rank_appends_after_the_seeded_preferences(): void
    {
        $program = Program::create([
            'code' => 'BSIT',
            'name' => 'Information Technology',
            'college' => CollegeCode::Ccs,
            'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => '2024–2029',
            'effective_school_year' => '2024-2029',
            'effective_start_year' => 2024,
            'effective_end_year' => 2029,
            'status' => CurriculumStatus::Active,
        ]);
        $subjects = collect(['CS301', 'CS302', 'CS303', 'CS304'])->map(
            fn (string $code): Subject => $this->makeSubject($code),
        );
        $subjects->each(fn (Subject $subject) => CurriculumSubject::create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => 3,
            'semester' => '1st',
            'is_required' => true,
        ]));
        [$professor, $token] = $this->tokenFor(UserRole::Faculty, 'professor.omitting-rank@grc.test');
        $professor->update(['college' => CollegeCode::Ccs]);

        $subjects->take(3)->each(fn (Subject $subject, int $index) => FacultyCurriculumSubjectPreference::create([
            'professor_id' => $professor->id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'semester' => '1st',
            'rank' => $index + 1,
            'origin' => 'workbook_seeded',
        ]));

        $response = $this->withToken($token)->postJson('/api/v1/faculty-curriculum-subject-preferences', [
            'curriculum_id' => $curriculum->id,
            'semester' => '1st',
            'subject_id' => $subjects->last()->id,
        ]);

        $response->assertCreated()->assertJsonPath('data.rank', 4);
        $this->assertDatabaseHas('faculty_curriculum_subject_preferences', [
            'professor_id' => $professor->id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subjects->last()->id,
            'semester' => '1st',
            'rank' => 4,
            'origin' => 'declared',
        ]);
    }

    public function test_deleting_a_preference_also_removes_the_declared_specialization_for_that_subject(): void
    {
        [$curriculum, $subjects] = $this->curriculumWithSubjects(['CS401', 'CS402']);
        [$professor, $token] = $this->tokenFor(UserRole::Faculty, 'professor.cascade@grc.test');
        $professor->update(['college' => CollegeCode::Ccs]);

        $preferences = $subjects->map(function (Subject $subject, int $index) use ($professor, $curriculum): FacultyCurriculumSubjectPreference {
            FacultySpecialization::create([
                'professor_id' => $professor->id, 'subject_id' => $subject->id,
                'proficiency' => 'primary', 'source' => $index === 0 ? 'declared' : 'workbook_seeded',
            ]);

            return FacultyCurriculumSubjectPreference::create([
                'professor_id' => $professor->id, 'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
                'semester' => '1st', 'rank' => $index + 1, 'origin' => 'declared',
            ]);
        });

        $this->withToken($token)->deleteJson("/api/v1/faculty-curriculum-subject-preferences/{$preferences[0]->id}")->assertNoContent();
        $this->withToken($token)->deleteJson("/api/v1/faculty-curriculum-subject-preferences/{$preferences[1]->id}")->assertNoContent();

        // The declared specialization went with its preference...
        $this->assertDatabaseMissing('faculty_specializations', ['professor_id' => $professor->id, 'subject_id' => $subjects[0]->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::FACULTY_SPECIALIZATION_DELETED]);
        // ...a seeded (workbook) one is evidence and stays.
        $this->assertDatabaseHas('faculty_specializations', ['professor_id' => $professor->id, 'subject_id' => $subjects[1]->id]);
    }

    public function test_a_professor_can_change_the_proficiency_of_their_declared_specialization(): void
    {
        [$professor, $token] = $this->tokenFor(UserRole::Faculty, 'professor.proficiency@grc.test');
        $subject = $this->makeSubject('CS501');
        $specialization = FacultySpecialization::create([
            'professor_id' => $professor->id, 'subject_id' => $subject->id, 'proficiency' => 'secondary',
            'source' => 'declared', 'status' => 'approved', 'decided_by' => $professor->id, 'decided_at' => now(),
        ]);

        $this->withToken($token)
            ->patchJson("/api/v1/faculty-specializations/{$specialization->id}/proficiency", ['proficiency' => 'primary'])
            ->assertOk()
            ->assertJsonPath('data.proficiency', 'primary')
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('faculty_specializations', ['id' => $specialization->id, 'proficiency' => 'primary', 'status' => 'pending', 'decided_by' => null]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::FACULTY_SPECIALIZATION_UPDATED, 'auditable_id' => $specialization->id]);

        // Saving the same value again changes nothing (stays pending, no extra audit row).
        $this->withToken($token)
            ->patchJson("/api/v1/faculty-specializations/{$specialization->id}/proficiency", ['proficiency' => 'primary'])
            ->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::FACULTY_SPECIALIZATION_UPDATED)->count());
    }

    public function test_proficiency_cannot_be_changed_for_another_professors_or_a_non_declared_specialization(): void
    {
        [$professor, $token] = $this->tokenFor(UserRole::Faculty, 'professor.proficiency-own@grc.test');
        [$other] = $this->tokenFor(UserRole::Faculty, 'professor.proficiency-other@grc.test');
        $subject = $this->makeSubject('CS502');
        $seededSubject = $this->makeSubject('CS503');
        $others = FacultySpecialization::create([
            'professor_id' => $other->id, 'subject_id' => $subject->id, 'proficiency' => 'secondary', 'source' => 'declared',
        ]);
        $seeded = FacultySpecialization::create([
            'professor_id' => $professor->id, 'subject_id' => $seededSubject->id, 'proficiency' => 'secondary', 'source' => 'workbook_seeded',
        ]);

        $this->withToken($token)
            ->patchJson("/api/v1/faculty-specializations/{$others->id}/proficiency", ['proficiency' => 'primary'])
            ->assertForbidden();
        $this->withToken($token)
            ->patchJson("/api/v1/faculty-specializations/{$seeded->id}/proficiency", ['proficiency' => 'primary'])
            ->assertForbidden();

        $mine = FacultySpecialization::create([
            'professor_id' => $professor->id, 'subject_id' => $this->makeSubject('CS504')->id, 'proficiency' => 'secondary', 'source' => 'declared',
        ]);
        $this->withToken($token)
            ->patchJson("/api/v1/faculty-specializations/{$mine->id}/proficiency", ['proficiency' => 'expert'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('faculty_specializations', ['id' => $mine->id, 'proficiency' => 'secondary']);
        $this->assertDatabaseHas('faculty_specializations', ['id' => $others->id, 'proficiency' => 'secondary']);
        $this->assertDatabaseHas('faculty_specializations', ['id' => $seeded->id, 'proficiency' => 'secondary']);
    }

    public function test_deleting_a_preference_keeps_the_specialization_while_another_preference_still_names_the_subject(): void
    {
        [$curriculum, $subjects] = $this->curriculumWithSubjects(['CS403']);
        [$professor, $token] = $this->tokenFor(UserRole::Faculty, 'professor.cascade-keep@grc.test');
        $professor->update(['college' => CollegeCode::Ccs]);
        FacultySpecialization::create(['professor_id' => $professor->id, 'subject_id' => $subjects[0]->id, 'proficiency' => 'secondary', 'source' => 'declared']);
        $first = FacultyCurriculumSubjectPreference::create([
            'professor_id' => $professor->id, 'curriculum_id' => $curriculum->id, 'subject_id' => $subjects[0]->id,
            'semester' => '1st', 'rank' => 1, 'origin' => 'declared',
        ]);
        FacultyCurriculumSubjectPreference::create([
            'professor_id' => $professor->id, 'curriculum_id' => $curriculum->id, 'subject_id' => $subjects[0]->id,
            'semester' => '2nd', 'rank' => 1, 'origin' => 'declared',
        ]);

        $this->withToken($token)->deleteJson("/api/v1/faculty-curriculum-subject-preferences/{$first->id}")->assertNoContent();

        $this->assertDatabaseHas('faculty_specializations', ['professor_id' => $professor->id, 'subject_id' => $subjects[0]->id]);
    }

    /**
     * @param  list<string>  $codes
     * @return array{0: Curriculum, 1: Collection<int, Subject>}
     */
    private function curriculumWithSubjects(array $codes): array
    {
        $program = Program::create([
            'code' => 'BSIT', 'name' => 'Information Technology', 'college' => CollegeCode::Ccs, 'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => '2024–2029', 'effective_school_year' => '2024-2029',
            'effective_start_year' => 2024, 'effective_end_year' => 2029, 'status' => CurriculumStatus::Active,
        ]);
        $subjects = collect($codes)->map(fn (string $code): Subject => $this->makeSubject($code));
        $subjects->each(fn (Subject $subject) => CurriculumSubject::create([
            'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
            'year_level' => 3, 'semester' => '1st', 'is_required' => true,
        ]));

        return [$curriculum, $subjects];
    }
}
