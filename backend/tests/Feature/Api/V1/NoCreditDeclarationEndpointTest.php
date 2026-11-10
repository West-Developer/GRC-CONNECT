<?php

namespace Tests\Feature\Api\V1;

use App\Actions\Academic\EvaluateCreditMappingStatus;
use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\NoCreditDeclaration;
use App\Models\Notification;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\TransfereeCredit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "No subject to credit": a Transferee or Returnee with nothing to credit says so, the Program Chair of
 * their college confirms it, and only then may they enroll (ADR 0026 / 0050, amended). Every test
 * authenticates as exactly one actor (the documented Sanctum test gotcha), so a state a second actor would
 * have produced is seeded through Eloquent.
 */
final class NoCreditDeclarationEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private int $sequence = 0;

    private function makeCurriculum(CollegeCode $college, string $code = 'BSIT'): Curriculum
    {
        $program = Program::query()->where('code', $code)->first() ?? Program::create([
            'code' => $code, 'name' => 'BS '.$code, 'status' => ProgramStatus::Active, 'college' => $college,
        ]);

        return Curriculum::query()->where('program_id', $program->id)->first() ?? Curriculum::create([
            'program_id' => $program->id, 'name' => $code.' Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
    }

    private function makeStudent(Curriculum $curriculum, StudentType $type = StudentType::Returnee): StudentProfile
    {
        $this->sequence++;
        $user = User::create([
            'name' => 'Test Student '.$this->sequence, 'email' => "student.nocredit{$this->sequence}@grc.test",
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => sprintf('2026-10-%05d', $this->sequence),
            'program_id' => $curriculum->program_id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'student_type' => $type,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    private function makeUser(UserRole $role, ?CollegeCode $college = null): User
    {
        $this->sequence++;

        return User::create([
            'name' => 'Test '.$role->value, 'email' => "{$role->value}.nocredit{$this->sequence}@grc.test",
            'password' => self::PASSWORD, 'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
            'college' => $college,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function declaration(StudentProfile $student, bool $confirmed = false): NoCreditDeclaration
    {
        return NoCreditDeclaration::create([
            'student_id' => $student->id,
            'declared_at' => now(),
            'confirmed_at' => $confirmed ? now() : null,
        ]);
    }

    public function test_a_returnee_declares_it_the_chair_of_their_college_is_told_and_they_stay_held_back(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $ccsChair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);
        $this->makeUser(UserRole::ProgramChair, CollegeCode::Coe);
        $token = $this->tokenFor($student->user);

        $this->withToken($token)->getJson('/api/v1/no-credit-declaration')->assertOk()->assertJsonPath('data', null);

        $this->withToken($token)->postJson('/api/v1/no-credit-declaration')
            ->assertCreated()
            ->assertJsonPath('data.type', 'no_credit_declaration')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.student_number', $student->student_number)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->withToken($token)->getJson('/api/v1/no-credit-declaration')->assertOk()->assertJsonPath('data.status', 'pending');

        $recipients = Notification::query()->where('type', NotificationType::TransfereeNoCreditDeclared->value)->pluck('user_id')->all();
        self::assertSame([$ccsChair->id], $recipients);
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::NO_CREDIT_DECLARED)->count());

        $status = (new EvaluateCreditMappingStatus)->execute($student->refresh());
        self::assertFalse($status->isCompleted);
        self::assertStringContainsString('no subject to credit', (string) $status->reason);
    }

    public function test_declaring_twice_is_the_same_declaration_and_tells_nobody_again(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);
        $token = $this->tokenFor($student->user);

        $this->withToken($token)->postJson('/api/v1/no-credit-declaration')->assertCreated();
        $this->withToken($token)->postJson('/api/v1/no-credit-declaration')->assertOk()->assertJsonPath('data.status', 'pending');

        self::assertSame(1, NoCreditDeclaration::query()->count());
        self::assertSame(1, Notification::query()->where('type', NotificationType::TransfereeNoCreditDeclared->value)->count());
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::NO_CREDIT_DECLARED)->count());
    }

    public function test_a_student_who_already_has_credits_must_submit_those_instead(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs), StudentType::Transferee);
        TransfereeCredit::create([
            'student_id' => $student->id, 'source_institution' => 'Other University', 'source_subject_code' => 'EXT101',
            'source_subject_title' => 'Introduction to Programming', 'credited_units' => 3, 'requested_by' => $student->user_id,
            'status' => TransfereeCreditStatus::Draft,
        ]);

        $this->withToken($this->tokenFor($student->user))->postJson('/api/v1/no-credit-declaration')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['errors' => ['student_id']]]);

        self::assertSame(0, NoCreditDeclaration::query()->count());
    }

    public function test_a_returnee_whose_every_credit_was_rejected_may_say_they_have_nothing_to_credit(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        foreach (['EXT101', 'EXT102'] as $code) {
            TransfereeCredit::create([
                'student_id' => $student->id, 'source_institution' => 'Other University', 'source_subject_code' => $code,
                'source_subject_title' => 'Subject '.$code, 'credited_units' => 3, 'requested_by' => $student->user_id,
                'status' => TransfereeCreditStatus::Rejected,
            ]);
        }
        // Rejected credits leave a Returnee held back: nothing was credited and nothing is left to map.
        self::assertFalse((new EvaluateCreditMappingStatus)->execute($student->refresh())->isCompleted);
        $chairToken = $this->tokenFor($this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs));
        $studentToken = $this->tokenFor($student->user);
        $this->app['auth']->forgetGuards();

        $this->withToken($studentToken)->postJson('/api/v1/no-credit-declaration')->assertCreated();
        self::assertFalse((new EvaluateCreditMappingStatus)->execute($student->refresh())->isCompleted);

        $this->app['auth']->forgetGuards();
        $declaration = NoCreditDeclaration::query()->firstOrFail();
        $this->withToken($chairToken)->postJson("/api/v1/no-credit-declarations/{$declaration->id}/confirm")->assertOk();

        self::assertTrue((new EvaluateCreditMappingStatus)->execute($student->refresh())->isCompleted);
    }

    public function test_a_credit_the_chair_approved_or_a_pending_one_still_blocks_declaring(): void
    {
        foreach ([TransfereeCreditStatus::Pending, TransfereeCreditStatus::Endorsed, TransfereeCreditStatus::Approved] as $status) {
            $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs), StudentType::Transferee);
            TransfereeCredit::create([
                'student_id' => $student->id, 'source_institution' => 'Other University', 'source_subject_code' => 'EXT1',
                'source_subject_title' => 'Subject '.$status->value, 'credited_units' => 3, 'requested_by' => $student->user_id,
                'status' => $status,
            ]);
            $token = $this->tokenFor($student->user);
            $this->app['auth']->forgetGuards();

            $this->withToken($token)->postJson('/api/v1/no-credit-declaration')->assertUnprocessable();
            $this->app['auth']->forgetGuards();
        }

        self::assertSame(0, NoCreditDeclaration::query()->count());
    }

    public function test_only_a_transferee_or_a_returnee_may_declare(): void
    {
        $curriculum = $this->makeCurriculum(CollegeCode::Ccs);

        foreach ([StudentType::Freshman, StudentType::ExistingStudent] as $type) {
            $student = $this->makeStudent($curriculum, $type);
            $this->app['auth']->forgetGuards();

            $this->withToken($this->tokenFor($student->user))->postJson('/api/v1/no-credit-declaration')
                ->assertUnprocessable()
                ->assertJsonStructure(['error' => ['errors' => ['student_id']]]);
        }

        $this->app['auth']->forgetGuards();
        $chairToken = $this->tokenFor($this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs));
        $this->app['auth']->forgetGuards();
        $this->withToken($chairToken)->postJson('/api/v1/no-credit-declaration')->assertForbidden();
    }

    public function test_saving_a_credit_replaces_a_declaration_the_chair_has_not_confirmed(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs), StudentType::Transferee);
        $this->declaration($student);
        $token = $this->tokenFor($student->user);

        $this->withToken($token)->postJson('/api/v1/transferee-credits', [
            'source_institution' => 'Other University',
            'source_subject_title' => 'Introduction to Programming',
            'credited_units' => 3,
            'source_school_year' => '2023-2024',
            'source_semester' => '1st',
        ])->assertCreated();

        self::assertSame(0, NoCreditDeclaration::query()->count());
    }

    public function test_the_chair_lists_only_the_waiting_declarations_of_their_own_college(): void
    {
        $ccs = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $coe = $this->makeStudent($this->makeCurriculum(CollegeCode::Coe, 'BSCE'));
        $confirmed = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs), StudentType::Transferee);
        $this->declaration($ccs);
        $this->declaration($coe);
        $this->declaration($confirmed, confirmed: true);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $response = $this->withToken($this->tokenFor($chair))->getJson('/api/v1/no-credit-declarations')->assertOk();

        self::assertSame([$ccs->student_number], collect($response->json('data'))->pluck('student_number')->all());
        self::assertSame('pending', $response->json('data.0.status'));
    }

    public function test_the_chair_confirms_it_the_student_is_told_and_the_mapping_is_complete(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $declaration = $this->declaration($student);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);
        $token = $this->tokenFor($chair);

        $this->withToken($token)->postJson("/api/v1/no-credit-declarations/{$declaration->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $declaration->refresh();
        self::assertTrue($declaration->isConfirmed());
        self::assertSame($chair->id, $declaration->confirmed_by_user_id);
        self::assertSame(
            [$student->user_id],
            Notification::query()->where('type', NotificationType::TransfereeNoCreditConfirmed->value)->pluck('user_id')->all(),
        );
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::NO_CREDIT_CONFIRMED)->count());
        self::assertTrue((new EvaluateCreditMappingStatus)->execute($student->refresh())->isCompleted);
    }

    public function test_confirming_twice_confirms_once(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $declaration = $this->declaration($student);
        $token = $this->tokenFor($this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs));

        $this->withToken($token)->postJson("/api/v1/no-credit-declarations/{$declaration->id}/confirm")->assertOk();
        $this->withToken($token)->postJson("/api/v1/no-credit-declarations/{$declaration->id}/confirm")->assertOk();

        self::assertSame(1, Notification::query()->where('type', NotificationType::TransfereeNoCreditConfirmed->value)->count());
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::NO_CREDIT_CONFIRMED)->count());
    }

    public function test_a_confirmed_transferee_is_complete_and_gets_their_queue_number(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs), StudentType::Transferee);
        $declaration = $this->declaration($student);
        $token = $this->tokenFor($this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs));

        $this->withToken($token)->postJson("/api/v1/no-credit-declarations/{$declaration->id}/confirm")->assertOk();

        self::assertTrue((new EvaluateCreditMappingStatus)->execute($student->refresh())->isCompleted);
        $number = Notification::query()->where('user_id', $student->user_id)->where('type', NotificationType::TransfereeQueueNumberIssued->value)->first();
        self::assertNotNull($number);
        self::assertStringContainsString('no subject to credit', $number->message);
        self::assertStringNotContainsString('Registrar Head still has to approve', $number->message);
    }

    public function test_a_chair_of_another_college_the_student_and_the_registrar_cannot_confirm_or_list(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $declaration = $this->declaration($student);

        foreach ([
            $this->makeUser(UserRole::ProgramChair, CollegeCode::Coe),
            $student->user,
            $this->makeUser(UserRole::RegistrarHead),
        ] as $actor) {
            $this->app['auth']->forgetGuards();
            $token = $this->tokenFor($actor);

            self::assertSame(403, $this->withToken($token)->postJson("/api/v1/no-credit-declarations/{$declaration->id}/confirm")->status(), 'confirm as '.$actor->role->value.' '.($actor->college?->value ?? '-'));
            if ($actor->role !== UserRole::ProgramChair) {
                $this->app['auth']->forgetGuards();
                self::assertSame(403, $this->withToken($token)->getJson('/api/v1/no-credit-declarations')->status(), 'list as '.$actor->role->value);
            }
        }

        self::assertFalse($declaration->refresh()->isConfirmed());
    }

    public function test_the_review_counts_are_the_students_and_enrollments_waiting_for_the_chair(): void
    {
        $curriculum = $this->makeCurriculum(CollegeCode::Ccs);
        $withCredits = $this->makeStudent($curriculum, StudentType::Transferee);
        foreach (['EXT101', 'EXT102'] as $code) {
            TransfereeCredit::create([
                'student_id' => $withCredits->id, 'source_institution' => 'Other University', 'source_subject_code' => $code,
                'source_subject_title' => 'Subject '.$code, 'credited_units' => 3, 'requested_by' => $withCredits->user_id,
                'status' => TransfereeCreditStatus::Pending,
            ]);
        }
        $this->declaration($this->makeStudent($curriculum));
        $this->declaration($this->makeStudent($curriculum), confirmed: true);
        $this->declaration($this->makeStudent($this->makeCurriculum(CollegeCode::Coe, 'BSCE')));
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->tokenFor($chair))->getJson('/api/v1/program-chair/review-counts')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.type', 'program_chair_review_counts')
            ->assertJsonPath('data.credit_mappings', 2)
            ->assertJsonPath('data.irregular_advising', 0);
    }

    public function test_only_a_program_chair_reads_the_review_counts(): void
    {
        $this->getJson('/api/v1/program-chair/review-counts')->assertUnauthorized();

        $token = $this->tokenFor($this->makeUser(UserRole::RegistrarHead));
        $this->withToken($token)->getJson('/api/v1/program-chair/review-counts')->assertForbidden();
    }
}
