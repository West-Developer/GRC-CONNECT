<?php

namespace Tests\Unit\Actions\Academic;

use App\Actions\Academic\ResolveCreditedSubjectIds;
use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\TransfereeCredit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ResolveCreditedSubjectIdsTest extends TestCase
{
    use RefreshDatabase;

    private function makeCurriculum(): Curriculum
    {
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);

        return Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
    }

    private function makeStudent(Curriculum $curriculum, string $email, string $number): StudentProfile
    {
        $user = User::create([
            'name' => 'Test Student', 'email' => $email,
            'password' => 'correct-horse-battery-staple', 'role' => UserRole::Student, 'status' => UserStatus::Active,
        ]);

        return StudentProfile::create([
            'user_id' => $user->id, 'student_number' => $number,
            'program_id' => $curriculum->program_id, 'curriculum_id' => $curriculum->id,
            'year_level' => 1, 'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    private function makeSubject(string $code): Subject
    {
        return Subject::create(['code' => $code, 'title' => $code.' Title', 'units' => 3.0, 'status' => SubjectStatus::Active]);
    }

    private function credit(StudentProfile $student, ?Subject $subject, TransfereeCreditStatus $status): void
    {
        TransfereeCredit::create([
            'student_id' => $student->id, 'source_institution' => 'Other University',
            'source_subject_code' => 'EXT101', 'source_subject_title' => 'Programming',
            'credited_units' => 3, 'subject_id' => $subject?->id, 'status' => $status,
        ]);
    }

    public function test_only_an_approved_credit_mapped_to_a_subject_counts(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(), 'resolver.a@grc.test', '2026-0001');
        $approved = $this->makeSubject('CS101');
        $pending = $this->makeSubject('CS102');
        $endorsed = $this->makeSubject('CS103');
        $rejected = $this->makeSubject('CS104');
        $this->credit($student, $approved, TransfereeCreditStatus::Approved);
        $this->credit($student, $pending, TransfereeCreditStatus::Pending);
        $this->credit($student, $endorsed, TransfereeCreditStatus::Endorsed);
        $this->credit($student, $rejected, TransfereeCreditStatus::Rejected);
        // Approved, but never mapped to a GRC subject: credits nothing.
        $this->credit($student, null, TransfereeCreditStatus::Approved);

        $result = (new ResolveCreditedSubjectIds)->fromApprovedTransfereeCredits([$student->id]);

        self::assertSame([$approved->id => true], $result[$student->id]);
    }

    public function test_an_endorsed_credit_counts_only_when_asked_for_as_provisional(): void
    {
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'resolver.d@grc.test', '2026-0004');
        $approved = $this->makeSubject('CS101');
        $endorsed = $this->makeSubject('CS103');
        $pending = $this->makeSubject('CS102');
        $this->credit($student, $approved, TransfereeCreditStatus::Approved);
        $this->credit($student, $endorsed, TransfereeCreditStatus::Endorsed);
        $this->credit($student, $pending, TransfereeCreditStatus::Pending);
        // Endorsed but never mapped to a GRC subject: credits nothing.
        $this->credit($student, null, TransfereeCreditStatus::Endorsed);
        $resolver = new ResolveCreditedSubjectIds;

        self::assertSame([$endorsed->id => true], $resolver->fromEndorsedTransfereeCredits([$student->id])[$student->id]);
        // By default only the Registrar Head's approved credits count (promotion, mapping suggestions).
        self::assertSame([$approved->id => true], $resolver->forStudents([$student->id], $curriculum->id)[$student->id]);
        // Enrolment counts what the Chair has passed on, until the Head decides.
        self::assertSame(
            [$approved->id => true, $endorsed->id => true],
            $resolver->forStudents([$student->id], $curriculum->id, includeEndorsed: true)[$student->id],
        );
    }

    public function test_credits_are_kept_per_student(): void
    {
        $curriculum = $this->makeCurriculum();
        $one = $this->makeStudent($curriculum, 'resolver.b@grc.test', '2026-0002');
        $two = $this->makeStudent($curriculum, 'resolver.c@grc.test', '2026-0003');
        $subject = $this->makeSubject('CS101');
        $this->credit($one, $subject, TransfereeCreditStatus::Approved);

        $result = (new ResolveCreditedSubjectIds)->forStudents([$one->id, $two->id], $curriculum->id);

        self::assertSame([$subject->id => true], $result[$one->id]);
        self::assertArrayNotHasKey($two->id, $result);
    }

    public function test_an_empty_list_of_students_resolves_nothing(): void
    {
        self::assertSame([], (new ResolveCreditedSubjectIds)->forStudents([], 1));
    }
}
