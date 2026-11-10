<?php

namespace Tests\Feature\Actions\Enrollment;

use App\Actions\Enrollment\ClaimQueueTicket;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * `ClaimQueueTicket::allocate()` self-guards the `pending_payment` status
 * (ADR 0011) even though today's only caller
 * (`QueueTicketController::resolveEnrollment()`) already filters to
 * `pending_payment` before ever calling `execute()` -- so the HTTP-level
 * `ClaimQueueTicketEndpointTest` can never actually reach this guard, only
 * the controller's own pre-filter. This resolves the Action from the
 * container and calls `execute()` directly, past the controller, the same
 * way `Tests\Feature\Actions\Enrollment\SubmitEnrollmentCapacityTest`
 * exercises `SubmitEnrollment`'s in-transaction seat lock past its Form
 * Request's pre-check -- an established pattern in this codebase for
 * proving a write Action's own guard holds regardless of caller.
 */
final class ClaimQueueTicketGuardTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_the_action_rejects_an_enrollment_that_is_not_pending_payment(): void
    {
        $term = AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
        $user = User::create([
            'name' => 'Guard Test Student', 'email' => 'guard.student@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active,
        ]);
        $student = StudentProfile::create([
            'user_id' => $user->id, 'student_number' => '2026-09999',
            'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted, 'academic_standing' => AcademicStanding::Good,
        ]);
        // Already enrolled, not pending_payment -- exactly the state the
        // controller's own resolveEnrollment() filter would already have
        // rejected, so only a direct Action call can reach this guard.
        $enrollment = Enrollment::create([
            'student_id' => $student->id, 'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::Enrolled, 'total_units' => 3,
        ]);
        $actor = User::create([
            'name' => 'Cashier', 'email' => 'guard.cashier@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::AccountingStaff, 'status' => UserStatus::Active,
        ]);

        try {
            app(ClaimQueueTicket::class)->execute($enrollment, $actor, new AuditRequestContext('test-request', null));
            self::fail('Expected a ValidationException because the enrollment is not pending_payment.');
        } catch (ValidationException $exception) {
            self::assertSame(
                "This enrollment is not pending payment; it is currently 'enrolled'.",
                $exception->errors()['enrollment'][0],
            );
        }

        $this->assertDatabaseCount('queue_tickets', 0);
    }
}
