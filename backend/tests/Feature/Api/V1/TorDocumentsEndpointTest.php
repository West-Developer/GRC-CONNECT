<?php

namespace Tests\Feature\Api\V1;

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
use App\Models\Curriculum;
use App\Models\Notification;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\StudentTorDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A transferee's / returnee's Transcript of Records, uploaded for credit
 * mapping (ADR 0026). Every test authenticates as exactly one actor (the
 * documented Sanctum test gotcha); other people's state is seeded directly.
 */
final class TorDocumentsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function makeCurriculum(CollegeCode $college, string $code): Curriculum
    {
        $program = Program::create(['code' => $code, 'name' => 'BS '.$code, 'status' => ProgramStatus::Active, 'college' => $college]);

        return Curriculum::create([
            'program_id' => $program->id, 'name' => $code.' Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
    }

    private function makeStudent(Curriculum $curriculum, ?StudentType $type): StudentProfile
    {
        $this->sequence++;
        $user = User::create([
            'name' => 'Test Student '.$this->sequence, 'email' => "student.tor{$this->sequence}@grc.test",
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => sprintf('2026-%04d', $this->sequence),
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
            'name' => 'Test '.$role->value, 'email' => "{$role->value}.tor{$this->sequence}@grc.test",
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

    /**
     * Acts as `$user` for the next request. Sanctum caches the resolved user on the
     * guard for the rest of a test, so a test that acts as several people clears
     * the guards before each switch (no state of the other actors is faked).
     */
    private function as(User $user): static
    {
        $token = $this->tokenFor($user);
        $this->app->make('auth')->forgetGuards();

        return $this->withToken($token);
    }

    private function seedTor(StudentProfile $student, string $name = 'tor.pdf'): StudentTorDocument
    {
        $path = 'tor/'.$student->id.'/'.uniqid('', true).'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 fake tor');

        return StudentTorDocument::create([
            'student_id' => $student->id, 'uploaded_by' => $student->user_id, 'original_name' => $name,
            'stored_path' => $path, 'mime_type' => 'application/pdf', 'size_bytes' => 17,
        ]);
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/tor-documents')->assertUnauthorized();
        $this->postJson('/api/v1/tor-documents', [])->assertUnauthorized();
    }

    public function test_a_transferee_uploads_a_tor_and_the_chairs_of_their_college_are_told(): void
    {
        $ccs = $this->makeCurriculum(CollegeCode::Ccs, 'BSCS');
        $student = $this->makeStudent($ccs, StudentType::Transferee);
        $ccsChair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);
        $coeChair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Coe);
        $token = $this->tokenFor($student->user);

        $response = $this->withToken($token)->post('/api/v1/tor-documents', [
            'file' => UploadedFile::fake()->create('my-tor.pdf', 200, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.student_id', $student->id)
            ->assertJsonPath('data.original_name', 'my-tor.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonMissingPath('data.stored_path');

        $document = StudentTorDocument::query()->firstOrFail();
        Storage::disk('local')->assertExists($document->stored_path);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::TOR_DOCUMENT_UPLOADED, 'auditable_id' => $document->id]);
        $this->assertDatabaseHas('notifications', ['user_id' => $ccsChair->id, 'type' => NotificationType::TransfereeCreditRequested->value]);
        $this->assertSame(0, Notification::query()->where('user_id', $coeChair->id)->count());
    }

    public function test_a_returnee_may_upload_but_a_freshman_may_not(): void
    {
        $ccs = $this->makeCurriculum(CollegeCode::Ccs, 'BSCS');
        $returnee = $this->makeStudent($ccs, StudentType::Returnee);
        $freshman = $this->makeStudent($ccs, StudentType::Freshman);
        $file = fn () => UploadedFile::fake()->create('tor.pdf', 50, 'application/pdf');

        $this->as($returnee->user)->post('/api/v1/tor-documents', ['file' => $file()], ['Accept' => 'application/json'])->assertCreated();

        $this->as($freshman->user)->post('/api/v1/tor-documents', ['file' => $file()], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->assertSame(0, StudentTorDocument::query()->where('student_id', $freshman->id)->count());
    }

    public function test_only_pdf_jpg_or_png_up_to_eight_megabytes_is_accepted(): void
    {
        $ccs = $this->makeCurriculum(CollegeCode::Ccs, 'BSCS');
        $student = $this->makeStudent($ccs, StudentType::Transferee);
        $token = $this->tokenFor($student->user);

        $this->withToken($token)->post('/api/v1/tor-documents', ['file' => UploadedFile::fake()->create('tor.exe', 10, 'application/x-msdownload')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->withToken($token)->post('/api/v1/tor-documents', ['file' => UploadedFile::fake()->create('big.pdf', 9000, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->withToken($token)->post('/api/v1/tor-documents', [], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->withToken($token)->post('/api/v1/tor-documents', ['file' => UploadedFile::fake()->create('scan.jpg', 120, 'image/jpeg')], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(1, StudentTorDocument::query()->count());
    }

    public function test_a_student_keeps_at_most_ten_files(): void
    {
        $ccs = $this->makeCurriculum(CollegeCode::Ccs, 'BSCS');
        $student = $this->makeStudent($ccs, StudentType::Transferee);
        for ($i = 0; $i < 10; $i++) {
            $this->seedTor($student, "tor-{$i}.pdf");
        }

        $this->as($student->user)->post('/api/v1/tor-documents', [
            'file' => UploadedFile::fake()->create('one-more.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(10, StudentTorDocument::query()->count());
        $this->assertCount(10, Storage::disk('local')->allFiles('tor'));
    }

    public function test_only_students_can_upload(): void
    {
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->as($chair)->post('/api/v1/tor-documents', [
            'file' => UploadedFile::fake()->create('tor.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_each_role_sees_only_the_tor_documents_it_may(): void
    {
        $ccs = $this->makeCurriculum(CollegeCode::Ccs, 'BSCS');
        $coe = $this->makeCurriculum(CollegeCode::Coe, 'BSED');
        $mine = $this->makeStudent($ccs, StudentType::Transferee);
        $other = $this->makeStudent($ccs, StudentType::Transferee);
        $coeStudent = $this->makeStudent($coe, StudentType::Transferee);
        $this->seedTor($mine);
        $this->seedTor($other);
        $this->seedTor($coeStudent);

        $this->as($mine->user)->getJson('/api/v1/tor-documents')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student_id', $mine->id);

        $this->as($this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs))->getJson('/api/v1/tor-documents')
            ->assertOk()->assertJsonCount(2, 'data');

        $this->as($this->makeUser(UserRole::RegistrarStaff))->getJson('/api/v1/tor-documents')
            ->assertOk()->assertJsonCount(3, 'data');

        $this->as($this->makeUser(UserRole::Faculty))->getJson('/api/v1/tor-documents')->assertForbidden();
    }

    public function test_the_file_is_served_only_to_those_allowed_to_read_it(): void
    {
        $ccs = $this->makeCurriculum(CollegeCode::Ccs, 'BSCS');
        $student = $this->makeStudent($ccs, StudentType::Transferee);
        $document = $this->seedTor($student);
        $url = "/api/v1/tor-documents/{$document->id}/file";

        $owner = $this->as($student->user)->get($url);
        $owner->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('%PDF-1.4', $owner->streamedContent());

        $this->as($this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs))->get($url)->assertOk();
        $this->as($this->makeUser(UserRole::RegistrarStaff))->get($url)->assertOk();

        $this->as($this->makeUser(UserRole::ProgramChair, CollegeCode::Coe))->get($url)->assertForbidden();
        $stranger = $this->makeStudent($ccs, StudentType::Transferee);
        $this->as($stranger->user)->get($url)->assertForbidden();
        $this->as($this->makeUser(UserRole::Faculty))->get($url)->assertForbidden();
    }

    public function test_a_student_can_remove_their_own_tor_but_nobody_else_can(): void
    {
        $ccs = $this->makeCurriculum(CollegeCode::Ccs, 'BSCS');
        $student = $this->makeStudent($ccs, StudentType::Transferee);
        $document = $this->seedTor($student);
        $path = $document->stored_path;
        $stranger = $this->makeStudent($ccs, StudentType::Transferee);

        $this->as($stranger->user)->deleteJson("/api/v1/tor-documents/{$document->id}")->assertForbidden();
        $this->as($this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs))->deleteJson("/api/v1/tor-documents/{$document->id}")->assertForbidden();
        Storage::disk('local')->assertExists($path);

        $this->as($student->user)->deleteJson("/api/v1/tor-documents/{$document->id}")->assertNoContent();

        $this->assertDatabaseMissing('student_tor_documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::TOR_DOCUMENT_DELETED, 'auditable_id' => $document->id]);
    }
}
