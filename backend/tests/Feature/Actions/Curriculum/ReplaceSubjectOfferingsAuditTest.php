<?php

namespace Tests\Feature\Actions\Curriculum;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class ReplaceSubjectOfferingsAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_replacing_offerings_records_the_before_and_after_snapshot(): void
    {
        $term = $this->makeTerm();
        $program = $this->makeProgram();
        $curriculum = $this->makeCurriculum($program);
        $subject = $this->makeSubject('AOF101');
        $curriculum->subjectPlacements()->create(['subject_id' => $subject->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true]);
        $existing = SubjectOffering::create([
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => 1,
            'semester' => '1st',
            'min_section_capacity' => 10,
            'max_section_capacity' => 20,
            'recommended_sections' => 1,
        ]);
        [$actor, $token] = $this->tokenFor('offering.audit.replace@grc.test');

        $this->withHeader('X-Request-ID', 'offering-replace-request')
            ->withToken($token)
            ->postJson('/api/v1/subject-offerings', [
                'academic_term_id' => $term->id,
                'curriculum_id' => $curriculum->id,
                'offerings' => [
                    [
                        'subject_id' => $subject->id, 'year_level' => 1, 'semester' => '1st',
                        'min_section_capacity' => 30, 'max_section_capacity' => 50, 'recommended_sections' => 3,
                    ],
                ],
            ])
            ->assertOk();

        $audit = AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole();

        self::assertSame(AuditAction::SUBJECT_OFFERINGS_REPLACED, $audit->action);
        self::assertSame(AuditableType::SUBJECT_OFFERING, $audit->auditable_type);
        self::assertNull($audit->auditable_id);
        self::assertSame($actor->id, $audit->actor_user_id);
        self::assertSame([
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'offerings' => [
                [
                    'subject_id' => $subject->id, 'year_level' => 1, 'semester' => '1st',
                    'min_section_capacity' => 10, 'max_section_capacity' => 20, 'recommended_sections' => 1,
                ],
            ],
        ], $audit->before_values);
        self::assertSame([
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'offerings' => [
                [
                    'subject_id' => $subject->id, 'year_level' => 1, 'semester' => '1st',
                    'min_section_capacity' => 30, 'max_section_capacity' => 50, 'recommended_sections' => 3,
                ],
            ],
        ], $audit->after_values);
        self::assertSame('offering-replace-request', $audit->request_id);
        self::assertDatabaseMissing('subject_offerings', ['id' => $existing->id]);
    }

    public function test_rejected_requests_create_no_audit_row(): void
    {
        $term = $this->makeTerm();
        $program = $this->makeProgram();
        $curriculum = $this->makeCurriculum($program);
        [, $token] = $this->tokenFor('offering.audit.reject@grc.test', UserRole::Dean);

        $this->withToken($token)->postJson('/api/v1/subject-offerings', [
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'offerings' => [],
        ])->assertForbidden();

        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_audit_failure_rolls_back_the_entire_replacement(): void
    {
        $term = $this->makeTerm();
        $program = $this->makeProgram();
        $curriculum = $this->makeCurriculum($program);
        $subject = $this->makeSubject('AOF102');
        $curriculum->subjectPlacements()->create(['subject_id' => $subject->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true]);
        $existing = SubjectOffering::create([
            'academic_term_id' => $term->id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => 1,
            'semester' => '1st',
            'min_section_capacity' => 10,
            'max_section_capacity' => 20,
            'recommended_sections' => 1,
        ]);
        [, $token] = $this->tokenFor('offering.audit.rollback@grc.test');

        AuditLog::creating(static function (): never {
            throw new RuntimeException('Injected audit write failure.');
        });

        $this->withoutExceptionHandling();

        try {
            $this->withToken($token)->postJson('/api/v1/subject-offerings', [
                'academic_term_id' => $term->id,
                'curriculum_id' => $curriculum->id,
                'offerings' => [
                    [
                        'subject_id' => $subject->id, 'year_level' => 1, 'semester' => '1st',
                        'min_section_capacity' => 99, 'max_section_capacity' => 99, 'recommended_sections' => 9,
                    ],
                ],
            ]);
            self::fail('The injected audit write failure must escape the action transaction.');
        } catch (RuntimeException $exception) {
            self::assertSame('Injected audit write failure.', $exception->getMessage());
        } finally {
            AuditLog::flushEventListeners();
            AuditLog::clearBootedModels();
        }

        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
        $this->assertDatabaseHas('subject_offerings', ['id' => $existing->id, 'min_section_capacity' => 10]);
        $this->assertDatabaseCount('subject_offerings', 1);
    }

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::Draft,
        ]);
    }

    private function makeProgram(): Program
    {
        return Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
    }

    private function makeCurriculum(Program $program): Curriculum
    {
        return Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS 2026', 'effective_school_year' => '2026-2027',
            'status' => CurriculumStatus::Active,
        ]);
    }

    private function makeSubject(string $code): Subject
    {
        return Subject::create(['code' => $code, 'title' => 'Audit Subject '.$code, 'units' => 3, 'status' => SubjectStatus::Active]);
    }

    /** @return array{User, string} */
    private function tokenFor(string $email, UserRole $role = UserRole::ProgramChair): array
    {
        $user = User::create([
            'name' => 'Offering audit actor',
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
}
