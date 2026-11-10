<?php

namespace Tests\Feature\Actions\Scheduling;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class SectionAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_creating_a_section_records_the_exact_fourteen_field_snapshot_with_database_defaults(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject();
        $professor = $this->makeUser(UserRole::Faculty, 'section.professor.create@grc.test');
        [$actor, $token] = $this->tokenFor('section.audit.create@grc.test');

        $response = $this->withHeader('X-Request-ID', 'section-create-request')
            ->withToken($token)
            ->postJson('/api/v1/sections', [
                'academic_term_id' => $term->id,
                'subject_id' => $subject->id,
                'section_code' => 'A',
                'professor_id' => $professor->id,
                'schedule_days' => 'MWF',
                'starts_at_time' => '08:00:00',
                'ends_at_time' => '09:00:00',
                'room' => 'Lab 1',
                'capacity' => 40,
                'viability_threshold' => 25,
                'status' => 'planned',
            ]);

        $response->assertCreated();
        $sectionId = (int) $response->json('data.id');

        $this->assertAudit(
            AuditAction::SECTION_CREATED,
            $sectionId,
            $actor,
            null,
            [
                'academic_term_id' => $term->id,
                'subject_id' => $subject->id,
                'section_code' => 'A',
                'professor_id' => $professor->id,
                'schedule_days' => 'MON/WED/FRI',
                'starts_at_time' => '08:00:00',
                'ends_at_time' => '09:00:00',
                'room' => 'Lab 1',
                'modality' => null,
                'capacity' => 40,
                // Hand-created sections are not plan-generated, so their
                // capacity belongs to the author who typed it.
                'capacity_source' => 'manual',
                'viability_threshold' => 25,
                'enrolled_count' => 0,
                'status' => 'planned',
            ],
            'section-create-request',
        );
    }

    public function test_updating_a_section_records_exact_before_and_after_snapshots_with_enum_strings(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject();
        $section = Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'section_code' => 'A',
            'schedule_days' => 'TTH',
            'starts_at_time' => '10:00:00',
            'ends_at_time' => '11:30:00',
            'room' => 'Room 2',
            'capacity' => 35,
            'viability_threshold' => 20,
            'enrolled_count' => 7,
            'status' => SectionStatus::Planned,
        ]);
        [$actor, $token] = $this->tokenFor('section.audit.update@grc.test');

        $this->withHeader('X-Request-ID', 'section-update-request')
            ->withToken($token)
            ->patchJson("/api/v1/sections/{$section->id}", [
                'academic_term_id' => $term->id,
                'subject_id' => $subject->id,
                'section_code' => 'B',
                'schedule_days' => null,
                'starts_at_time' => null,
                'ends_at_time' => null,
                'room' => null,
                'capacity' => 45,
                'viability_threshold' => null,
                'status' => 'published',
            ])
            ->assertOk();

        $this->assertAudit(
            AuditAction::SECTION_UPDATED,
            $section->id,
            $actor,
            [
                'academic_term_id' => $term->id,
                'subject_id' => $subject->id,
                'section_code' => 'A',
                'professor_id' => null,
                'schedule_days' => 'TTH',
                'starts_at_time' => '10:00:00',
                'ends_at_time' => '11:30:00',
                'room' => 'Room 2',
                'modality' => null,
                'capacity' => 35,
                // Seeded straight through the model, so it still carries
                // the column default before the PATCH claims it.
                'capacity_source' => 'plan',
                'viability_threshold' => 20,
                'enrolled_count' => 7,
                'status' => 'planned',
            ],
            [
                'academic_term_id' => $term->id,
                'subject_id' => $subject->id,
                'section_code' => 'B',
                'professor_id' => null,
                'schedule_days' => null,
                'starts_at_time' => null,
                'ends_at_time' => null,
                'room' => null,
                'modality' => null,
                'capacity' => 45,
                // Changing capacity from 35 claims the section from its
                // year-level plan so a later release cannot overwrite it.
                'capacity_source' => 'manual',
                'viability_threshold' => null,
                'enrolled_count' => 7,
                'status' => 'published',
            ],
            'section-update-request',
        );
    }

    public function test_rejected_section_requests_create_no_audit_row(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject();
        [, $token] = $this->tokenFor('section.audit.reject@grc.test', UserRole::Dean);

        $this->withToken($token)
            ->postJson('/api/v1/sections', [
                'academic_term_id' => $term->id,
                'subject_id' => $subject->id,
                'section_code' => 'A',
                'capacity' => 40,
                'status' => 'planned',
            ])
            ->assertForbidden();

        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_audit_failure_rolls_back_section_creation(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject();
        [, $token] = $this->tokenFor('section.audit.create-rollback@grc.test');

        $this->assertAuditFailure(function () use ($token, $term, $subject): void {
            $this->withToken($token)
                ->postJson('/api/v1/sections', [
                    'academic_term_id' => $term->id,
                    'subject_id' => $subject->id,
                    'section_code' => 'ROLLBACK',
                    'capacity' => 40,
                    'status' => 'planned',
                ]);
        });

        $this->assertDatabaseMissing('sections', ['section_code' => 'ROLLBACK']);
    }

    public function test_audit_failure_rolls_back_the_complete_section_update(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject();
        $professor = $this->makeUser(UserRole::Faculty, 'section.professor.rollback@grc.test');
        $section = Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'section_code' => 'STABLE',
            'professor_id' => $professor->id,
            'schedule_days' => 'MWF',
            'starts_at_time' => '13:00:00',
            'ends_at_time' => '14:00:00',
            'room' => 'Room 3',
            'capacity' => 30,
            'viability_threshold' => 25,
            'enrolled_count' => 11,
            'status' => SectionStatus::Planned,
        ]);
        [, $token] = $this->tokenFor('section.audit.update-rollback@grc.test');

        $this->assertAuditFailure(function () use ($token, $section, $term, $subject): void {
            $this->withToken($token)
                ->patchJson("/api/v1/sections/{$section->id}", [
                    'academic_term_id' => $term->id,
                    'subject_id' => $subject->id,
                    'section_code' => 'CHANGED',
                    'capacity' => 99,
                    'status' => 'published',
                ]);
        });

        $this->assertDatabaseHas('sections', [
            'id' => $section->id,
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'section_code' => 'STABLE',
            'professor_id' => $professor->id,
            'schedule_days' => 'MWF',
            'starts_at_time' => '13:00:00',
            'ends_at_time' => '14:00:00',
            'room' => 'Room 3',
            'capacity' => 30,
            'viability_threshold' => 25,
            'enrolled_count' => 11,
            'status' => 'planned',
        ]);
    }

    /**
     * @param  ?array<string, mixed>  $beforeValues
     * @param  array<string, mixed>  $afterValues
     */
    private function assertAudit(
        string $action,
        int $sectionId,
        User $actor,
        ?array $beforeValues,
        array $afterValues,
        string $requestId,
    ): void {
        $audit = AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole();

        self::assertSame($action, $audit->action);
        self::assertSame(AuditableType::SECTION, $audit->auditable_type);
        self::assertSame($sectionId, $audit->auditable_id);
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
        $user = $this->makeUser($role, $email);
        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->json('data.token');

        return [$user, $token];
    }

    private function makeUser(UserRole $role, string $email): User
    {
        return User::create([
            'name' => 'Section audit '.$role->value,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            // Program Chairs are college-scoped (ADR 0018); SectionPolicy
            // denies section writes to a chair with no college.
            'college' => $role === UserRole::ProgramChair ? CollegeCode::Ccs : null,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);
    }

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2026-2027',
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
    }

    private function makeSubject(): Subject
    {
        return Subject::create([
            'code' => 'SECAUD',
            'title' => 'Section Audit Subject',
            'units' => 3,
            'status' => SubjectStatus::Active,
        ]);
    }
}
