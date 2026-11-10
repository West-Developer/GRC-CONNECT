<?php

namespace Tests\Feature\Actions\Curriculum;

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
use App\Models\Program;
use App\Models\Subject;
use App\Models\SubjectPrerequisite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class CurriculumAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_creating_a_curriculum_records_a_sorted_complete_graph_snapshot(): void
    {
        $program = $this->makeProgram();
        [$first, $second, $third] = $this->makeSubjects();
        [$actor, $token] = $this->tokenFor('curriculum.audit.create@grc.test');

        $response = $this->withHeader('X-Request-ID', 'curriculum-create-request')
            ->withToken($token)
            ->postJson('/api/v1/curricula', [
                'program_id' => $program->id,
                'name' => 'BSCS 2026',
                'effective_school_year' => '2026-2027',
                'subjects' => [
                    [
                        'subject_id' => $third->id,
                        'year_level' => 2,
                        'semester' => '1st',
                        'is_required' => false,
                        'prerequisites' => [
                            ['prerequisite_subject_id' => $second->id, 'minimum_grade' => '2.25'],
                            ['prerequisite_subject_id' => $first->id, 'minimum_grade' => '2.50'],
                        ],
                    ],
                    [
                        'subject_id' => $first->id,
                        'year_level' => 1,
                        'semester' => '1st',
                        'is_required' => true,
                    ],
                ],
            ]);

        $response->assertCreated();
        $curriculumId = (int) $response->json('data.id');

        $this->assertAudit(
            AuditAction::CURRICULUM_CREATED,
            $curriculumId,
            $actor,
            null,
            [
                'program_id' => $program->id,
                'equivalency_source_curriculum_id' => null,
                'name' => 'BSCS 2026',
                'effective_school_year' => '2026-2027',
                'status' => 'draft',
                'subjects' => [
                    [
                        'subject_id' => $first->id,
                        'year_level' => 1,
                        'semester' => '1st',
                        'is_required' => true,
                        'equivalent_source_subject_id' => null,
                        'prerequisites' => [],
                    ],
                    [
                        'subject_id' => $third->id,
                        'year_level' => 2,
                        'semester' => '1st',
                        'is_required' => false,
                        'equivalent_source_subject_id' => null,
                        'prerequisites' => [
                            ['prerequisite_subject_id' => $first->id, 'minimum_grade' => '2.50'],
                            ['prerequisite_subject_id' => $second->id, 'minimum_grade' => '2.25'],
                        ],
                    ],
                ],
            ],
            'curriculum-create-request',
        );
    }

    public function test_updating_a_curriculum_audits_the_old_and_replacement_graphs(): void
    {
        $program = $this->makeProgram();
        [$first, $second, $third] = $this->makeSubjects();
        $curriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'Original curriculum',
            'effective_school_year' => '2025-2026',
            'status' => CurriculumStatus::Draft,
        ]);
        $oldThird = $curriculum->subjectPlacements()->create([
            'subject_id' => $third->id,
            'year_level' => 2,
            'semester' => '2nd',
            'is_required' => false,
        ]);
        SubjectPrerequisite::create([
            'curriculum_subject_id' => $oldThird->id,
            'prerequisite_subject_id' => $first->id,
            'minimum_grade' => '2.75',
        ]);
        $curriculum->subjectPlacements()->create([
            'subject_id' => $first->id,
            'year_level' => 1,
            'semester' => '1st',
            'is_required' => true,
        ]);
        [$actor, $token] = $this->tokenFor('curriculum.audit.update@grc.test');

        $this->withHeader('X-Request-ID', 'curriculum-update-request')
            ->withToken($token)
            ->patchJson("/api/v1/curricula/{$curriculum->id}", [
                'name' => 'Replacement curriculum',
                'effective_school_year' => '2026-2027',
                'subjects' => [
                    [
                        'subject_id' => $second->id,
                        'year_level' => 1,
                        'semester' => '2nd',
                        'is_required' => true,
                    ],
                ],
            ])
            ->assertOk();

        $this->assertAudit(
            AuditAction::CURRICULUM_UPDATED,
            $curriculum->id,
            $actor,
            [
                'program_id' => $program->id,
                'equivalency_source_curriculum_id' => null,
                'name' => 'Original curriculum',
                'effective_school_year' => '2025-2026',
                'status' => 'draft',
                'subjects' => [
                    [
                        'subject_id' => $first->id,
                        'year_level' => 1,
                        'semester' => '1st',
                        'is_required' => true,
                        'equivalent_source_subject_id' => null,
                        'prerequisites' => [],
                    ],
                    [
                        'subject_id' => $third->id,
                        'year_level' => 2,
                        'semester' => '2nd',
                        'is_required' => false,
                        'equivalent_source_subject_id' => null,
                        'prerequisites' => [
                            ['prerequisite_subject_id' => $first->id, 'minimum_grade' => '2.75'],
                        ],
                    ],
                ],
            ],
            [
                'program_id' => $program->id,
                'equivalency_source_curriculum_id' => null,
                'name' => 'Replacement curriculum',
                'effective_school_year' => '2025-2026',
                'status' => 'draft',
                'subjects' => [
                    [
                        'subject_id' => $second->id,
                        'year_level' => 1,
                        'semester' => '2nd',
                        'is_required' => true,
                        'equivalent_source_subject_id' => null,
                        'prerequisites' => [],
                    ],
                ],
            ],
            'curriculum-update-request',
        );
        $this->assertDatabaseCount('curriculum_subjects', 1);
        $this->assertDatabaseHas('curriculum_subjects', [
            'curriculum_id' => $curriculum->id,
            'subject_id' => $second->id,
        ]);
        $this->assertDatabaseCount('subject_prerequisites', 0);
    }

    public function test_rejected_curriculum_requests_create_no_audit_row(): void
    {
        $program = $this->makeProgram();
        [, $token] = $this->tokenFor('curriculum.audit.reject@grc.test', UserRole::Dean);

        $this->withToken($token)
            ->postJson('/api/v1/curricula', [
                'program_id' => $program->id,
                'name' => 'Rejected curriculum',
                'effective_school_year' => '2026-2027',
                'subjects' => [],
            ])
            ->assertForbidden();

        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_audit_failure_rolls_back_curriculum_creation_and_its_entire_graph(): void
    {
        $program = $this->makeProgram();
        [$first, $second] = $this->makeSubjects();
        [, $token] = $this->tokenFor('curriculum.audit.create-rollback@grc.test');

        $this->assertAuditFailure(function () use ($token, $program, $first, $second): void {
            $this->withToken($token)
                ->postJson('/api/v1/curricula', [
                    'program_id' => $program->id,
                    'name' => 'Must roll back',
                    'effective_school_year' => '2026-2027',
                    'subjects' => [
                        [
                            'subject_id' => $second->id,
                            'year_level' => 1,
                            'semester' => '2nd',
                            'is_required' => true,
                            'prerequisites' => [
                                ['prerequisite_subject_id' => $first->id, 'minimum_grade' => '2.50'],
                            ],
                        ],
                    ],
                ]);
        });

        $this->assertDatabaseMissing('curricula', ['name' => 'Must roll back']);
        $this->assertDatabaseCount('curriculum_subjects', 0);
        $this->assertDatabaseCount('subject_prerequisites', 0);
    }

    public function test_audit_failure_rolls_back_curriculum_update_and_restores_the_entire_old_graph(): void
    {
        $program = $this->makeProgram();
        [$first, $second, $third] = $this->makeSubjects();
        $curriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'Stable curriculum',
            'effective_school_year' => '2025-2026',
            'status' => CurriculumStatus::Draft,
        ]);
        $firstPlacement = $curriculum->subjectPlacements()->create([
            'subject_id' => $first->id,
            'year_level' => 1,
            'semester' => '1st',
            'is_required' => true,
        ]);
        $thirdPlacement = $curriculum->subjectPlacements()->create([
            'subject_id' => $third->id,
            'year_level' => 2,
            'semester' => '1st',
            'is_required' => false,
        ]);
        $oldPrerequisite = SubjectPrerequisite::create([
            'curriculum_subject_id' => $thirdPlacement->id,
            'prerequisite_subject_id' => $first->id,
            'minimum_grade' => '2.25',
        ]);
        [, $token] = $this->tokenFor('curriculum.audit.update-rollback@grc.test');

        $this->assertAuditFailure(function () use ($token, $curriculum, $second): void {
            $this->withToken($token)
                ->patchJson("/api/v1/curricula/{$curriculum->id}", [
                    'name' => 'Must not persist',
                    'effective_school_year' => '2026-2027',
                    'subjects' => [
                        [
                            'subject_id' => $second->id,
                            'year_level' => 3,
                            'semester' => '2nd',
                            'is_required' => true,
                        ],
                    ],
                ]);
        });

        $this->assertDatabaseHas('curricula', [
            'id' => $curriculum->id,
            'program_id' => $program->id,
            'name' => 'Stable curriculum',
            'effective_school_year' => '2025-2026',
            'status' => 'draft',
        ]);
        $this->assertDatabaseCount('curriculum_subjects', 2);
        $this->assertDatabaseHas('curriculum_subjects', [
            'id' => $firstPlacement->id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => $first->id,
            'year_level' => 1,
            'semester' => '1st',
            'is_required' => true,
        ]);
        $this->assertDatabaseHas('curriculum_subjects', [
            'id' => $thirdPlacement->id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => $third->id,
            'year_level' => 2,
            'semester' => '1st',
            'is_required' => false,
        ]);
        $this->assertDatabaseCount('subject_prerequisites', 1);
        $this->assertDatabaseHas('subject_prerequisites', [
            'id' => $oldPrerequisite->id,
            'curriculum_subject_id' => $thirdPlacement->id,
            'prerequisite_subject_id' => $first->id,
            'minimum_grade' => '2.25',
        ]);
    }

    /**
     * @param  ?array<string, mixed>  $beforeValues
     * @param  array<string, mixed>  $afterValues
     */
    private function assertAudit(
        string $action,
        int $curriculumId,
        User $actor,
        ?array $beforeValues,
        array $afterValues,
        string $requestId,
    ): void {
        $audit = AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole();

        self::assertSame($action, $audit->action);
        self::assertSame(AuditableType::CURRICULUM, $audit->auditable_type);
        self::assertSame($curriculumId, $audit->auditable_id);
        self::assertSame($actor->id, $audit->actor_user_id);
        self::assertSame($beforeValues, $audit->before_values);
        self::assertSame($afterValues, $audit->after_values);
        self::assertNull($audit->reason);
        self::assertSame($requestId, $audit->request_id);
        self::assertSame('127.0.0.1', $audit->ip_address);
    }

    /**
     * @param  callable(): void  $operation
     */
    private function assertAuditFailure(callable $operation): void
    {
        AuditLog::creating(static function (): never {
            throw new RuntimeException('Injected audit write failure.');
        });

        $this->withoutExceptionHandling();

        try {
            $operation();
            self::fail('The injected audit write failure must escape the action transaction.');
        } catch (RuntimeException $exception) {
            self::assertSame('Injected audit write failure.', $exception->getMessage());
            self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
        } finally {
            AuditLog::flushEventListeners();
            AuditLog::clearBootedModels();
        }
    }

    /** @return array{User, string} */
    private function tokenFor(string $email, UserRole $role = UserRole::ProgramChair): array
    {
        $user = User::create([
            'name' => 'Curriculum audit actor',
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'college' => CollegeCode::Ccs,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);
        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->json('data.token');

        return [$user, $token];
    }

    private function makeProgram(): Program
    {
        // A curriculum is created against the current or latest academic term.
        AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing, 'starts_at' => '2026-08-01']);

        return Program::create([
            'code' => 'BSCS',
            'name' => 'BS Computer Science',
            'college' => CollegeCode::Ccs,
            'status' => ProgramStatus::Active,
        ]);
    }

    /** @return list<Subject> */
    private function makeSubjects(): array
    {
        return [
            Subject::create(['code' => 'AUD101', 'title' => 'First', 'units' => 3, 'status' => SubjectStatus::Active]),
            Subject::create(['code' => 'AUD102', 'title' => 'Second', 'units' => 3, 'status' => SubjectStatus::Active]),
            Subject::create(['code' => 'AUD103', 'title' => 'Third', 'units' => 3, 'status' => SubjectStatus::Active]),
        ];
    }
}
