<?php

namespace Tests\Feature\Api\V1;

use App\Actions\Enrollment\BuildCorSnapshot;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\EnrollmentDocumentType;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\AccountPayment;
use App\Models\Assessment;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\EnrollmentDocument;
use App\Models\Payment;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EnrollmentDocumentsEndpointTest extends TestCase
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

    private function makeStudent(Curriculum $curriculum, string $email, string $studentNumber, string $name = 'Test Student'): StudentProfile
    {
        $user = User::create([
            'name' => $name, 'email' => $email,
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => $studentNumber,
            'program_id' => $curriculum->program_id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
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
        User::create([
            'name' => 'Test '.$role->value, 'email' => $email,
            'password' => self::PASSWORD, 'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function makeDocument(StudentProfile $student, AcademicTerm $term): EnrollmentDocument
    {
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::Enrolled,
            'total_units' => 3,
            'submitted_at' => now(),
        ]);

        return EnrollmentDocument::create([
            'enrollment_id' => $enrollment->id,
            'document_type' => EnrollmentDocumentType::Cor,
            'document_number' => sprintf('COR%06d', $enrollment->id),
            'generated_at' => now(),
        ]);
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/enrollment-documents')->assertUnauthorized();
    }

    public function test_a_non_listed_role_cannot_view_enrollment_documents(): void
    {
        $token = $this->tokenForNewUser(UserRole::Faculty, 'faculty.docforbidden@grc.test');

        $this->withToken($token)->getJson('/api/v1/enrollment-documents')->assertForbidden();
    }

    public function test_a_student_sees_only_their_own_enrollment_documents(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.owndoc@grc.test', '2026-0001');
        $ownDocument = $this->makeDocument($student, $term);

        $otherStudent = $this->makeStudent($curriculum, 'other.student.doc@grc.test', '2026-0002');
        $this->makeDocument($otherStudent, $term);

        $token = $this->tokenFor($student->user);

        $response = $this->withToken($token)->getJson('/api/v1/enrollment-documents');

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $ownDocument->id);
        $response->assertJsonPath('data.0.student_number', $student->student_number);
        $response->assertJsonPath('data.0.student_name', null);
    }

    public function test_a_student_cannot_open_another_students_cor_detail(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.owncordetail@grc.test', '2026-0011');
        $otherStudent = $this->makeStudent($curriculum, 'student.othercordetail@grc.test', '2026-0012');
        $otherDocument = $this->makeDocument($otherStudent, $term);

        $this->withToken($this->tokenFor($student->user))
            ->getJson("/api/v1/enrollment-documents/{$otherDocument->id}")
            ->assertForbidden();
    }

    public function test_a_legacy_com_row_is_presented_to_its_student_as_a_cor(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.legacycor@grc.test', '2026-0013');
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::Enrolled,
            'total_units' => 3,
            'submitted_at' => now(),
        ]);
        DB::table('enrollment_documents')->insert([
            'enrollment_id' => $enrollment->id,
            'document_type' => 'com',
            'document_number' => 'COM000013',
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($this->tokenFor($student->user))
            ->getJson('/api/v1/enrollment-documents')
            ->assertOk()
            ->assertJsonPath('data.0.document_type', 'cor')
            ->assertJsonPath('data.0.document_type_label', 'Certificate of Registration')
            ->assertJsonPath('data.0.document_number', 'COR000013');
    }

    public function test_a_paid_legacy_com_row_opens_as_a_printable_cor_snapshot(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.legacysnapshot@grc.test', '2026-0014');
        $cashier = User::create([
            'name' => 'Test Cashier',
            'email' => 'cashier.legacysnapshot@grc.test',
            'password' => self::PASSWORD,
            'role' => UserRole::AccountingStaff,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::Enrolled,
            'total_units' => 3,
            'submitted_at' => now(),
        ]);
        $payment = Payment::create([
            'enrollment_id' => $enrollment->id,
            'confirmed_by' => $cashier->id,
            'amount' => '1500.00',
            'confirmed_at' => now(),
        ]);
        DB::table('enrollment_documents')->insert([
            'enrollment_id' => $enrollment->id,
            'document_type' => 'com',
            'document_number' => 'COM000014',
            'generated_at' => $payment->confirmed_at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $document = EnrollmentDocument::query()->where('document_number', 'COM000014')->sole();

        $this->withToken($this->tokenFor($student->user))
            ->getJson("/api/v1/enrollment-documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('data.type', 'certificate_of_registration')
            ->assertJsonPath('data.document_number', 'COR000014')
            ->assertJsonPath('data.snapshot.document_title', 'Certificate of Registration')
            ->assertJsonPath('data.snapshot.student.student_number', '2026-0014')
            ->assertJsonPath('data.snapshot.signatories.cashier', 'Test Cashier');
    }

    public function test_an_enrolled_legacy_record_without_an_imported_payment_still_opens_as_a_cor(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.importedcor@grc.test', '2026-0015');
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::Enrolled,
            'total_units' => 3,
            'submitted_at' => now(),
            'enrolled_at' => now(),
        ]);
        DB::table('enrollment_documents')->insert([
            'enrollment_id' => $enrollment->id,
            'document_type' => 'com',
            'document_number' => 'COM000015',
            'generated_at' => $enrollment->enrolled_at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $document = EnrollmentDocument::query()->where('document_number', 'COM000015')->sole();

        $this->withToken($this->tokenFor($student->user))
            ->getJson("/api/v1/enrollment-documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('data.document_number', 'COR000015')
            ->assertJsonPath('data.snapshot.document_title', 'Certificate of Registration')
            ->assertJsonPath('data.snapshot.fees.payment_amount', '0.00')
            ->assertJsonPath('data.snapshot.signatories.cashier', 'Not provided');
    }

    public function test_a_registrar_head_sees_every_enrollment_document(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $studentA = $this->makeStudent($curriculum, 'student.a.doc@grc.test', '2026-0003');
        $this->makeDocument($studentA, $term);
        $studentB = $this->makeStudent($curriculum, 'student.b.doc@grc.test', '2026-0004');
        $this->makeDocument($studentB, $term);

        $registrarToken = $this->tokenForNewUser(UserRole::RegistrarHead, 'registrar.docview@grc.test');

        $response = $this->withToken($registrarToken)->getJson('/api/v1/enrollment-documents');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    /**
     * Phase 7b Task 3: Registrar Staff gets the same broad read access the
     * Registrar Head already has (PRD §3.8 "view permitted ... enrollment
     * documents") — mirrors the test directly above.
     */
    public function test_a_registrar_staff_sees_every_enrollment_document(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $studentA = $this->makeStudent($curriculum, 'student.a.staffdoc@grc.test', '2026-0005');
        $this->makeDocument($studentA, $term);
        $studentB = $this->makeStudent($curriculum, 'student.b.staffdoc@grc.test', '2026-0006');
        $this->makeDocument($studentB, $term);

        $registrarStaffToken = $this->tokenForNewUser(UserRole::RegistrarStaff, 'registrar-staff.docview@grc.test');

        $response = $this->withToken($registrarStaffToken)->getJson('/api/v1/enrollment-documents');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_accounting_staff_sees_prior_certificates_of_registration(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.cashiercor@grc.test', '2026-0007');
        $document = $this->makeDocument($student, $term);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'cashier.cor@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/enrollment-documents');

        $response->assertOk()->assertJsonPath('data.0.id', $document->id);
        $response->assertJsonPath('data.0.document_type', 'cor');

        $this->withToken($token)
            ->getJson("/api/v1/enrollment-documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('data.type', 'certificate_of_registration')
            ->assertJsonPath('data.document_number', $document->document_number);
    }

    public function test_accounting_staff_can_find_a_students_prior_cor_by_student_number(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $matchingStudent = $this->makeStudent($curriculum, 'student.matchingcor@grc.test', '2026-0101');
        $matchingDocument = $this->makeDocument($matchingStudent, $term);
        $otherStudent = $this->makeStudent($curriculum, 'student.othercor@grc.test', '2026-0102');
        $this->makeDocument($otherStudent, $term);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'cashier.searchcor@grc.test');

        $this->withToken($token)
            ->getJson('/api/v1/enrollment-documents?student_number=2026-0101')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matchingDocument->id)
            ->assertJsonPath('data.0.student_number', '2026-0101');
    }

    public function test_accounting_staff_and_registrar_head_can_find_cors_by_student_name(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $matchingStudent = $this->makeStudent(
            $curriculum,
            'student.aurora.cor@grc.test',
            '2026-0103',
            'Aurora S. Lopez',
        );
        $matchingDocument = $this->makeDocument($matchingStudent, $term);
        $otherStudent = $this->makeStudent(
            $curriculum,
            'student.other-name.cor@grc.test',
            '2026-0104',
            'Ramon Santos',
        );
        $this->makeDocument($otherStudent, $term);

        foreach ([
            [UserRole::AccountingStaff, 'cashier.name-search@grc.test'],
            [UserRole::RegistrarHead, 'registrar.name-search@grc.test'],
        ] as [$role, $email]) {
            $this->withToken($this->tokenForNewUser($role, $email))
                ->getJson('/api/v1/enrollment-documents?student_name=aurora')
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $matchingDocument->id)
                ->assertJsonPath('data.0.student_number', '2026-0103')
                ->assertJsonPath('data.0.student_name', 'Aurora S. Lopez');
        }
    }

    public function test_a_student_can_download_their_own_cor_pdf(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.pdfdownload@grc.test', '2026-0201');
        $document = $this->makeDocument($student, $term);

        $response = $this->withToken($this->tokenFor($student->user))
            ->get("/api/v1/enrollment-documents/{$document->id}/pdf");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('filename="COR_', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_the_downloaded_cor_pdf_filename_carries_the_students_name_and_date(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.pdffilename@grc.test', '2026-0208');
        $document = $this->makeDocument($student, $term);
        $document->forceFill(['generated_at' => '2026-10-01 03:29:28'])->save();

        $response = $this->withToken($this->tokenFor($student->user))
            ->get("/api/v1/enrollment-documents/{$document->id}/pdf");

        $response->assertOk();
        $expectedSlug = preg_replace('/[^A-Za-z0-9]+/', '_', $student->user->name);
        $this->assertStringContainsString(
            "filename=\"COR_{$expectedSlug}_2026-10-01.pdf\"",
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_the_cor_pdf_view_uses_the_approved_labels_and_the_ordinal_year_level(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.pdflabels@grc.test', '2026-0204');
        $document = $this->makeDocument($student, $term);

        $snapshot = $this->withToken($this->tokenFor($student->user))
            ->getJson("/api/v1/enrollment-documents/{$document->id}")
            ->assertOk()
            ->json('data.snapshot');

        // The stored wording stays "Year 1"; only the printed COR is reworded.
        $this->assertSame('Year 1', $snapshot['student']['level']);

        $html = view('pdf.certificate-of-registration', [
            'document' => $document,
            'snapshot' => $snapshot,
        ])->render();

        foreach (['Name:', 'Degree:', 'Academic Year:', 'Year Level:', '1st Year'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        foreach (['Course:', 'School Year:', 'Year 1'] as $retired) {
            $this->assertStringNotContainsString($retired, $html);
        }
    }

    public function test_the_cor_pdf_uses_a_font_that_can_print_the_peso_sign(): void
    {
        // The base-14 PDF fonts (Helvetica/Arial) have no glyph for \u20b1 and DomPDF prints "?" in
        // its place; DejaVu Sans (bundled with DomPDF) does. Regression guard for that swap.
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.pdfpeso@grc.test', '2026-0206');
        $document = $this->makeDocument($student, $term);
        $snapshot = $this->withToken($this->tokenFor($student->user))
            ->getJson("/api/v1/enrollment-documents/{$document->id}")
            ->assertOk()
            ->json('data.snapshot');

        $html = view('pdf.certificate-of-registration', ['document' => $document, 'snapshot' => $snapshot])->render();

        $this->assertStringContainsString("font-family: 'DejaVu Sans', sans-serif;", $html);
    }

    public function test_the_printed_cor_lists_the_fees_but_not_what_was_paid_or_the_balance(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.pdfbill@grc.test', '2026-0205');
        $document = $this->makeDocument($student, $term);

        $snapshot = $this->withToken($this->tokenFor($student->user))
            ->getJson("/api/v1/enrollment-documents/{$document->id}")
            ->assertOk()
            ->json('data.snapshot');
        // A snapshot that does carry payment figures must still not print them.
        $snapshot['fees']['amount_paid'] = '7777.77';
        $snapshot['fees']['remaining_balance'] = '3333.33';

        $html = view('pdf.certificate-of-registration', ['document' => $document, 'snapshot' => $snapshot])->render();

        // The COR is the bill: the list of fees and the grand total are on it (the same lines the
        // Accounting fee settings produce). What was paid and what is still owed is not; that
        // lives in the Statement of Account.
        $this->assertStringContainsString('Assessment of Fees', $html);
        $this->assertStringContainsString('Total Tuition Fees', $html);
        $this->assertStringContainsString('Total Other Fees', $html);
        $this->assertStringContainsString('GRAND TOTAL', $html);
        $this->assertStringNotContainsString('AMOUNT PAID', $html);
        $this->assertStringNotContainsString('REMAINING BALANCE', $html);
        $this->assertStringNotContainsString('7,777.77', $html);
        $this->assertStringNotContainsString('3,333.33', $html);
    }

    public function test_cashier_student_and_registrar_sign_in_one_flat_row(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.pdfsignatures@grc.test', '2026-0207');
        $document = $this->makeDocument($student, $term);
        $snapshot = $this->withToken($this->tokenFor($student->user))
            ->getJson("/api/v1/enrollment-documents/{$document->id}")
            ->assertOk()
            ->json('data.snapshot');

        $html = view('pdf.certificate-of-registration', ['document' => $document, 'snapshot' => $snapshot])->render();

        // Matches the on-screen/browser-print COR exactly: Cashier, Student, and
        // Registrar sign level with each other in a single row, not a stacked
        // "student above, Cashier/Registrar below" layout (stakeholder Doc 16).
        $this->assertStringNotContainsString('signature-student', $html);
        $signatureRow = strpos($html, 'class="signature-table ');
        self::assertNotFalse($signatureRow);
        $cashierPosition = strpos($html, 'Cashier</div>');
        $studentPosition = strpos($html, "Student's Signature Over Printed Name</div>");
        $registrarPosition = strpos($html, 'Registrar</div>');
        self::assertNotFalse($cashierPosition);
        self::assertNotFalse($studentPosition);
        self::assertNotFalse($registrarPosition);
        self::assertGreaterThan($signatureRow, $cashierPosition);
        self::assertGreaterThan($cashierPosition, $studentPosition, 'Cashier must come before the Student in the signature row.');
        self::assertGreaterThan($studentPosition, $registrarPosition, 'Student must come before the Registrar in the signature row.');
    }

    public function test_a_student_cannot_download_another_students_cor_pdf(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $studentA = $this->makeStudent($curriculum, 'student.pdfa@grc.test', '2026-0202');
        $studentB = $this->makeStudent($curriculum, 'student.pdfb@grc.test', '2026-0203');
        $documentB = $this->makeDocument($studentB, $term);

        $this->withToken($this->tokenFor($studentA->user))
            ->get("/api/v1/enrollment-documents/{$documentB->id}/pdf")
            ->assertForbidden();
    }

    public function test_accounting_staff_can_download_any_students_cor_pdf(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.cashierpdf@grc.test', '2026-0204');
        $document = $this->makeDocument($student, $term);
        $cashierToken = $this->tokenForNewUser(UserRole::AccountingStaff, 'cashier.downloadpdf@grc.test');

        $response = $this->withToken($cashierToken)
            ->get("/api/v1/enrollment-documents/{$document->id}/pdf");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    public function test_registrar_staff_can_search_enrollment_documents_by_multi_field_and_number(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $studentA = $this->makeStudent($curriculum, 'student.search_a@grc.test', '2026-9001');
        $studentA->user->update(['name' => 'Searchable Alpha']);
        $studentB = $this->makeStudent($curriculum, 'student.search_b@grc.test', '2026-9002');
        $studentB->user->update(['name' => 'Other Beta']);

        $docA = $this->makeDocument($studentA, $term);
        $docB = $this->makeDocument($studentB, $term);

        $registrarToken = $this->tokenForNewUser(UserRole::RegistrarStaff, 'registrar.searchtest@grc.test');

        // Search by document number
        $this->withToken($registrarToken)
            ->getJson("/api/v1/enrollment-documents?document_number={$docA->document_number}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $docA->id);

        // Multi-field search by student number
        $this->withToken($registrarToken)
            ->getJson('/api/v1/enrollment-documents?search=2026-9001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $docA->id);

        // Multi-field search by student name
        $this->withToken($registrarToken)
            ->getJson('/api/v1/enrollment-documents?search=Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $docA->id);
    }

    public function test_cor_snapshot_reflects_additional_account_payments(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.additionalpay@grc.test', '2026-9003');

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::Enrolled,
            'total_units' => 3,
            'submitted_at' => now(),
            'enrolled_at' => now(),
        ]);

        Assessment::create([
            'enrollment_id' => $enrollment->id,
            'total_amount' => '5000.00',
            'currency' => 'PHP',
        ]);

        Payment::create([
            'enrollment_id' => $enrollment->id,
            'confirmed_by' => $student->user->id,
            'amount' => '1500.00',
            'confirmed_at' => now(),
        ]);

        $document = EnrollmentDocument::create([
            'enrollment_id' => $enrollment->id,
            'document_type' => EnrollmentDocumentType::Cor,
            'document_number' => sprintf('COR%06d', $enrollment->id),
            'generated_at' => now(),
            'snapshot' => app(BuildCorSnapshot::class)->execute($enrollment, $enrollment->payment),
        ]);

        // Prior to additional payment, remaining balance is 3500.00
        $this->withToken($this->tokenFor($student->user))
            ->getJson("/api/v1/enrollment-documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('data.snapshot.fees.amount_paid', '1500.00')
            ->assertJsonPath('data.snapshot.fees.remaining_balance', '3500.00');

        // Cashier records an additional account payment of 1000.00
        AccountPayment::create([
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'received_by' => $student->user->id,
            'amount' => '1000.00',
            'received_at' => now(),
        ]);

        // Re-fetching COR now dynamically reflects total paid of 2500.00 and balance 2500.00
        $this->withToken($this->tokenFor($student->user))
            ->getJson("/api/v1/enrollment-documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('data.snapshot.fees.initial_payment', '1500.00')
            ->assertJsonPath('data.snapshot.fees.additional_payments', '1000.00')
            ->assertJsonPath('data.snapshot.fees.amount_paid', '2500.00')
            ->assertJsonPath('data.snapshot.fees.remaining_balance', '2500.00');
    }

    public function test_a_cor_prints_the_platform_the_registrar_set_for_the_term(): void
    {
        $term = $this->makeTerm();
        $term->update(['enrollment_platform' => 'face_to_face']);
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.platform@grc.test', '2026-0301');
        $document = $this->makeDocument($student, $term);

        $snapshot = $this->withToken($this->tokenFor($student->user))
            ->getJson("/api/v1/enrollment-documents/{$document->id}")
            ->assertOk()
            ->json('data.snapshot');

        $this->assertSame('Face-to-Face', $snapshot['student']['platform']);
    }

    public function test_a_cor_prints_both_when_the_registrar_set_both_platforms(): void
    {
        $term = $this->makeTerm();
        $term->update(['enrollment_platform' => 'both']);
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.bothplatform@grc.test', '2026-0304');
        $document = $this->makeDocument($student, $term);

        $snapshot = $this->withToken($this->tokenFor($student->user))
            ->getJson("/api/v1/enrollment-documents/{$document->id}")
            ->assertOk()
            ->json('data.snapshot');

        $this->assertSame('Both (Face-to-Face and Online)', $snapshot['student']['platform']);
    }

    public function test_a_cor_still_says_not_provided_while_no_platform_is_set(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.noplatform@grc.test', '2026-0302');
        $document = $this->makeDocument($student, $term);

        $snapshot = $this->withToken($this->tokenFor($student->user))
            ->getJson("/api/v1/enrollment-documents/{$document->id}")
            ->assertOk()
            ->json('data.snapshot');

        $this->assertSame('Not provided', $snapshot['student']['platform']);
    }

    public function test_re_syncing_a_cor_after_the_platform_changes_never_moves_its_issue_time(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.resync@grc.test', '2026-0303');
        $document = $this->makeDocument($student, $term);
        $issuedAt = '2026-01-05 03:04:05';
        // Naming the column keeps MariaDB's implicit ON UPDATE from touching it.
        DB::table('enrollment_documents')->where('id', $document->id)->update(['generated_at' => $issuedAt]);
        $token = $this->tokenFor($student->user);

        $this->withToken($token)->getJson("/api/v1/enrollment-documents/{$document->id}")->assertOk();
        $hashBefore = DB::table('enrollment_documents')->where('id', $document->id)->value('content_hash');
        $this->assertSame($issuedAt, DB::table('enrollment_documents')->where('id', $document->id)->value('generated_at'));

        // The Registrar sets the platform after the COR exists: the next view
        // re-syncs the stored snapshot, but the "Generated" time must not move.
        $term->update(['enrollment_platform' => 'online']);
        $snapshot = $this->withToken($token)->getJson("/api/v1/enrollment-documents/{$document->id}")
            ->assertOk()
            ->json('data.snapshot');

        $this->assertSame('Online', $snapshot['student']['platform']);
        $this->assertNotSame($hashBefore, DB::table('enrollment_documents')->where('id', $document->id)->value('content_hash'));
        $this->assertSame($issuedAt, DB::table('enrollment_documents')->where('id', $document->id)->value('generated_at'));
        $stored = json_decode((string) DB::table('enrollment_documents')->where('id', $document->id)->value('snapshot'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Online', $stored['student']['platform']);
    }
}
