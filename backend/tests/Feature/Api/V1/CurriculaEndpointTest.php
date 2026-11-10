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
use App\Models\CurriculumSubjectEquivalency;
use App\Models\Program;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CurriculaEndpointTest extends TestCase
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

    private function makeProgram(): Program
    {
        return Program::create([
            'code' => 'BSCS',
            'name' => 'BS Computer Science',
            'college' => CollegeCode::Ccs,
            'status' => ProgramStatus::Active,
        ]);
    }

    private function setCurrentAcademicTerm(string $schoolYear = '2026-2027'): AcademicTerm
    {
        $term = AcademicTerm::create([
            'school_year' => $schoolYear,
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        DB::table('academic_term_current_slots')->where('id', 1)->update([
            'academic_term_id' => $term->id,
        ]);

        return $term;
    }

    /** @return array{0: Subject, 1: Subject} */
    private function makeTwoSubjects(): array
    {
        return [
            Subject::create(['code' => 'CS101', 'title' => 'Intro to Programming', 'units' => 3, 'status' => SubjectStatus::Active]),
            Subject::create(['code' => 'CS102', 'title' => 'Data Structures', 'units' => 3, 'status' => SubjectStatus::Active]),
        ];
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/curricula')->assertUnauthorized();
        $this->postJson('/api/v1/curricula', [])->assertUnauthorized();
    }

    public function test_a_learner_scoped_role_does_not_see_a_draft_curriculum(): void
    {
        $program = $this->makeProgram();
        Curriculum::create(['program_id' => $program->id, 'name' => 'Active Curriculum', 'effective_school_year' => '2025-2026', 'status' => CurriculumStatus::Active]);
        Curriculum::create(['program_id' => $program->id, 'name' => 'Draft Curriculum', 'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Draft]);
        $token = $this->tokenFor(UserRole::Faculty, 'faculty.curricula@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/curricula');

        $response
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active Curriculum');
    }

    public function test_a_dean_sees_only_curricula_from_their_college(): void
    {
        $ccsProgram = Program::create([
            'code' => 'BSCS',
            'name' => 'BS Computer Science',
            'college' => CollegeCode::Ccs,
            'status' => ProgramStatus::Active,
        ]);
        $cbaeProgram = Program::create([
            'code' => 'BSA',
            'name' => 'BS Accountancy',
            'college' => CollegeCode::Cbae,
            'status' => ProgramStatus::Active,
        ]);
        Curriculum::create([
            'program_id' => $ccsProgram->id,
            'name' => 'CCS Curriculum',
            'effective_school_year' => '2026-2027',
            'status' => CurriculumStatus::PendingDeanReview,
        ]);
        Curriculum::create([
            'program_id' => $cbaeProgram->id,
            'name' => 'CBAE Curriculum',
            'effective_school_year' => '2026-2027',
            'status' => CurriculumStatus::PendingDeanReview,
        ]);
        $token = $this->tokenFor(UserRole::Dean, 'cbae.dean@grc.test', CollegeCode::Cbae);

        $this->withToken($token)
            ->getJson('/api/v1/curricula')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'CBAE Curriculum');
    }

    public function test_a_program_chair_sees_only_programs_from_their_college(): void
    {
        Program::create([
            'code' => 'BSCS',
            'name' => 'BS Computer Science',
            'college' => CollegeCode::Ccs,
            'status' => ProgramStatus::Active,
        ]);
        Program::create([
            'code' => 'BSED',
            'name' => 'BS Education',
            'college' => CollegeCode::Coe,
            'status' => ProgramStatus::Active,
        ]);
        $token = $this->tokenFor(UserRole::ProgramChair, 'ccs.chair.programs@grc.test', CollegeCode::Ccs);

        $this->withToken($token)
            ->getJson('/api/v1/programs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'BSCS');
    }

    public function test_a_program_chair_without_a_college_sees_no_authorable_programs(): void
    {
        $this->makeProgram();
        $token = $this->tokenFor(UserRole::ProgramChair, 'unassigned.chair.programs@grc.test');

        $this->withToken($token)
            ->getJson('/api/v1/programs')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_program_chair_can_create_a_curriculum_for_a_program_in_their_college(): void
    {
        $this->setCurrentAcademicTerm();
        $program = $this->makeProgram();
        $token = $this->tokenFor(UserRole::ProgramChair, 'ccs.chair.create@grc.test', CollegeCode::Ccs);

        $this->withToken($token)
            ->postJson('/api/v1/curricula', [
                'program_id' => $program->id,
                'name' => 'CCS Curriculum',
                'subjects' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('data.effective_school_year', '2026-2027');
    }

    public function test_a_program_chair_selects_an_approved_same_program_curriculum_as_the_equivalency_source(): void
    {
        $this->setCurrentAcademicTerm();
        $program = $this->makeProgram();
        $source = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSCS 2021 Curriculum',
            'effective_school_year' => '2021-2022',
            'status' => CurriculumStatus::Archived,
        ]);
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.equivalency-source@grc.test', CollegeCode::Ccs);

        $this->withToken($token)
            ->postJson('/api/v1/curricula', [
                'program_id' => $program->id,
                'name' => 'BSCS 2026 Curriculum',
                'equivalency_source_curriculum_id' => $source->id,
                'subjects' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('data.equivalency_source_curriculum_id', $source->id)
            ->assertJsonPath('data.equivalency_source_curriculum_name', 'BSCS 2021 Curriculum');
    }

    public function test_a_program_chair_cannot_select_a_source_curriculum_from_another_program(): void
    {
        $this->setCurrentAcademicTerm();
        $program = $this->makeProgram();
        $otherProgram = Program::create([
            'code' => 'BSIT', 'name' => 'BS Information Technology',
            'college' => CollegeCode::Ccs, 'status' => ProgramStatus::Active,
        ]);
        $otherSource = Curriculum::create([
            'program_id' => $otherProgram->id,
            'name' => 'BSIT 2021 Curriculum',
            'effective_school_year' => '2021-2022',
            'status' => CurriculumStatus::Archived,
        ]);
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.invalid-equivalency-source@grc.test', CollegeCode::Ccs);

        $this->withToken($token)
            ->postJson('/api/v1/curricula', [
                'program_id' => $program->id,
                'name' => 'Invalid Source Curriculum',
                'equivalency_source_curriculum_id' => $otherSource->id,
                'subjects' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.errors.equivalency_source_curriculum_id.0',
                'Select an active or archived curriculum from the same program.',
            );
    }

    public function test_a_program_chair_cannot_create_a_curriculum_for_another_colleges_program(): void
    {
        $program = Program::create([
            'code' => 'BSED',
            'name' => 'BS Education',
            'college' => CollegeCode::Coe,
            'status' => ProgramStatus::Active,
        ]);
        $token = $this->tokenFor(UserRole::ProgramChair, 'ccs.chair.denied@grc.test', CollegeCode::Ccs);

        $this->withToken($token)
            ->postJson('/api/v1/curricula', [
                'program_id' => $program->id,
                'name' => 'COE Curriculum',
                'effective_school_year' => '2026-2027',
                'subjects' => [],
            ])
            ->assertForbidden();
    }

    public function test_a_program_chair_without_a_college_cannot_create_a_curriculum(): void
    {
        $program = $this->makeProgram();
        $token = $this->tokenFor(UserRole::ProgramChair, 'unassigned.chair.create@grc.test');

        $this->withToken($token)
            ->postJson('/api/v1/curricula', [
                'program_id' => $program->id,
                'name' => 'Unowned Curriculum',
                'effective_school_year' => '2026-2027',
                'subjects' => [],
            ])
            ->assertForbidden();
    }

    public function test_a_forged_effective_school_year_is_replaced_with_the_current_terms_school_year(): void
    {
        $this->setCurrentAcademicTerm('2026-2027');
        $program = $this->makeProgram();
        $token = $this->tokenFor(UserRole::ProgramChair, 'ccs.chair.forged-year@grc.test', CollegeCode::Ccs);

        $this->withToken($token)
            ->postJson('/api/v1/curricula', [
                'program_id' => $program->id,
                'name' => 'Server Owned School Year',
                'effective_school_year' => '1999-2000',
                'subjects' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('data.effective_school_year', '2026-2027');
    }

    public function test_a_program_chair_can_create_a_curriculum_with_subjects_and_prerequisites(): void
    {
        $this->setCurrentAcademicTerm();
        $program = $this->makeProgram();
        [$intro, $dataStructures] = $this->makeTwoSubjects();
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.create@grc.test', CollegeCode::Ccs);

        $response = $this->withToken($token)->postJson('/api/v1/curricula', [
            'program_id' => $program->id,
            'name' => 'BSCS 2026 Curriculum',
            'effective_school_year' => '2026-2027',
            'subjects' => [
                ['subject_id' => $intro->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true],
                [
                    'subject_id' => $dataStructures->id, 'year_level' => 1, 'semester' => '2nd', 'is_required' => true,
                    'prerequisites' => [
                        ['prerequisite_subject_id' => $intro->id, 'minimum_grade' => '75'],
                    ],
                ],
            ],
        ]);

        $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.name', 'BSCS 2026 Curriculum');
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonCount(2, 'data.subjects');
        $response->assertJsonPath('data.subjects.1.code', 'CS102');
        $response->assertJsonCount(1, 'data.subjects.1.prerequisites');
        $response->assertJsonPath(
            'data.subjects.1.prerequisites.0.prerequisite_subject_id',
            $intro->id,
        );

        $this->assertDatabaseHas('curricula', ['name' => 'BSCS 2026 Curriculum']);
        $this->assertDatabaseCount('curriculum_subjects', 2);
        $this->assertDatabaseCount('subject_prerequisites', 1);
        self::assertSame(
            AuditAction::CURRICULUM_CREATED,
            AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action,
        );
    }

    public function test_a_non_program_chair_role_cannot_create_a_curriculum(): void
    {
        $program = $this->makeProgram();
        $token = $this->tokenFor(UserRole::Dean, 'dean.create@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/curricula', [
            'program_id' => $program->id,
            'name' => 'Should Not Exist',
            'effective_school_year' => '2026-2027',
            'subjects' => [],
        ]);

        $response->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertDatabaseMissing('curricula', ['name' => 'Should Not Exist']);
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    /**
     * Direct acceptance criterion from PRD §5.1: "A Program Chair cannot
     * create a prerequisite cycle."
     */
    public function test_a_program_chair_cannot_create_a_direct_prerequisite_cycle(): void
    {
        $program = $this->makeProgram();
        [$a, $b] = $this->makeTwoSubjects();
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.cycle@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/curricula', [
            'program_id' => $program->id,
            'name' => 'Cyclic Curriculum',
            'effective_school_year' => '2026-2027',
            'subjects' => [
                ['subject_id' => $a->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true, 'prerequisites' => [
                    ['prerequisite_subject_id' => $b->id, 'minimum_grade' => '75'],
                ]],
                ['subject_id' => $b->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true, 'prerequisites' => [
                    ['prerequisite_subject_id' => $a->id, 'minimum_grade' => '75'],
                ]],
            ],
        ]);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertDatabaseMissing('curricula', ['name' => 'Cyclic Curriculum']);
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_a_program_chair_cannot_create_a_transitive_prerequisite_cycle(): void
    {
        $program = $this->makeProgram();
        $a = Subject::create(['code' => 'CS1', 'title' => 'Subject A', 'units' => 3, 'status' => SubjectStatus::Active]);
        $b = Subject::create(['code' => 'CS2', 'title' => 'Subject B', 'units' => 3, 'status' => SubjectStatus::Active]);
        $c = Subject::create(['code' => 'CS3', 'title' => 'Subject C', 'units' => 3, 'status' => SubjectStatus::Active]);
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.transitive-cycle@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/curricula', [
            'program_id' => $program->id,
            'name' => 'Transitively Cyclic Curriculum',
            'effective_school_year' => '2026-2027',
            'subjects' => [
                ['subject_id' => $a->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true, 'prerequisites' => [
                    ['prerequisite_subject_id' => $b->id, 'minimum_grade' => '75'],
                ]],
                ['subject_id' => $b->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true, 'prerequisites' => [
                    ['prerequisite_subject_id' => $c->id, 'minimum_grade' => '75'],
                ]],
                ['subject_id' => $c->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true, 'prerequisites' => [
                    ['prerequisite_subject_id' => $a->id, 'minimum_grade' => '75'],
                ]],
            ],
        ]);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_updating_a_curriculum_fully_replaces_its_subject_placements(): void
    {
        $this->setCurrentAcademicTerm();
        $program = $this->makeProgram();
        [$intro, $dataStructures] = $this->makeTwoSubjects();
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.update@grc.test', CollegeCode::Ccs);

        $created = $this->withToken($token)->postJson('/api/v1/curricula', [
            'program_id' => $program->id,
            'name' => 'BSCS 2026 Curriculum',
            'effective_school_year' => '2026-2027',
            'subjects' => [
                ['subject_id' => $intro->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true],
            ],
        ]);
        $curriculumId = $created->json('data.id');

        $response = $this->withToken($token)->patchJson("/api/v1/curricula/{$curriculumId}", [
            'name' => 'BSCS 2026 Curriculum (revised)',
            'effective_school_year' => '1999-2000',
            'subjects' => [
                ['subject_id' => $dataStructures->id, 'year_level' => 1, 'semester' => '2nd', 'is_required' => true],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'BSCS 2026 Curriculum (revised)');
        $response->assertJsonPath('data.effective_school_year', '2026-2027');
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonCount(1, 'data.subjects');
        $response->assertJsonPath('data.subjects.0.code', 'CS102');
        $this->assertDatabaseCount('curriculum_subjects', 1);
        self::assertSame(
            1,
            AuditLog::query()->where('action', AuditAction::CURRICULUM_UPDATED)->count(),
        );
    }

    /**
     * Regression coverage for a data-loss bug: the `reference_*` columns on
     * `curriculum_subjects` hold real schedule/faculty data transcribed from
     * GRC's official Excel files and seeded once by
     * `GrcCurriculumScheduleReferenceSeeder`. The Curriculum Editor autosaves
     * on a debounce and never sends those columns, so
     * `SynchronizeCurriculumSubjects`' delete-and-recreate used to null them
     * out on every single save, silently and irreversibly.
     */
    public function test_updating_a_curriculum_preserves_its_placements_reference_schedule_data(): void
    {
        $program = $this->makeProgram();
        [$intro, $dataStructures] = $this->makeTwoSubjects();
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS 2026 Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Draft,
        ]);
        CurriculumSubject::create([
            'curriculum_id' => $curriculum->id, 'subject_id' => $intro->id,
            'year_level' => 1, 'semester' => '1st', 'is_required' => true,
            'reference_day' => 'Tue', 'reference_start_time' => '07:30:00', 'reference_end_time' => '09:30:00',
            'reference_room' => 'ONLINE', 'reference_modality' => 'ONLINE',
            'reference_professor_name' => 'MR. MACINAS', 'reference_sched_id' => 'S-101',
            'reference_notes' => 'From the 2024-2029 sheet.',
        ]);
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.reference-data@grc.test', CollegeCode::Ccs);

        // The editor re-sends the untouched placement verbatim while adding
        // an unrelated second subject — the exact shape of an autosave.
        $response = $this->withToken($token)->patchJson("/api/v1/curricula/{$curriculum->id}", [
            'name' => 'BSCS 2026 Curriculum',
            'effective_school_year' => '2026-2027',
            'subjects' => [
                ['subject_id' => $intro->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true],
                ['subject_id' => $dataStructures->id, 'year_level' => 1, 'semester' => '2nd', 'is_required' => true],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('curriculum_subjects', [
            'curriculum_id' => $curriculum->id,
            'subject_id' => $intro->id,
            'reference_day' => 'Tue',
            'reference_start_time' => '07:30:00',
            'reference_end_time' => '09:30:00',
            'reference_room' => 'ONLINE',
            'reference_modality' => 'ONLINE',
            'reference_professor_name' => 'MR. MACINAS',
            'reference_sched_id' => 'S-101',
            'reference_notes' => 'From the 2024-2029 sheet.',
        ]);
        // A genuinely new placement has no reference data to carry forward,
        // and none is invented for it.
        $this->assertDatabaseHas('curriculum_subjects', [
            'curriculum_id' => $curriculum->id,
            'subject_id' => $dataStructures->id,
            'reference_day' => null,
            'reference_professor_name' => null,
        ]);
    }

    public function test_replacing_a_draft_curriculum_removes_only_equivalencies_whose_target_subject_was_removed(): void
    {
        $program = $this->makeProgram();
        [$oldSubject, $keptTarget, $removedTarget] = [
            Subject::create(['code' => 'CS-OLD', 'title' => 'Old Subject', 'units' => 3, 'status' => SubjectStatus::Active]),
            Subject::create(['code' => 'CS-KEEP', 'title' => 'Kept Subject', 'units' => 3, 'status' => SubjectStatus::Active]),
            Subject::create(['code' => 'CS-REMOVE', 'title' => 'Removed Subject', 'units' => 3, 'status' => SubjectStatus::Active]),
        ];
        $source = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSCS 2021 Curriculum',
            'effective_school_year' => '2021-2022',
            'status' => CurriculumStatus::Archived,
        ]);
        CurriculumSubject::create(['curriculum_id' => $source->id, 'subject_id' => $oldSubject->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true]);
        $draft = Curriculum::create([
            'program_id' => $program->id,
            'equivalency_source_curriculum_id' => $source->id,
            'name' => 'BSCS 2026 Curriculum',
            'effective_school_year' => '2026-2027',
            'status' => CurriculumStatus::Draft,
        ]);
        foreach ([$keptTarget, $removedTarget] as $target) {
            CurriculumSubject::create(['curriculum_id' => $draft->id, 'subject_id' => $target->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true]);
        }
        CurriculumSubjectEquivalency::create([
            'source_curriculum_id' => $source->id,
            'target_curriculum_id' => $draft->id,
            'source_subject_id' => $oldSubject->id,
            'target_subject_id' => $removedTarget->id,
        ]);
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.mapping-sync@grc.test', CollegeCode::Ccs);

        $this->withToken($token)
            ->patchJson("/api/v1/curricula/{$draft->id}", [
                'name' => 'BSCS 2026 Curriculum',
                'subjects' => [
                    ['subject_id' => $keptTarget->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('curriculum_subject_equivalencies', [
            'target_curriculum_id' => $draft->id,
            'target_subject_id' => $removedTarget->id,
        ]);
    }

    public function test_a_non_program_chair_role_cannot_update_a_curriculum(): void
    {
        $program = $this->makeProgram();
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'Existing', 'effective_school_year' => '2026-2027',
            'status' => CurriculumStatus::Draft,
        ]);
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar-head.update@grc.test');

        $response = $this->withToken($token)->patchJson("/api/v1/curricula/{$curriculum->id}", [
            'name' => 'Hijacked', 'effective_school_year' => '2026-2027', 'subjects' => [],
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('curricula', ['name' => 'Existing']);
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_a_program_chair_cannot_update_a_curriculum_from_another_college(): void
    {
        $program = Program::create([
            'code' => 'BSED',
            'name' => 'BS Education',
            'college' => CollegeCode::Coe,
            'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'COE Curriculum',
            'effective_school_year' => '2026-2027',
            'status' => CurriculumStatus::Draft,
        ]);
        $token = $this->tokenFor(UserRole::ProgramChair, 'ccs.chair.patch@grc.test', CollegeCode::Ccs);

        $this->withToken($token)
            ->patchJson("/api/v1/curricula/{$curriculum->id}", [
                'name' => 'Hijacked COE Curriculum',
                'effective_school_year' => '2026-2027',
                'subjects' => [],
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('curricula', ['id' => $curriculum->id, 'name' => 'COE Curriculum']);
    }
}
