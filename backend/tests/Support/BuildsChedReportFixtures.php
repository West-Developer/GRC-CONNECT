<?php

namespace Tests\Support;

use App\Domain\Academic\GradeStatus;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\Gender;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;

/**
 * Small world builders for the CHED / NSTP report tests: terms, programs, students with the
 * details the forms need, enrollments with subjects, and locked grades.
 */
trait BuildsChedReportFixtures
{
    private int $chedCounter = 0;

    protected function chedTerm(string $schoolYear = '2025-2026', string $semester = '2nd'): AcademicTerm
    {
        return AcademicTerm::query()->firstOrCreate(
            ['school_year' => $schoolYear, 'semester' => $semester],
            ['status' => AcademicTermStatus::SemesterOngoing],
        );
    }

    protected function chedProgram(string $code = 'BSIT', string $name = 'BS Information Technology'): Program
    {
        return Program::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'status' => ProgramStatus::Active],
        );
    }

    protected function chedSubject(string $code, string $title, float $units = 3.0): Subject
    {
        return Subject::query()->firstOrCreate(
            ['code' => $code],
            ['title' => $title, 'units' => $units, 'status' => SubjectStatus::Active],
        );
    }

    protected function chedRegistrarHead(): User
    {
        return User::query()->where('email', 'ched.registrar.head@grc.test')->first() ?? User::create([
            'name' => 'Registrar Head', 'email' => 'ched.registrar.head@grc.test', 'password' => 'password',
            'role' => UserRole::RegistrarHead, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $user  overrides for the user row
     * @param  array<string, mixed>  $profile  overrides for the student profile row
     */
    protected function chedStudent(array $user = [], array $profile = [], ?Program $program = null): StudentProfile
    {
        $this->chedCounter++;
        $n = $this->chedCounter;
        $program ??= $this->chedProgram();
        $curriculum = Curriculum::query()->where('program_id', $program->id)->first() ?? Curriculum::create([
            'program_id' => $program->id, 'name' => $program->code.' Curriculum',
            'effective_school_year' => '2024-2029', 'effective_start_year' => 2024,
            'effective_end_year' => 2029, 'status' => CurriculumStatus::Active,
        ]);

        $account = User::create(array_merge([
            'name' => "Student{$n} Santos",
            'first_name' => "Student{$n}",
            'middle_initial' => 'D',
            'last_name' => 'Santos',
            'email' => "ched.student{$n}@grc.test",
            'password' => 'password',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ], $user));

        return StudentProfile::create(array_merge([
            'user_id' => $account->id,
            'student_number' => sprintf('2025-08-%05d', $n),
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'entry_year' => 2025,
            'year_level' => 1,
            'admission_status' => AdmissionStatus::Enrolled,
            'academic_standing' => AcademicStanding::Good,
            'gender' => Gender::Female,
            'citizenship' => 'Filipino',
        ], $profile));
    }

    /**
     * @param  list<Subject>  $subjects
     */
    protected function chedEnrol(
        StudentProfile $student,
        AcademicTerm $term,
        array $subjects,
        EnrollmentStatus $status = EnrollmentStatus::Enrolled,
        EnrollmentSubjectStatus $subjectStatus = EnrollmentSubjectStatus::Enrolled,
    ): Enrollment {
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => $status,
            'total_units' => array_sum(array_map(static fn (Subject $subject): float => (float) $subject->units, $subjects)),
            'submitted_at' => now(),
        ]);

        foreach ($subjects as $subject) {
            $section = Section::query()->firstOrCreate(
                ['academic_term_id' => $term->id, 'subject_id' => $subject->id, 'section_code' => 'A'],
                ['capacity' => 40, 'status' => SectionStatus::Published],
            );

            EnrollmentSubject::create([
                'enrollment_id' => $enrollment->id,
                'section_id' => $section->id,
                'status' => $subjectStatus,
            ]);
        }

        return $enrollment;
    }

    protected function chedGrade(
        StudentProfile $student,
        Subject $subject,
        AcademicTerm $term,
        string $mark,
        GradeStatus $status = GradeStatus::Locked,
    ): AcademicGrade {
        return AcademicGrade::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'academic_term_id' => $term->id,
            'final_grade' => is_numeric($mark) ? (float) $mark : null,
            'mark' => $mark,
            'status' => $status,
            'encoded_by' => $this->chedRegistrarHead()->id,
        ]);
    }
}
