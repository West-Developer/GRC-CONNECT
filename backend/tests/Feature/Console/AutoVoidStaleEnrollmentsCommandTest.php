<?php

namespace Tests\Feature\Console;

use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AutoVoidStaleEnrollmentsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_it_voids_enrollments_pending_payment_beyond_the_stale_window(): void
    {
        $registrar = User::create([
            'name' => 'Registrar Head',
            'email' => 'registrar@grc.test',
            'password' => self::PASSWORD,
            'role' => UserRole::RegistrarHead,
            'status' => UserStatus::Active,
        ]);

        $term = AcademicTerm::create([
            'school_year' => '2026-2027',
            'semester' => '1st',
            'status' => 'semester_ongoing',
        ]);

        $program = Program::create([
            'code' => 'BSCS',
            'name' => 'BS Computer Science',
            'college' => CollegeCode::Ccs,
            'status' => ProgramStatus::Active,
        ]);

        $curriculum = \App\Models\Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);

        $studentUser1 = User::create([
            'name' => 'Stale Student',
            'email' => 'stale@grc.test',
            'password' => self::PASSWORD,
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ]);

        $student1 = StudentProfile::create([
            'user_id' => $studentUser1->id,
            'student_number' => '2026-0001',
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => 'admitted',
            'academic_standing' => 'good',
        ]);

        $studentUser2 = User::create([
            'name' => 'Fresh Student',
            'email' => 'fresh@grc.test',
            'password' => self::PASSWORD,
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ]);

        $student2 = StudentProfile::create([
            'user_id' => $studentUser2->id,
            'student_number' => '2026-0002',
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => 'admitted',
            'academic_standing' => 'good',
        ]);

        // Stale enrollment: approved 5 days ago
        $staleEnrollment = Enrollment::create([
            'student_id' => $student1->id,
            'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::PendingPayment,
            'total_units' => 18,
            'submitted_at' => Carbon::now()->subDays(6),
            'registrar_decided_at' => Carbon::now()->subDays(5),
        ]);

        // Fresh enrollment: approved 2 hours ago
        $freshEnrollment = Enrollment::create([
            'student_id' => $student2->id,
            'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::PendingPayment,
            'total_units' => 18,
            'submitted_at' => Carbon::now()->subHours(3),
            'registrar_decided_at' => Carbon::now()->subHours(2),
        ]);

        // Test dry-run: should not modify
        $this->artisan('enrollments:auto-void', ['--days' => 3, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(EnrollmentStatus::PendingPayment, $staleEnrollment->fresh()->status);

        // Run real command
        $this->artisan('enrollments:auto-void', ['--days' => 3])
            ->assertSuccessful();

        // Stale enrollment should now be cancelled (voided)
        $this->assertSame(EnrollmentStatus::Cancelled, $staleEnrollment->fresh()->status);

        // Fresh enrollment should remain pending payment
        $this->assertSame(EnrollmentStatus::PendingPayment, $freshEnrollment->fresh()->status);
    }
}
