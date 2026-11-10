<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Academic\GradeStatus;
use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\CurriculumMigration;
use App\Models\CurriculumMigrationCredit;
use App\Models\CurriculumSubject;
use App\Models\CurriculumSubjectEquivalency;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\SubjectPrerequisite;
use App\Models\TransfereeCredit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProspectusEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
    }

    private function makeCurriculum(): Curriculum
    {
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);

        return Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
    }

    private function makeSubject(string $code): Subject
    {
        return Subject::create(['code' => $code, 'title' => $code.' Title', 'units' => 3.0, 'status' => SubjectStatus::Active]);
    }

    private function placeSubject(Curriculum $curriculum, Subject $subject, int $yearLevel, string $semester = '1st'): CurriculumSubject
    {
        return CurriculumSubject::create([
            'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
            'year_level' => $yearLevel, 'semester' => $semester, 'is_required' => true,
        ]);
    }

    private function makeStudent(Curriculum $curriculum, string $email = 'student.prospectus@grc.test', int $yearLevel = 2): StudentProfile
    {
        $user = User::create([
            'name' => 'Test Student', 'email' => $email,
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => 'STU-'.$user->id,
            'program_id' => $curriculum->program_id,
            'curriculum_id' => $curriculum->id,
            'year_level' => $yearLevel,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function tokenForNewUser(UserRole $role, string $email): string
    {
        $user = User::create([
            'name' => 'Test '.$role->value, 'email' => $email,
            'password' => self::PASSWORD, 'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return $this->tokenFor($user);
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/prospectus')->assertUnauthorized();
    }

    public function test_a_faculty_role_is_forbidden(): void
    {
        $token = $this->tokenForNewUser(UserRole::Faculty, 'faculty.prospectus@grc.test');

        $this->withToken($token)->getJson('/api/v1/prospectus')->assertForbidden();
    }

    public function test_a_student_sees_their_own_prospectus_with_blank_and_filled_entries(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $taken = $this->makeSubject('CS101');
        $untaken = $this->makeSubject('CS102');
        $this->placeSubject($curriculum, $taken, 1, '1st');
        $this->placeSubject($curriculum, $untaken, 1, '2nd');
        $student = $this->makeStudent($curriculum);
        $professor = User::create(['name' => 'Prof', 'email' => 'prof.prospectus@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        AcademicGrade::create([
            'student_id' => $student->id, 'subject_id' => $taken->id, 'academic_term_id' => $term->id,
            'mark' => '1.75', 'status' => GradeStatus::Locked, 'encoded_by' => $professor->id,
        ]);
        $token = $this->tokenFor($student->user);

        $response = $this->withToken($token)->getJson('/api/v1/prospectus');

        $response->assertOk();
        $semesters = $response->json('data.semesters');
        self::assertCount(2, $semesters);

        $firstSemEntries = collect($semesters)->firstWhere('semester', '1st')['entries'];
        $takenEntry = collect($firstSemEntries)->firstWhere('code', 'CS101');
        self::assertSame('1.75', $takenEntry['mark']);
        self::assertSame('Very Good', $takenEntry['mark_label']);
        self::assertSame(1, $takenEntry['attempt_count']);

        $secondSemEntries = collect($semesters)->firstWhere('semester', '2nd')['entries'];
        $untakenEntry = collect($secondSemEntries)->firstWhere('code', 'CS102');
        self::assertNull($untakenEntry['mark']);
        self::assertSame(0, $untakenEntry['attempt_count']);
    }

    public function test_a_subjects_prerequisites_are_included_on_its_prospectus_entry(): void
    {
        $curriculum = $this->makeCurriculum();
        $prerequisite = $this->makeSubject('CS100');
        $subject = $this->makeSubject('CS101');
        $this->placeSubject($curriculum, $prerequisite, 1, '1st');
        $placement = $this->placeSubject($curriculum, $subject, 1, '2nd');
        SubjectPrerequisite::create([
            'curriculum_subject_id' => $placement->id,
            'prerequisite_subject_id' => $prerequisite->id,
            'minimum_grade' => '75',
        ]);
        $student = $this->makeStudent($curriculum);
        $token = $this->tokenFor($student->user);

        $response = $this->withToken($token)->getJson('/api/v1/prospectus');

        $response->assertOk();
        $semesters = $response->json('data.semesters');

        $secondSemEntries = collect($semesters)->firstWhere('semester', '2nd')['entries'];
        $entry = collect($secondSemEntries)->firstWhere('code', 'CS101');
        self::assertSame([
            [
                'subject_id' => $prerequisite->id,
                'code' => 'CS100',
                'title' => 'CS100 Title',
                'minimum_grade' => '75',
            ],
        ], $entry['prerequisites']);

        $firstSemEntries = collect($semesters)->firstWhere('semester', '1st')['entries'];
        $prerequisiteEntry = collect($firstSemEntries)->firstWhere('code', 'CS100');
        self::assertSame([], $prerequisiteEntry['prerequisites']);
    }

    public function test_a_migrated_student_sees_read_only_old_to_new_credited_subjects(): void
    {
        $term = $this->makeTerm();
        $target = $this->makeCurriculum();
        $source = Curriculum::create([
            'program_id' => $target->program_id,
            'name' => 'BSCS 2021 Curriculum',
            'effective_school_year' => '2021-2022',
            'status' => CurriculumStatus::Archived,
        ]);
        $oldSubject = $this->makeSubject('CS-OLD');
        $newSubject = $this->makeSubject('CS-NEW');
        $this->placeSubject($source, $oldSubject, 1);
        $this->placeSubject($target, $newSubject, 1);
        $student = $this->makeStudent($target, 'migrated.prospectus@grc.test');
        $chair = User::create([
            'name' => 'Chair', 'email' => 'chair.migrated.prospectus@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::ProgramChair, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $grade = AcademicGrade::create([
            'student_id' => $student->id, 'subject_id' => $oldSubject->id, 'academic_term_id' => $term->id,
            'mark' => '1.75', 'status' => GradeStatus::Locked, 'encoded_by' => $chair->id,
        ]);
        $equivalency = CurriculumSubjectEquivalency::create([
            'source_curriculum_id' => $source->id, 'target_curriculum_id' => $target->id,
            'source_subject_id' => $oldSubject->id, 'target_subject_id' => $newSubject->id,
        ]);
        $migration = CurriculumMigration::create([
            'student_id' => $student->id, 'source_curriculum_id' => $source->id,
            'target_curriculum_id' => $target->id, 'processed_by' => $chair->id,
            'migrated_at' => now(),
        ]);
        CurriculumMigrationCredit::create([
            'curriculum_migration_id' => $migration->id,
            'curriculum_subject_equivalency_id' => $equivalency->id,
            'source_academic_grade_id' => $grade->id,
            'target_subject_id' => $newSubject->id,
        ]);

        $response = $this->withToken($this->tokenFor($student->user))
            ->getJson('/api/v1/prospectus');

        $response
            ->assertOk()
            ->assertJsonPath('data.curriculum_transition.source_curriculum_name', 'BSCS 2021 Curriculum')
            ->assertJsonPath('data.curriculum_transition.target_curriculum_name', 'BSCS Curriculum')
            ->assertJsonPath('data.curriculum_transition.credits.0.source_code', 'CS-OLD')
            ->assertJsonPath('data.curriculum_transition.credits.0.target_code', 'CS-NEW');
    }

    /**
     * @return array{student: StudentProfile, credited: Subject, notYet: Subject}
     */
    private function transfereeWithCredits(): array
    {
        $curriculum = $this->makeCurriculum();
        $credited = $this->makeSubject('CS-XFER');
        $notYet = $this->makeSubject('CS-LATER');
        $withTheChair = $this->makeSubject('CS-CHAIR');
        $turnedDown = $this->makeSubject('CS-NO');
        foreach ([$credited, $notYet, $withTheChair, $turnedDown] as $subject) {
            $this->placeSubject($curriculum, $subject, 1);
        }
        $student = $this->makeStudent($curriculum, 'transferee.prospectus@grc.test');
        foreach ([
            [$credited, TransfereeCreditStatus::Approved, '1.75'],
            [$notYet, TransfereeCreditStatus::Endorsed, '2.00'],
            [$withTheChair, TransfereeCreditStatus::Pending, '2.25'],
            [$turnedDown, TransfereeCreditStatus::Rejected, '3.00'],
        ] as [$subject, $status, $grade]) {
            TransfereeCredit::create([
                'student_id' => $student->id, 'source_institution' => 'Other University',
                'source_subject_code' => 'EXT101', 'source_subject_title' => 'Programming',
                'source_grade' => $grade,
                'credited_units' => 3, 'source_school_year' => '2023-2024', 'source_semester' => '1st',
                'subject_id' => $subject->id, 'status' => $status,
            ]);
        }

        return ['student' => $student, 'credited' => $credited, 'notYet' => $notYet];
    }

    /**
     * Owner, 2026-10-15: what the Registrar Head approved is recorded; what only the Program Chair
     * passed on is listed too, marked as still needing the Head's approval. A credit still with the
     * Chair, or turned down, is not on the Prospectus.
     */
    public function test_approved_and_chair_passed_transferee_credits_are_listed_and_the_rest_are_not(): void
    {
        ['student' => $student] = $this->transfereeWithCredits();

        $this->withToken($this->tokenFor($student->user))
            ->getJson('/api/v1/prospectus')
            ->assertOk()
            ->assertJsonCount(2, 'data.transferee_credits')
            ->assertJsonPath('data.transferee_credits.0.target_code', 'CS-XFER')
            ->assertJsonPath('data.transferee_credits.0.source_institution', 'Other University')
            ->assertJsonPath('data.transferee_credits.0.source_school_year', '2023-2024')
            ->assertJsonPath('data.transferee_credits.0.source_grade', '1.75')
            ->assertJsonPath('data.transferee_credits.0.status', 'approved')
            ->assertJsonPath('data.transferee_credits.0.awaiting_registrar_head_approval', false)
            ->assertJsonPath('data.transferee_credits.1.target_code', 'CS-LATER')
            ->assertJsonPath('data.transferee_credits.1.status', 'endorsed')
            ->assertJsonPath('data.transferee_credits.1.status_label', 'Awaiting Registrar Head approval')
            ->assertJsonPath('data.transferee_credits.1.awaiting_registrar_head_approval', true);
    }

    public function test_a_credited_subject_carries_its_previous_school_grade_on_its_own_curriculum_row(): void
    {
        ['student' => $student] = $this->transfereeWithCredits();

        $response = $this->withToken($this->tokenFor($student->user))->getJson('/api/v1/prospectus')->assertOk();

        $entries = collect($response->json('data.semesters'))->flatMap(fn (array $semester): array => $semester['entries'])->keyBy('code');
        self::assertSame('approved', $entries['CS-XFER']['credit']['status']);
        self::assertSame('1.75', $entries['CS-XFER']['credit']['source_grade']);
        self::assertSame('Other University', $entries['CS-XFER']['credit']['source_institution']);
        self::assertFalse($entries['CS-XFER']['credit']['awaiting_registrar_head_approval']);
        self::assertSame('endorsed', $entries['CS-LATER']['credit']['status']);
        self::assertTrue($entries['CS-LATER']['credit']['awaiting_registrar_head_approval']);
        // Still with the Chair, or turned down: nothing is recorded against the subject.
        self::assertNull($entries['CS-CHAIR']['credit']);
        self::assertNull($entries['CS-NO']['credit']);
    }

    public function test_a_student_cannot_view_another_students_prospectus(): void
    {
        $curriculum = $this->makeCurriculum();
        $owner = $this->makeStudent($curriculum, 'owner.prospectus@grc.test');
        $other = $this->makeStudent($curriculum, 'other.prospectus@grc.test');
        $token = $this->tokenFor($other->user);

        $this->withToken($token)
            ->getJson('/api/v1/prospectus?student_id='.$owner->id)
            ->assertForbidden();
    }

    public function test_a_registrar_head_can_view_any_students_prospectus(): void
    {
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $registrarToken = $this->tokenForNewUser(UserRole::RegistrarHead, 'registrar.prospectus@grc.test');

        $this->withToken($registrarToken)
            ->getJson('/api/v1/prospectus?student_id='.$student->id)
            ->assertOk()
            ->assertJsonPath('data.student_id', $student->id);
    }

    public function test_a_registrar_staff_can_view_any_students_prospectus(): void
    {
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $registrarToken = $this->tokenForNewUser(UserRole::RegistrarStaff, 'registrar-staff.prospectus@grc.test');

        $this->withToken($registrarToken)
            ->getJson('/api/v1/prospectus?student_id='.$student->id)
            ->assertOk();
    }

    public function test_a_program_chair_can_view_a_students_prospectus(): void
    {
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $chairToken = $this->tokenForNewUser(UserRole::ProgramChair, 'chair.prospectus@grc.test');

        $this->withToken($chairToken)
            ->getJson('/api/v1/prospectus?student_id='.$student->id)
            ->assertOk()
            ->assertJsonPath('data.student_id', $student->id);
    }

    public function test_a_grade_for_a_subject_outside_the_curriculum_is_surfaced_as_unplaced(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $unplacedSubject = $this->makeSubject('TRANSFER101');
        $student = $this->makeStudent($curriculum);
        $professor = User::create(['name' => 'Prof', 'email' => 'prof.unplaced@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        AcademicGrade::create([
            'student_id' => $student->id, 'subject_id' => $unplacedSubject->id, 'academic_term_id' => $term->id,
            'mark' => '2.00', 'status' => GradeStatus::Locked, 'encoded_by' => $professor->id,
        ]);
        $token = $this->tokenFor($student->user);

        $response = $this->withToken($token)->getJson('/api/v1/prospectus');

        $response->assertOk();
        self::assertSame([], $response->json('data.semesters'));
        $unplaced = $response->json('data.unplaced_entries');
        self::assertCount(1, $unplaced);
        self::assertSame('TRANSFER101', $unplaced[0]['code']);
    }

    public function test_fixed_semester_placement_retains_curriculum_slot_regardless_of_attempt_term_semester(): void
    {
        $term1st = $this->makeTerm(); // semester: 1st
        $curriculum = $this->makeCurriculum();
        $sub1 = $this->makeSubject('CS101');
        $sub2 = $this->makeSubject('CS102');
        $this->placeSubject($curriculum, $sub1, 1, '1st');
        $this->placeSubject($curriculum, $sub2, 1, '2nd');

        $student = $this->makeStudent($curriculum);
        $professor = User::create(['name' => 'Prof', 'email' => 'prof.fixed@grc.test', 'password' => self::PASSWORD, 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);

        // Student took CS102 (a 2nd sem subject) during a 1st sem term
        AcademicGrade::create([
            'student_id' => $student->id, 'subject_id' => $sub2->id, 'academic_term_id' => $term1st->id,
            'mark' => '2.00', 'status' => GradeStatus::Locked, 'encoded_by' => $professor->id,
        ]);

        $token = $this->tokenFor($student->user);
        $response = $this->withToken($token)->getJson('/api/v1/prospectus');

        $response->assertOk();
        $semesters = $response->json('data.semesters');
        self::assertCount(2, $semesters);
        self::assertSame(1, $semesters[0]['year_level']);
        self::assertSame('1st', $semesters[0]['semester']);
        self::assertSame(['CS101'], array_column($semesters[0]['entries'], 'code'));

        self::assertSame(1, $semesters[1]['year_level']);
        self::assertSame('2nd', $semesters[1]['semester']);
        self::assertSame(['CS102'], array_column($semesters[1]['entries'], 'code'));
    }
}
