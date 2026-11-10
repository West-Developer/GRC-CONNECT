<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Academic\GradeStatus;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\CurriculumSubjectEquivalency;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CurriculumMigrationEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_a_program_chair_can_preview_a_passing_old_subject_as_a_selectable_credit_for_the_configured_target_curriculum(): void
    {
        [$chair, $source, $target, $student, $equivalency] = $this->migrationScenario();
        $token = $this->tokenFor($chair);

        $this->withToken($token)
            ->getJson("/api/v1/curricula/{$target->id}/migration-preview?student_number={$student->student_number}")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.student.id', $student->id)
            ->assertJsonPath('data.source_curriculum_id', $source->id)
            ->assertJsonPath('data.credit_candidates.0.equivalency_id', $equivalency->id)
            ->assertJsonPath('data.credit_candidates.0.source_subject.code', 'CS-OLD')
            ->assertJsonPath('data.credit_candidates.0.target_subject.code', 'CS-NEW')
            ->assertJsonPath('data.credit_candidates.0.source_completion.final_grade', '1.75');
    }

    public function test_a_program_chair_can_migrate_the_student_and_apply_only_the_selected_passing_equivalencies(): void
    {
        [$chair, $source, $target, $student, $equivalency] = $this->migrationScenario();
        $token = $this->tokenFor($chair);
        $originalGradeId = AcademicGrade::query()->where('student_id', $student->id)->sole()->id;

        $this->withToken($token)
            ->postJson("/api/v1/curricula/{$target->id}/migrations", [
                'student_id' => $student->id,
                'equivalency_ids' => [$equivalency->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.student_id', $student->id)
            ->assertJsonPath('data.source_curriculum_id', $source->id)
            ->assertJsonPath('data.target_curriculum_id', $target->id)
            ->assertJsonPath('data.credited_subject_ids.0', $equivalency->target_subject_id);

        $this->assertDatabaseHas('student_profiles', ['id' => $student->id, 'curriculum_id' => $target->id]);
        $this->assertDatabaseHas('curriculum_migration_credits', [
            'curriculum_subject_equivalency_id' => $equivalency->id,
            'source_academic_grade_id' => $originalGradeId,
            'target_subject_id' => $equivalency->target_subject_id,
        ]);
        self::assertSame(1, AcademicGrade::query()->where('student_id', $student->id)->count());
    }

    /** @return array{0: User, 1: Curriculum, 2: Curriculum, 3: StudentProfile, 4: CurriculumSubjectEquivalency} */
    private function migrationScenario(): array
    {
        $program = Program::create([
            'code' => 'BSCS', 'name' => 'BS Computer Science',
            'college' => CollegeCode::Ccs, 'status' => ProgramStatus::Active,
        ]);
        $chair = User::create([
            'name' => 'CCS Program Chair', 'email' => 'chair.migration@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::ProgramChair,
            'college' => CollegeCode::Ccs, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $source = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS 2021 Curriculum',
            'effective_school_year' => '2021-2022', 'status' => CurriculumStatus::Archived,
        ]);
        $target = Curriculum::create([
            'program_id' => $program->id, 'equivalency_source_curriculum_id' => $source->id,
            'name' => 'BSCS 2026 Curriculum', 'effective_school_year' => '2026-2027',
            'status' => CurriculumStatus::Active,
        ]);
        $oldSubject = Subject::create(['code' => 'CS-OLD', 'title' => 'Old Programming', 'units' => 3, 'status' => SubjectStatus::Active]);
        $newSubject = Subject::create(['code' => 'CS-NEW', 'title' => 'Foundations of Programming', 'units' => 3, 'status' => SubjectStatus::Active]);
        CurriculumSubject::create(['curriculum_id' => $source->id, 'subject_id' => $oldSubject->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true]);
        CurriculumSubject::create(['curriculum_id' => $target->id, 'subject_id' => $newSubject->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true]);
        $equivalency = CurriculumSubjectEquivalency::create([
            'source_curriculum_id' => $source->id, 'target_curriculum_id' => $target->id,
            'source_subject_id' => $oldSubject->id, 'target_subject_id' => $newSubject->id,
        ]);
        $studentUser = User::create([
            'name' => 'Migration Student', 'email' => 'student.migration@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $student = StudentProfile::create([
            'user_id' => $studentUser->id, 'student_number' => '2021-0001',
            'program_id' => $program->id, 'curriculum_id' => $source->id, 'year_level' => 2,
            'admission_status' => AdmissionStatus::Enrolled, 'academic_standing' => AcademicStanding::Good,
        ]);
        $term = AcademicTerm::create([
            'school_year' => '2025-2026', 'semester' => '2nd', 'status' => AcademicTermStatus::Archived,
        ]);
        AcademicGrade::create([
            'student_id' => $student->id, 'subject_id' => $oldSubject->id,
            'academic_term_id' => $term->id, 'final_grade' => '1.75',
            'status' => GradeStatus::Locked, 'encoded_by' => $chair->id,
        ]);

        return [$chair, $source, $target, $student, $equivalency];
    }

    private function tokenFor(User $user): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }
}
