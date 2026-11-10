<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AdmissionRequirementType;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\StudentAdmissionRequirement;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stakeholder Doc 14 S26 (ADR 0037): the Admission requirements checklist.
 * Admission Staff tick requirements off and add new ones; a Student reads
 * their own list, made of their student type's category plus Additional.
 */
final class AdmissionRequirementsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(UserRole $role): User
    {
        return User::create([
            'name' => 'Test '.$role->value.' '.++$this->seq,
            'email' => $role->value.'.'.$this->seq.'.adm@grc.test',
            'password' => 'correct-horse-battery-staple',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('adm-test')->plainTextToken;
    }

    private function student(?StudentType $type): StudentProfile
    {
        $program = Program::query()->first()
            ?? Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::query()->first()
            ?? Curriculum::create(['program_id' => $program->id, 'name' => 'BSCS', 'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active]);

        return StudentProfile::create([
            'user_id' => $this->user(UserRole::Student)->id,
            'student_number' => 'ADM-'.++$this->seq,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'student_type' => $type,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    private function typeId(string $category, string $name): int
    {
        return (int) AdmissionRequirementType::query()->where('category', $category)->where('name', $name)->value('id');
    }

    public function test_admission_staff_can_list_the_requirements_that_apply_to_a_year_level(): void
    {
        $token = $this->token($this->user(UserRole::AdmissionStaff));

        $first = $this->withToken($token)->getJson('/api/v1/admission-requirement-types?year_level=1')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.type', 'admission_requirement_selection')
            ->assertJsonPath('data.student_type', 'freshman');
        self::assertSame(['freshman', 'additional'], array_column($first->json('data.categories'), 'category'));
        self::assertSame(
            ['Form 137', 'Form 138', 'Good Moral Character', 'Certificate of Ratings'],
            array_column($first->json('data.categories.0.items'), 'name'),
        );
        self::assertCount(6, $first->json('data.categories.1.items'));

        $later = $this->withToken($token)->getJson('/api/v1/admission-requirement-types?year_level=3')
            ->assertOk()
            ->assertJsonPath('data.student_type', 'transferee');
        self::assertSame(['transferee', 'additional'], array_column($later->json('data.categories'), 'category'));
    }

    public function test_the_requirement_list_follows_the_student_type_admission_chose(): void
    {
        $token = $this->token($this->user(UserRole::AdmissionStaff));

        $expected = [
            'freshman' => ['freshman', 'additional'],
            'transferee' => ['transferee', 'additional'],
            'returnee' => ['additional'],
            'existing_student' => ['additional'],
        ];
        foreach ($expected as $type => $categories) {
            $response = $this->withToken($token)->getJson('/api/v1/admission-requirement-types?student_type='.$type)
                ->assertOk()
                ->assertJsonPath('data.student_type', $type);
            self::assertSame($categories, array_column($response->json('data.categories'), 'category'), $type);
        }
        $this->withToken($token)->getJson('/api/v1/admission-requirement-types?student_type=existing_student')
            ->assertJsonPath('data.student_type_label', 'Existing Student');

        $this->withToken($token)->getJson('/api/v1/admission-requirement-types?student_type=visitor')->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/admission-requirement-types')->assertUnprocessable();
    }

    public function test_the_requirement_list_for_a_year_level_is_for_admission_staff_only_and_needs_a_valid_year(): void
    {
        $this->getJson('/api/v1/admission-requirement-types?year_level=1')->assertUnauthorized();
        $studentToken = $this->token($this->user(UserRole::Student));
        $staffToken = $this->token($this->user(UserRole::AdmissionStaff));
        $this->withToken($studentToken)
            ->getJson('/api/v1/admission-requirement-types?year_level=1')
            ->assertForbidden();
        // Sanctum caches the user per test; a second user needs the guard reset.
        app('auth')->forgetGuards();
        $this->withToken($staffToken)
            ->getJson('/api/v1/admission-requirement-types?year_level=9')
            ->assertUnprocessable();
    }

    public function test_the_stakeholders_list_is_seeded_exactly(): void
    {
        $seeded = AdmissionRequirementType::query()->orderBy('category')->orderBy('sort_order')->get()
            ->groupBy(fn (AdmissionRequirementType $type): string => $type->category->value)
            ->map(fn ($group): array => $group->pluck('name')->all())
            ->all();

        self::assertSame([
            'Form 137',
            'Form 138',
            'Good Moral Character',
            'Certificate of Ratings',
        ], $seeded['freshman']);
        self::assertSame([
            'Certificate of Grades',
            'TOR (Original copy for GRC)',
            'Honorable Dismissal (Original)',
            'Good Moral Character (Original)',
        ], $seeded['transferee']);
        self::assertSame([
            '2 Pcs 2x2 Picture (White background & nametag)',
            '2 Pcs 1x1 Picture (White background)',
            'Original Birth Certificate (PSA)',
            'Original Marriage Certificate (PSA if Married)',
            '(3PCS) LONG BROWN EXPANDED ENVELOPE',
            'Latest Chest X-ray with normal result',
        ], $seeded['additional']);
        self::assertSame(0, AdmissionRequirementType::query()->where('is_system', true)->whereNotNull('created_by')->count());
    }

    public function test_a_freshman_sees_the_freshman_and_additional_categories(): void
    {
        $student = $this->student(StudentType::Freshman);

        $response = $this->withToken($this->token($student->user))->getJson('/api/v1/me/admission-requirements');

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.type', 'admission_requirements')
            ->assertJsonPath('data.student.student_type', 'freshman')
            ->assertJsonPath('data.summary.required_count', 10)
            ->assertJsonPath('data.summary.submitted_count', 0)
            ->assertJsonPath('data.summary.complete', false);
        self::assertSame(['freshman', 'additional'], array_column($response->json('data.categories'), 'category'));
    }

    public function test_a_transferee_sees_the_transferee_and_additional_categories(): void
    {
        $student = $this->student(StudentType::Transferee);

        $response = $this->withToken($this->token($student->user))->getJson('/api/v1/me/admission-requirements');

        $response->assertOk()->assertJsonPath('data.summary.required_count', 10);
        self::assertSame(['transferee', 'additional'], array_column($response->json('data.categories'), 'category'));
    }

    public function test_a_student_with_no_type_sees_only_the_additional_list(): void
    {
        $student = $this->student(null);

        $response = $this->withToken($this->token($student->user))->getJson('/api/v1/me/admission-requirements');

        $response->assertOk()->assertJsonPath('data.summary.required_count', 6);
        self::assertSame(['additional'], array_column($response->json('data.categories'), 'category'));
    }

    public function test_admission_staff_tick_a_requirement_and_it_is_audited_with_before_and_after(): void
    {
        $student = $this->student(StudentType::Freshman);
        $staff = $this->user(UserRole::AdmissionStaff);
        $form137 = $this->typeId('freshman', 'Form 137');

        $response = $this->withToken($this->token($staff))
            ->putJson("/api/v1/student-profiles/{$student->id}/admission-requirements/{$form137}", ['is_submitted' => true]);

        $response->assertOk()->assertJsonPath('data.summary.submitted_count', 1)->assertJsonPath('data.summary.missing_count', 9);
        $item = collect($response->json('data.categories.0.items'))->firstWhere('requirement_type_id', $form137);
        self::assertTrue($item['is_submitted']);
        self::assertNotNull($item['submitted_at']);

        $audit = AuditLog::query()->where('action', AuditAction::ADMISSION_REQUIREMENT_UPDATED)->sole();
        self::assertSame($staff->id, $audit->actor_user_id);
        self::assertSame(['student_profile_id' => $student->id, 'requirement' => 'Form 137', 'is_submitted' => false], $audit->before_values);
        self::assertSame(['student_profile_id' => $student->id, 'requirement' => 'Form 137', 'is_submitted' => true], $audit->after_values);
    }

    public function test_ticking_is_idempotent_and_unticking_is_audited(): void
    {
        $student = $this->student(StudentType::Freshman);
        $token = $this->token($this->user(UserRole::AdmissionStaff));
        $form137 = $this->typeId('freshman', 'Form 137');
        $url = "/api/v1/student-profiles/{$student->id}/admission-requirements/{$form137}";

        $this->withToken($token)->putJson($url, ['is_submitted' => true])->assertOk();
        $this->withToken($token)->putJson($url, ['is_submitted' => true])->assertOk()->assertJsonPath('data.summary.submitted_count', 1);
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::ADMISSION_REQUIREMENT_UPDATED)->count());
        self::assertSame(1, StudentAdmissionRequirement::query()->count());

        // Unticking something that was never ticked writes nothing at all.
        $envelope = $this->typeId('additional', '(3PCS) LONG BROWN EXPANDED ENVELOPE');
        $this->withToken($token)->putJson("/api/v1/student-profiles/{$student->id}/admission-requirements/{$envelope}", ['is_submitted' => false])->assertOk();
        self::assertSame(1, StudentAdmissionRequirement::query()->count());

        $this->withToken($token)->putJson($url, ['is_submitted' => false])->assertOk()->assertJsonPath('data.summary.submitted_count', 0);
        self::assertSame(2, AuditLog::query()->where('action', AuditAction::ADMISSION_REQUIREMENT_UPDATED)->count());
        self::assertNull(StudentAdmissionRequirement::query()->sole()->submitted_at);
    }

    public function test_a_requirement_of_the_other_student_type_cannot_be_ticked(): void
    {
        $student = $this->student(StudentType::Freshman);
        $token = $this->token($this->user(UserRole::AdmissionStaff));
        $transfereeOnly = $this->typeId('transferee', 'Certificate of Grades');

        $this->withToken($token)
            ->putJson("/api/v1/student-profiles/{$student->id}/admission-requirements/{$transfereeOnly}", ['is_submitted' => true])
            ->assertUnprocessable();
        self::assertSame(0, StudentAdmissionRequirement::query()->count());
    }

    public function test_the_value_must_be_a_boolean(): void
    {
        $student = $this->student(StudentType::Freshman);
        $token = $this->token($this->user(UserRole::AdmissionStaff));
        $form137 = $this->typeId('freshman', 'Form 137');

        $this->withToken($token)
            ->putJson("/api/v1/student-profiles/{$student->id}/admission-requirements/{$form137}", [])
            ->assertUnprocessable();
    }

    public function test_admission_staff_add_a_requirement_and_it_shows_for_that_category(): void
    {
        $token = $this->token($this->user(UserRole::AdmissionStaff));

        $this->withToken($token)->postJson('/api/v1/admission-requirement-types', ['name' => '  Barangay Clearance ', 'category' => 'additional'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Barangay Clearance')
            ->assertJsonPath('data.category', 'additional')
            ->assertJsonPath('data.is_system', false);

        $created = AdmissionRequirementType::query()->where('name', 'Barangay Clearance')->sole();
        self::assertSame(70, $created->sort_order);
        $audit = AuditLog::query()->where('action', AuditAction::ADMISSION_REQUIREMENT_TYPE_CREATED)->sole();
        self::assertNull($audit->before_values);
        self::assertSame(['requirement' => 'Barangay Clearance', 'category' => 'additional'], $audit->after_values);

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $student = $this->student(StudentType::Freshman);
        $this->withToken($this->token($student->user))->getJson('/api/v1/me/admission-requirements')
            ->assertOk()->assertJsonPath('data.summary.required_count', 11);
    }

    public function test_a_duplicate_requirement_in_a_category_is_refused(): void
    {
        $token = $this->token($this->user(UserRole::AdmissionStaff));

        $this->withToken($token)->postJson('/api/v1/admission-requirement-types', ['name' => 'form 137', 'category' => 'freshman'])
            ->assertUnprocessable();
        // The same name is fine in another category.
        $this->withToken($token)->postJson('/api/v1/admission-requirement-types', ['name' => 'Form 137', 'category' => 'additional'])
            ->assertCreated();
    }

    public function test_the_category_must_be_known(): void
    {
        $token = $this->token($this->user(UserRole::AdmissionStaff));

        $this->withToken($token)->postJson('/api/v1/admission-requirement-types', ['name' => 'Thing', 'category' => 'graduate'])
            ->assertUnprocessable();
    }

    public function test_admission_staff_read_any_students_checklist(): void
    {
        $student = $this->student(StudentType::Transferee);
        $token = $this->token($this->user(UserRole::AdmissionStaff));

        $this->withToken($token)->getJson("/api/v1/student-profiles/{$student->id}/admission-requirements")
            ->assertOk()->assertJsonPath('data.student.student_number', $student->student_number);
    }

    public function test_a_student_cannot_tick_or_add_or_read_another_students_list(): void
    {
        $mine = $this->student(StudentType::Freshman);
        $other = $this->student(StudentType::Freshman);
        $form137 = $this->typeId('freshman', 'Form 137');
        $token = $this->token($mine->user);

        $this->withToken($token)->putJson("/api/v1/student-profiles/{$other->id}/admission-requirements/{$form137}", ['is_submitted' => true])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/admission-requirement-types', ['name' => 'Thing', 'category' => 'additional'])->assertForbidden();
        $this->withToken($token)->getJson("/api/v1/student-profiles/{$other->id}/admission-requirements")->assertForbidden();
        self::assertSame(0, StudentAdmissionRequirement::query()->count());
    }

    public function test_other_roles_are_forbidden(): void
    {
        $student = $this->student(StudentType::Freshman);
        $form137 = $this->typeId('freshman', 'Form 137');

        foreach ([UserRole::RegistrarHead, UserRole::RegistrarStaff, UserRole::AccountingStaff, UserRole::Dean, UserRole::ProgramChair] as $role) {
            $token = $this->token($this->user($role));
            $this->withToken($token)->getJson("/api/v1/student-profiles/{$student->id}/admission-requirements")->assertForbidden();
            $this->withToken($token)->putJson("/api/v1/student-profiles/{$student->id}/admission-requirements/{$form137}", ['is_submitted' => true])->assertForbidden();
            $this->withToken($token)->postJson('/api/v1/admission-requirement-types', ['name' => 'Thing', 'category' => 'additional'])->assertForbidden();
            $this->withToken($token)->getJson('/api/v1/me/admission-requirements')->assertForbidden();
            $this->flushHeaders();
            $this->app['auth']->forgetGuards();
        }
    }
}
