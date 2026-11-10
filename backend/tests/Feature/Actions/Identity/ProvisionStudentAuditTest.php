<?php

namespace Tests\Feature\Actions\Identity;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

final class ProvisionStudentAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_provisioning_records_only_the_exact_safe_student_profile_audit(): void
    {
        $this->travelTo(Carbon::parse('2027-08-15 10:00:00', 'Asia/Manila'));
        [$program, $curriculum] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        [$actor, $token] = $this->tokenFor(
            UserRole::AdmissionStaff,
            'provision.audit.admission@grc.test',
        );
        Mail::fake();

        $response = $this->withHeader('X-Request-ID', 'student-provision-request')
            ->withToken($token)
            ->postJson('/api/v1/student-profiles', [
                'first_name' => 'Sensitive',
                'last_name' => 'Student Name',
                'email' => 'sensitive.student@grc.test',
                'gender' => 'F',
                'birth_date' => '2006-05-04',
                'citizenship' => 'Filipino',
                'contact_number' => '09171234567',
                'street_barangay' => '1 Sensitive Street, Caloocan City',
                'town_city' => 'Caloocan City',
                'province' => 'Metro Manila',
                'program_id' => $program->id,
                'year_level' => 2,
                'requirements_verified' => true,
                'privacy_notice_acknowledged' => true,
                'privacy_notice_version' => config('privacy.notice_version'),
                'student_type' => 'transferee',
            ]);

        $response->assertCreated();
        $profileId = (int) $response->json('data.id');
        $userId = (int) $response->json('data.user_id');
        $audit = AuditLog::query()->where('action', AuditAction::STUDENT_PROFILE_PROVISIONED)->sole();

        self::assertSame(AuditAction::STUDENT_PROFILE_PROVISIONED, $audit->action);
        self::assertSame(AuditableType::STUDENT_PROFILE, $audit->auditable_type);
        self::assertSame($profileId, $audit->auditable_id);
        self::assertSame($actor->id, $audit->actor_user_id);
        self::assertNull($audit->before_values);
        self::assertSame([
            'user_id' => $userId,
            'student_profile_id' => $profileId,
            'role' => 'student',
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'entry_year' => 2027,
            'year_level' => 2,
            // Admission chose the student type; the category is not asked for any more, so every new
            // account starts Regular until the system re-derives it from the student's record.
            'enrollment_category' => 'regular',
            'student_type' => 'transferee',
            'admission_status' => 'admitted',
            'academic_standing' => 'good',
            'financial_status' => null,
            'student_number_source' => 'assigned',
        ], $audit->after_values);
        self::assertNull($audit->reason);
        self::assertSame('student-provision-request', $audit->request_id);
        self::assertSame('127.0.0.1', $audit->ip_address);

        $serializedAudit = json_encode($audit->toArray(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('Sensitive Student Name', $serializedAudit);
        self::assertStringNotContainsString('sensitive.student@grc.test', $serializedAudit);
        self::assertStringNotContainsString('temporary-secret-password', $serializedAudit);
        self::assertSame('2027-08-00001', $response->json('data.student_number'));
        self::assertStringNotContainsString('2027-08-00001', $serializedAudit);
        self::assertStringNotContainsString('"password"', strtolower($serializedAudit));
        self::assertStringNotContainsString('password_confirmation', strtolower($serializedAudit));
    }

    public function test_authorization_rejection_creates_no_student_or_audit(): void
    {
        [$program, $curriculum] = $this->makeProgramAndCurriculum();
        [, $token] = $this->tokenFor(UserRole::RegistrarStaff, 'provision.audit.denied@grc.test');

        $this->withToken($token)
            ->postJson('/api/v1/student-profiles', [
                'name' => 'Denied Student',
                'email' => 'denied.student@grc.test',
                'password' => 'temporary-password',
                'student_number' => 'DENIED-0001',
                'program_id' => $program->id,
                'curriculum_id' => $curriculum->id,
                'year_level' => 1,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'denied.student@grc.test']);
        $this->assertDatabaseCount('student_profiles', 0);
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_validation_rejection_creates_no_student_or_audit(): void
    {
        [$program, $curriculum] = $this->makeProgramAndCurriculum();
        [, $token] = $this->tokenFor(UserRole::AdmissionStaff, 'provision.audit.invalid@grc.test');

        $this->withToken($token)
            ->postJson('/api/v1/student-profiles', [
                'name' => 'Invalid Student',
                'email' => 'invalid.student@grc.test',
                'password' => 'short',
                'student_number' => 'INVALID-0001',
                'program_id' => $program->id,
                'curriculum_id' => $curriculum->id,
                'year_level' => 1,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('users', ['email' => 'invalid.student@grc.test']);
        $this->assertDatabaseCount('student_profiles', 0);
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_audit_failure_rolls_back_both_user_and_student_profile(): void
    {
        [$program] = $this->makeProgramAndCurriculum();
        $this->setCurrentTerm('2027-2028');
        [, $token] = $this->tokenFor(UserRole::AdmissionStaff, 'provision.audit.rollback@grc.test');

        AuditLog::creating(static function (): never {
            throw new RuntimeException('Injected student audit write failure.');
        });
        $this->withoutExceptionHandling();
        $caughtException = null;

        try {
            $this->withToken($token)
                ->postJson('/api/v1/student-profiles', [
                    'first_name' => 'Rollback',
                    'last_name' => 'Student',
                    'email' => 'rollback.student@grc.test',
                    'gender' => 'F',
                    'birth_date' => '2006-05-04',
                    'citizenship' => 'Filipino',
                    'contact_number' => '09171234567',
                    'street_barangay' => '2 Rollback Street, Caloocan City',
                    'town_city' => 'Caloocan City',
                    'province' => 'Metro Manila',
                    'program_id' => $program->id,
                    'year_level' => 3,
                    'requirements_verified' => true,
                    'privacy_notice_acknowledged' => true,
                    'privacy_notice_version' => config('privacy.notice_version'),
                    'student_type' => 'transferee',
                ]);
        } catch (RuntimeException $exception) {
            $caughtException = $exception;
        } finally {
            AuditLog::flushEventListeners();
            AuditLog::clearBootedModels();
        }

        self::assertNotNull($caughtException, 'The injected audit failure must escape the transaction.');
        self::assertSame('Injected student audit write failure.', $caughtException->getMessage());
        $this->assertDatabaseMissing('users', ['email' => 'rollback.student@grc.test']);
        $this->assertDatabaseCount('student_profiles', 0);
        // The number taken inside the failed transaction is given back.
        $this->assertDatabaseCount('student_number_sequences', 0);
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    private function setCurrentTerm(string $schoolYear): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => $schoolYear,
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
    }

    /** @return array{User, string} */
    private function tokenFor(UserRole $role, string $email): array
    {
        $user = User::create([
            'name' => 'Provision '.$role->value,
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

    /** @return array{Program, Curriculum} */
    private function makeProgramAndCurriculum(): array
    {
        $program = Program::create([
            'code' => 'BSCS',
            'name' => 'BS Computer Science',
            'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027',
            'effective_start_year' => 2026,
            'effective_end_year' => 2030,
            'status' => CurriculumStatus::Active,
        ]);

        return [$program, $curriculum];
    }
}
