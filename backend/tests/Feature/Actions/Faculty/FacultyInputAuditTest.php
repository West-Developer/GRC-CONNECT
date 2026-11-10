<?php

namespace Tests\Feature\Actions\Faculty;

use App\Actions\Faculty\CreateFacultyAvailability;
use App\Actions\Faculty\CreateFacultySubjectPreference;
use App\Actions\Faculty\DeleteFacultyAvailability;
use App\Actions\Faculty\DeleteFacultySubjectPreference;
use App\Actions\Faculty\UpdateFacultyAvailability;
use App\Actions\Faculty\UpdateFacultySubjectPreference;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\FacultyAvailability;
use App\Models\FacultySubjectPreference;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class FacultyInputAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_creating_an_availability_records_the_exact_safe_audit_event(): void
    {
        $term = $this->makeTerm();
        [$professor, $token] = $this->tokenFor(UserRole::Faculty, 'availability.create@grc.test');

        $response = $this->withHeader('X-Request-ID', 'availability-create-request')
            ->withToken($token)
            ->postJson('/api/v1/faculty-availabilities', [
                'academic_term_id' => $term->id,
                'day_of_week' => 2,
                'starts_at_time' => '08:00:00',
                'ends_at_time' => '10:00:00',
            ]);

        $response->assertCreated();
        $availabilityId = (int) $response->json('data.id');

        $this->assertAudit(
            AuditAction::FACULTY_AVAILABILITY_CREATED,
            AuditableType::FACULTY_AVAILABILITY,
            $availabilityId,
            $professor,
            null,
            [
                'professor_id' => $professor->id,
                'day_of_week' => 2,
                'starts_at_time' => '08:00:00',
                'ends_at_time' => '10:00:00',
            ],
            'availability-create-request',
            '127.0.0.1',
        );
    }

    public function test_updating_an_availability_records_exact_before_and_after_snapshots(): void
    {
        $term = $this->makeTerm();
        [$professor, $token] = $this->tokenFor(UserRole::Faculty, 'availability.update@grc.test');
        $availability = $this->makeAvailability($professor, $term, 1, '08:00:00', '09:00:00');

        $this->withHeader('X-Request-ID', 'availability-update-request')
            ->withToken($token)
            ->patchJson("/api/v1/faculty-availabilities/{$availability->id}", [
                'academic_term_id' => $term->id,
                'day_of_week' => 3,
                'starts_at_time' => '10:00:00',
                'ends_at_time' => '11:00:00',
            ])
            ->assertOk();

        $this->assertAudit(
            AuditAction::FACULTY_AVAILABILITY_UPDATED,
            AuditableType::FACULTY_AVAILABILITY,
            $availability->id,
            $professor,
            [
                'professor_id' => $professor->id,
                'day_of_week' => 1,
                'starts_at_time' => '08:00:00',
                'ends_at_time' => '09:00:00',
            ],
            [
                'professor_id' => $professor->id,
                'day_of_week' => 3,
                'starts_at_time' => '10:00:00',
                'ends_at_time' => '11:00:00',
            ],
            'availability-update-request',
            '127.0.0.1',
        );
    }

    public function test_deleting_an_availability_records_its_full_safe_before_snapshot(): void
    {
        $term = $this->makeTerm();
        [$professor, $token] = $this->tokenFor(UserRole::Faculty, 'availability.delete@grc.test');
        $availability = $this->makeAvailability($professor, $term, 4, '13:00:00', '15:00:00');

        $this->withHeader('X-Request-ID', 'availability-delete-request')
            ->withToken($token)
            ->deleteJson("/api/v1/faculty-availabilities/{$availability->id}")
            ->assertNoContent();

        $this->assertAudit(
            AuditAction::FACULTY_AVAILABILITY_DELETED,
            AuditableType::FACULTY_AVAILABILITY,
            $availability->id,
            $professor,
            [
                'professor_id' => $professor->id,
                'day_of_week' => 4,
                'starts_at_time' => '13:00:00',
                'ends_at_time' => '15:00:00',
            ],
            null,
            'availability-delete-request',
            '127.0.0.1',
        );
    }

    public function test_creating_a_subject_preference_records_the_exact_safe_audit_event(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject('AUD101');
        [$professor, $token] = $this->tokenFor(UserRole::Faculty, 'preference.create@grc.test');

        $response = $this->withHeader('X-Request-ID', 'preference-create-request')
            ->withToken($token)
            ->postJson('/api/v1/faculty-subject-preferences', [
                'academic_term_id' => $term->id,
                'subject_id' => $subject->id,
                'rank' => 1,
            ]);

        $response->assertCreated();
        $preferenceId = (int) $response->json('data.id');

        $this->assertAudit(
            AuditAction::FACULTY_SUBJECT_PREFERENCE_CREATED,
            AuditableType::FACULTY_SUBJECT_PREFERENCE,
            $preferenceId,
            $professor,
            null,
            [
                'professor_id' => $professor->id,
                'academic_term_id' => $term->id,
                'subject_id' => $subject->id,
                'rank' => 1,
            ],
            'preference-create-request',
            '127.0.0.1',
        );
    }

    public function test_updating_a_subject_preference_records_exact_before_and_after_snapshots(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject('AUD102');
        [$professor, $token] = $this->tokenFor(UserRole::Faculty, 'preference.update@grc.test');
        $preference = $this->makePreference($professor, $term, $subject, 1);

        $this->withHeader('X-Request-ID', 'preference-update-request')
            ->withToken($token)
            ->patchJson("/api/v1/faculty-subject-preferences/{$preference->id}", [
                'academic_term_id' => $term->id,
                'subject_id' => $subject->id,
                'rank' => 2,
            ])
            ->assertOk();

        $this->assertAudit(
            AuditAction::FACULTY_SUBJECT_PREFERENCE_UPDATED,
            AuditableType::FACULTY_SUBJECT_PREFERENCE,
            $preference->id,
            $professor,
            [
                'professor_id' => $professor->id,
                'academic_term_id' => $term->id,
                'subject_id' => $subject->id,
                'rank' => 1,
            ],
            [
                'professor_id' => $professor->id,
                'academic_term_id' => $term->id,
                'subject_id' => $subject->id,
                'rank' => 2,
            ],
            'preference-update-request',
            '127.0.0.1',
        );
    }

    public function test_deleting_a_subject_preference_records_its_full_safe_before_snapshot(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject('AUD103');
        [$professor, $token] = $this->tokenFor(UserRole::Faculty, 'preference.delete@grc.test');
        $preference = $this->makePreference($professor, $term, $subject, 3);

        $this->withHeader('X-Request-ID', 'preference-delete-request')
            ->withToken($token)
            ->deleteJson("/api/v1/faculty-subject-preferences/{$preference->id}")
            ->assertNoContent();

        $this->assertAudit(
            AuditAction::FACULTY_SUBJECT_PREFERENCE_DELETED,
            AuditableType::FACULTY_SUBJECT_PREFERENCE,
            $preference->id,
            $professor,
            [
                'professor_id' => $professor->id,
                'academic_term_id' => $term->id,
                'subject_id' => $subject->id,
                'rank' => 3,
            ],
            null,
            'preference-delete-request',
            '127.0.0.1',
        );
    }

    public function test_rejected_availability_requests_do_not_create_audit_rows(): void
    {
        $term = $this->makeTerm();
        [, $token] = $this->tokenFor(UserRole::ProgramChair, 'availability.reject@grc.test');

        $this->withToken($token)
            ->postJson('/api/v1/faculty-availabilities', [
                'academic_term_id' => $term->id,
                'day_of_week' => 1,
                'starts_at_time' => '08:00:00',
                'ends_at_time' => '09:00:00',
            ])
            ->assertForbidden();

        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_invalid_subject_preference_requests_do_not_create_audit_rows(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject('AUD104');
        [, $token] = $this->tokenFor(UserRole::Faculty, 'preference.invalid@grc.test');

        $this->withToken($token)
            ->postJson('/api/v1/faculty-subject-preferences', [
                'academic_term_id' => $term->id,
                'subject_id' => $subject->id,
                'rank' => 0,
            ])
            ->assertUnprocessable();

        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_audit_creation_failure_rolls_back_every_availability_mutation(): void
    {
        $term = $this->makeTerm();
        $professor = $this->makeUser(UserRole::Faculty, 'availability.rollback@grc.test');
        $context = new AuditRequestContext('availability-rollback-request', '198.51.100.10');

        $this->assertAuditFailureRollsBack(function () use ($professor, $term, $context): void {
            app(CreateFacultyAvailability::class)->execute($professor, [
                'academic_term_id' => $term->id,
                'day_of_week' => 1,
                'starts_at_time' => '08:00:00',
                'ends_at_time' => '09:00:00',
            ], $context);
        });
        self::assertSame(0, FacultyAvailability::query()->count());

        $availability = $this->makeAvailability($professor, $term, 2, '10:00:00', '11:00:00');
        $this->assertAuditFailureRollsBack(function () use ($professor, $availability, $term, $context): void {
            app(UpdateFacultyAvailability::class)->execute($professor, [
                'academic_term_id' => $term->id,
                'day_of_week' => 3,
                'starts_at_time' => '12:00:00',
                'ends_at_time' => '13:00:00',
            ], $availability, $context);
        });
        $this->assertDatabaseHas('faculty_availabilities', [
            'id' => $availability->id,
            'day_of_week' => 2,
            'starts_at_time' => '10:00:00',
            'ends_at_time' => '11:00:00',
        ]);

        $this->assertAuditFailureRollsBack(function () use ($professor, $availability, $context): void {
            app(DeleteFacultyAvailability::class)->execute($professor, $availability, $context);
        });
        $this->assertDatabaseHas('faculty_availabilities', ['id' => $availability->id]);
    }

    public function test_audit_creation_failure_rolls_back_every_subject_preference_mutation(): void
    {
        $term = $this->makeTerm();
        $subject = $this->makeSubject('AUD105');
        $professor = $this->makeUser(UserRole::Faculty, 'preference.rollback@grc.test');
        $context = new AuditRequestContext('preference-rollback-request', '198.51.100.11');

        $this->assertAuditFailureRollsBack(function () use ($professor, $term, $subject, $context): void {
            app(CreateFacultySubjectPreference::class)->execute($professor, [
                'academic_term_id' => $term->id,
                'subject_id' => $subject->id,
                'rank' => 1,
            ], $context);
        });
        self::assertSame(0, FacultySubjectPreference::query()->count());

        $preference = $this->makePreference($professor, $term, $subject, 2);
        $this->assertAuditFailureRollsBack(function () use ($professor, $preference, $term, $subject, $context): void {
            app(UpdateFacultySubjectPreference::class)->execute($professor, [
                'academic_term_id' => $term->id,
                'subject_id' => $subject->id,
                'rank' => 3,
            ], $preference, $context);
        });
        $this->assertDatabaseHas('faculty_subject_preferences', ['id' => $preference->id, 'rank' => 2]);

        $this->assertAuditFailureRollsBack(function () use ($professor, $preference, $context): void {
            app(DeleteFacultySubjectPreference::class)->execute($professor, $preference, $context);
        });
        $this->assertDatabaseHas('faculty_subject_preferences', ['id' => $preference->id]);
    }

    /**
     * @param  ?array<string, int|string>  $beforeValues
     * @param  ?array<string, int|string>  $afterValues
     */
    private function assertAudit(
        string $action,
        string $auditableType,
        int $auditableId,
        User $actor,
        ?array $beforeValues,
        ?array $afterValues,
        string $requestId,
        string $ipAddress,
    ): void {
        $audit = AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole();

        self::assertSame($action, $audit->action);
        self::assertSame($auditableType, $audit->auditable_type);
        self::assertSame($auditableId, $audit->auditable_id);
        self::assertSame($actor->id, $audit->actor_user_id);
        self::assertSame($beforeValues, $audit->before_values);
        self::assertSame($afterValues, $audit->after_values);
        self::assertSame($requestId, $audit->request_id);
        self::assertSame($ipAddress, $audit->ip_address);
    }

    /**
     * @param  callable(): void  $operation
     */
    private function assertAuditFailureRollsBack(callable $operation): void
    {
        AuditLog::creating(static function (): never {
            throw new RuntimeException('Injected audit write failure.');
        });

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
    private function tokenFor(UserRole $role, string $email): array
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
            'name' => 'Audit '.$role->value,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
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

    private function makeSubject(string $code): Subject
    {
        return Subject::create([
            'code' => $code,
            'title' => 'Audit Subject '.$code,
            'units' => 3,
            'status' => SubjectStatus::Active,
        ]);
    }

    private function makeAvailability(
        User $professor,
        AcademicTerm $term,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
    ): FacultyAvailability {
        return FacultyAvailability::create([
            'professor_id' => $professor->id,
            'academic_term_id' => $term->id,
            'day_of_week' => $dayOfWeek,
            'starts_at_time' => $startsAt,
            'ends_at_time' => $endsAt,
        ]);
    }

    private function makePreference(
        User $professor,
        AcademicTerm $term,
        Subject $subject,
        int $rank,
    ): FacultySubjectPreference {
        return FacultySubjectPreference::create([
            'professor_id' => $professor->id,
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'rank' => $rank,
        ]);
    }
}
