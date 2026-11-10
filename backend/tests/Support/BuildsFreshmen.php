<?php

namespace Tests\Support;

use App\Actions\Enrollment\IssueFreshmanQueueTicket;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\IssuedFreshmanQueueTicket;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;

/**
 * Test helpers for the freshman pay-first flow: a freshman is a disabled student
 * with a student profile and no enrollment, who holds a queue ticket issued by
 * Admission.
 */
trait BuildsFreshmen
{
    private const FRESHMAN_TEST_PASSWORD = 'correct-horse-battery-staple';

    protected function freshmanProfile(
        string $studentNumber = '2026-10-00001',
        StudentType $type = StudentType::Freshman,
        string $category = 'regular',
    ): StudentProfile {
        $program = Program::query()->first() ?? Program::create(['code' => 'BSIT', 'name' => 'BS Information Technology', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::query()->first() ?? Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSIT Curriculum',
            'effective_school_year' => '2026-2027', 'effective_start_year' => 2026,
            'effective_end_year' => 2030, 'status' => CurriculumStatus::Active,
        ]);
        $user = User::create([
            'name' => 'Fresh Student '.$studentNumber, 'email' => 'fresh.'.$studentNumber.'@grc.test',
            'password' => self::FRESHMAN_TEST_PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Disabled,
        ]);

        return StudentProfile::create([
            'user_id' => $user->id, 'student_number' => $studentNumber,
            'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'year_level' => 1,
            'enrollment_category' => $category, 'student_type' => $type,
            'admission_status' => AdmissionStatus::Admitted, 'academic_standing' => AcademicStanding::Good,
        ]);
    }

    protected function staffUser(UserRole $role, string $email): User
    {
        return User::query()->where('email', $email)->first() ?? User::create([
            'name' => 'Test '.$role->value, 'email' => $email, 'password' => self::FRESHMAN_TEST_PASSWORD,
            'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
    }

    protected function issueFreshmanTicket(StudentProfile $profile): IssuedFreshmanQueueTicket
    {
        return $this->app->make(IssueFreshmanQueueTicket::class)->execute(
            $profile,
            $this->staffUser(UserRole::AdmissionStaff, 'admission@grc.test'),
            new AuditRequestContext('test-issue', null),
        );
    }
}
