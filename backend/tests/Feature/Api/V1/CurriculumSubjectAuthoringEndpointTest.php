<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditableType;
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
use App\Models\Program;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CurriculumSubjectAuthoringEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_current_curriculum_subjects_returns_only_distinct_subjects_from_the_current_term_active_source(): void
    {
        $token = $this->chairToken(CollegeCode::Ccs);
        $program = $this->program('BSCS', CollegeCode::Ccs);
        $old = $this->curriculum($program, '2025-2026', CurriculumStatus::Active, 'Old source');
        $current = $this->curriculum($program, '2026-2027', CurriculumStatus::Active, 'Current source');
        $newer = $this->curriculum($program, '2027-2028', CurriculumStatus::Active, 'Newer source');
        $oldSubject = $this->subject('CS-OLD', CollegeCode::Ccs);
        $currentSubject = $this->subject('CS-CURRENT', CollegeCode::Ccs);
        $newerSubject = $this->subject('CS-NEWER', CollegeCode::Ccs);
        $this->place($old, $oldSubject);
        $this->place($current, $currentSubject);
        $this->place($newer, $newerSubject);
        $this->setCurrentTerm('2026-2027');

        $this->withToken($token)
            ->getJson("/api/v1/programs/{$program->id}/current-curriculum-subjects")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $currentSubject->id)
            ->assertJsonMissing(['id' => $oldSubject->id])
            ->assertJsonMissing(['id' => $newerSubject->id]);
    }

    public function test_current_curriculum_subjects_falls_back_to_latest_active_source_and_returns_an_empty_collection_when_none_exists(): void
    {
        $token = $this->chairToken(CollegeCode::Ccs);
        $program = $this->program('BSCS', CollegeCode::Ccs);
        $fallback = $this->curriculum($program, '2027-2028', CurriculumStatus::Active, 'A fallback source');
        $fallbackSubject = $this->subject('CS-FALLBACK', CollegeCode::Ccs);
        $this->place($fallback, $fallbackSubject);
        $this->setCurrentTerm('2026-2027');

        $this->withToken($token)
            ->getJson("/api/v1/programs/{$program->id}/current-curriculum-subjects")
            ->assertOk()
            ->assertJsonPath('data.0.id', $fallbackSubject->id);

        $emptyProgram = $this->program('BSIT', CollegeCode::Ccs);
        $this->withToken($token)
            ->getJson("/api/v1/programs/{$emptyProgram->id}/current-curriculum-subjects")
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_another_colleges_program_chair_cannot_read_or_add_subject_placements(): void
    {
        $token = $this->chairToken(CollegeCode::Ccs);
        $coeProgram = $this->program('BSCOE', CollegeCode::Coe);
        $coeDraft = $this->curriculum($coeProgram, '2026-2027', CurriculumStatus::Draft, 'COE Draft');
        $this->setCurrentTerm('2026-2027');

        $this->withToken($token)
            ->getJson("/api/v1/programs/{$coeProgram->id}/current-curriculum-subjects")
            ->assertForbidden();

        $this->withToken($token)
            ->postJson("/api/v1/curricula/{$coeDraft->id}/subject-placements", $this->newPayload('COE-UNAUTHORIZED'))
            ->assertForbidden();
    }

    public function test_existing_placement_rejects_an_old_or_foreign_subject_and_only_a_current_source_subject_is_accepted(): void
    {
        $token = $this->chairToken(CollegeCode::Ccs);
        $program = $this->program('BSCS', CollegeCode::Ccs);
        $draft = $this->curriculum($program, '2026-2027', CurriculumStatus::Draft, 'Draft');
        $oldSource = $this->curriculum($program, '2025-2026', CurriculumStatus::Active, 'Old source');
        $currentSource = $this->curriculum($program, '2026-2027', CurriculumStatus::Active, 'Current source');
        $oldSubject = $this->subject('CS-OLD', CollegeCode::Ccs);
        $currentSubject = $this->subject('CS-CURRENT', CollegeCode::Ccs);
        $foreignSubject = $this->subject('COE-FOREIGN', CollegeCode::Coe);
        $this->place($oldSource, $oldSubject);
        $this->place($currentSource, $currentSubject);
        $this->setCurrentTerm('2026-2027');

        $this->withToken($token)
            ->postJson("/api/v1/curricula/{$draft->id}/subject-placements", $this->existingPayload($oldSubject->id))
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.subject_id.0', 'The selected subject is not in this program\'s current curriculum source.');
        $this->withToken($token)
            ->postJson("/api/v1/curricula/{$draft->id}/subject-placements", $this->existingPayload($foreignSubject->id))
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.subject_id.0', 'The selected subject is not in this program\'s current curriculum source.');

        $this->withToken($token)
            ->postJson("/api/v1/curricula/{$draft->id}/subject-placements", $this->existingPayload($currentSubject->id))
            ->assertOk()
            ->assertJsonPath('data.subjects.0.subject_id', $currentSubject->id);

        $this->assertDatabaseHas('curriculum_subjects', [
            'curriculum_id' => $draft->id,
            'subject_id' => $currentSubject->id,
            'year_level' => 1,
            'semester' => '1st',
            'is_required' => true,
        ]);
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::CURRICULUM_UPDATED)->count());
        self::assertSame(0, AuditLog::query()->where('action', 'subject.created')->count());
    }

    public function test_new_source_creates_a_college_owned_subject_and_placement_atomically_with_both_audit_events(): void
    {
        $token = $this->chairToken(CollegeCode::Ccs);
        $program = $this->program('BSCS', CollegeCode::Ccs);
        $draft = $this->curriculum($program, '2026-2027', CurriculumStatus::Draft, 'Draft');
        $this->setCurrentTerm('2026-2027');

        $this->withToken($token)
            ->postJson("/api/v1/curricula/{$draft->id}/subject-placements", $this->newPayload('CS-NEW'))
            ->assertOk()
            ->assertJsonPath('data.subjects.0.code', 'CS-NEW')
            ->assertJsonPath('data.subjects.0.year_level', 1)
            ->assertJsonPath('data.subjects.0.semester', '1st')
            ->assertJsonPath('data.subjects.0.is_required', true)
            ->assertJsonPath('data.subjects.0.prerequisites', []);

        $subject = Subject::query()->where('code', 'CS-NEW')->sole();
        $this->assertDatabaseHas('subjects', [
            'id' => $subject->id,
            'college' => CollegeCode::Ccs->value,
            'title' => 'New subject',
            'units' => 3,
            'status' => SubjectStatus::Active->value,
        ]);
        $this->assertDatabaseHas('curriculum_subjects', ['curriculum_id' => $draft->id, 'subject_id' => $subject->id]);
        self::assertSame(1, AuditLog::query()->where('action', 'subject.created')->where('auditable_type', AuditableType::SUBJECT)->count());
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::CURRICULUM_UPDATED)->where('auditable_type', AuditableType::CURRICULUM)->count());
    }

    public function test_a_new_subject_can_map_one_old_subject_from_its_curriculums_configured_equivalency_source(): void
    {
        $token = $this->chairToken(CollegeCode::Ccs);
        $program = $this->program('BSCS', CollegeCode::Ccs);
        $source = $this->curriculum($program, '2021-2022', CurriculumStatus::Archived, 'BSCS 2021 Curriculum');
        $oldSubject = $this->subject('CS-OLD', CollegeCode::Ccs);
        $this->place($source, $oldSubject);
        $draft = $this->curriculum($program, '2026-2027', CurriculumStatus::Draft, 'BSCS 2026 Curriculum');
        $draft->update(['equivalency_source_curriculum_id' => $source->id]);
        $this->setCurrentTerm('2026-2027');

        $this->withToken($token)
            ->postJson("/api/v1/curricula/{$draft->id}/subject-placements", [
                ...$this->newPayload('CS-NEW'),
                'equivalent_source_subject_id' => $oldSubject->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.subjects.0.equivalent_source_subject_id', $oldSubject->id)
            ->assertJsonPath('data.subjects.0.equivalent_source_subject_code', 'CS-OLD');

        $newSubject = Subject::query()->where('code', 'CS-NEW')->sole();
        $this->assertDatabaseHas('curriculum_subject_equivalencies', [
            'source_curriculum_id' => $source->id,
            'target_curriculum_id' => $draft->id,
            'source_subject_id' => $oldSubject->id,
            'target_subject_id' => $newSubject->id,
        ]);
    }

    public function test_duplicate_subject_code_or_duplicate_draft_placement_is_invalid_without_an_extra_placement(): void
    {
        $token = $this->chairToken(CollegeCode::Ccs);
        $program = $this->program('BSCS', CollegeCode::Ccs);
        $draft = $this->curriculum($program, '2026-2027', CurriculumStatus::Draft, 'Draft');
        $source = $this->curriculum($program, '2026-2027', CurriculumStatus::Active, 'Current source');
        $existing = $this->subject('CS-EXISTING', CollegeCode::Ccs);
        $this->place($source, $existing);
        $this->setCurrentTerm('2026-2027');

        $this->withToken($token)
            ->postJson("/api/v1/curricula/{$draft->id}/subject-placements", $this->newPayload('CS-EXISTING'))
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.code.0', 'The code has already been taken.');
        self::assertSame(0, CurriculumSubject::query()->where('curriculum_id', $draft->id)->count());

        $this->withToken($token)
            ->postJson("/api/v1/curricula/{$draft->id}/subject-placements", $this->existingPayload($existing->id))
            ->assertOk();
        $this->withToken($token)
            ->postJson("/api/v1/curricula/{$draft->id}/subject-placements", $this->existingPayload($existing->id))
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.subject_id.0', 'The selected subject is already placed in this Draft curriculum.');
        self::assertSame(1, CurriculumSubject::query()->where('curriculum_id', $draft->id)->count());
    }

    public function test_pending_curriculum_cannot_add_a_subject_placement(): void
    {
        $token = $this->chairToken(CollegeCode::Ccs);
        $program = $this->program('BSCS', CollegeCode::Ccs);
        $pending = $this->curriculum($program, '2026-2027', CurriculumStatus::PendingDeanReview, 'Pending');
        $this->setCurrentTerm('2026-2027');

        $this->withToken($token)
            ->postJson("/api/v1/curricula/{$pending->id}/subject-placements", $this->newPayload('CS-PENDING'))
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.status.0', 'Only a Draft curriculum can be edited.');

        $this->assertDatabaseMissing('subjects', ['code' => 'CS-PENDING']);
        self::assertSame(0, CurriculumSubject::query()->where('curriculum_id', $pending->id)->count());
    }

    public function test_program_chair_can_update_curriculum_max_units(): void
    {
        $token = $this->chairToken(CollegeCode::Ccs);
        $program = $this->program('BSCS', CollegeCode::Ccs);
        $curriculum = $this->curriculum($program, '2026-2027', CurriculumStatus::Active, 'BSCS Curriculum');
        $subject = $this->subject('CS101', CollegeCode::Ccs);
        $this->place($curriculum, $subject);

        $response = $this->withToken($token)
            ->putJson("/api/v1/curricula/{$curriculum->id}/max-units", [
                'max_units' => 33.5,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.max_units', 33.5);
        $response->assertJsonPath('data.effective_max_units', 33.5);
        $curriculum->refresh();
        self::assertSame('33.5', (string) $curriculum->max_units);
        self::assertSame(AuditAction::CURRICULUM_UPDATED, AuditLog::query()->latest('id')->first()->action);
    }

    public function test_program_chair_can_reset_curriculum_max_units_to_null(): void
    {
        $token = $this->chairToken(CollegeCode::Ccs);
        $program = $this->program('BSCS', CollegeCode::Ccs);
        $curriculum = $this->curriculum($program, '2026-2027', CurriculumStatus::Active, 'BSCS Curriculum');
        $curriculum->update(['max_units' => 35.0]);
        $subject = $this->subject('CS101', CollegeCode::Ccs);
        $this->place($curriculum, $subject);

        $response = $this->withToken($token)
            ->putJson("/api/v1/curricula/{$curriculum->id}/max-units", [
                'max_units' => null,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.max_units', null);
        $curriculum->refresh();
        self::assertNull($curriculum->max_units);
    }

    private function chairToken(CollegeCode $college): string
    {
        $email = strtolower($college->value).'.chair@grc.test';
        User::create([
            'name' => $college->value.' Program Chair',
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => UserRole::ProgramChair,
            'college' => $college,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function program(string $code, CollegeCode $college): Program
    {
        return Program::create([
            'code' => $code,
            'name' => $code.' Program',
            'college' => $college,
            'status' => ProgramStatus::Active,
        ]);
    }

    private function curriculum(Program $program, string $schoolYear, CurriculumStatus $status, string $name): Curriculum
    {
        return Curriculum::create([
            'program_id' => $program->id,
            'name' => $name,
            'effective_school_year' => $schoolYear,
            'status' => $status,
        ]);
    }

    private function subject(string $code, CollegeCode $college): Subject
    {
        return Subject::create([
            'code' => $code,
            'college' => $college,
            'title' => $code.' title',
            'units' => 3,
            'status' => SubjectStatus::Active,
        ]);
    }

    private function place(Curriculum $curriculum, Subject $subject, int $yearLevel = 1, string $semester = '1st'): void
    {
        CurriculumSubject::create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => $yearLevel,
            'semester' => $semester,
            'is_required' => true,
        ]);
    }

    private function setCurrentTerm(string $schoolYear): void
    {
        $term = AcademicTerm::create([
            'school_year' => $schoolYear,
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        DB::table('academic_term_current_slots')->where('id', 1)->update(['academic_term_id' => $term->id]);
    }

    /** @return array{source: string, code: string, title: string, units: int, year_level: int, semester: string} */
    private function newPayload(string $code): array
    {
        return [
            'source' => 'new',
            'code' => $code,
            'title' => 'New subject',
            'units' => 3,
            'year_level' => 1,
            'semester' => '1st',
        ];
    }

    /** @return array{source: string, subject_id: int, year_level: int, semester: string} */
    private function existingPayload(int $subjectId): array
    {
        return [
            'source' => 'existing',
            'subject_id' => $subjectId,
            'year_level' => 1,
            'semester' => '1st',
        ];
    }
}
