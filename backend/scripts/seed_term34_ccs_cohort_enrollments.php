<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Actions\Billing\AssessEnrollment;
use App\Actions\Enrollment\BuildCorSnapshot;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\EnrollmentDocumentType;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use App\Models\EnrollmentDocument;
use App\Models\EnrollmentSubject;
use App\Models\Payment;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

echo "=== Enrolling 40 CCS Test Students for Academic Term 34 (2026-2027 · 1st Semester) ===\n\n";

DB::beginTransaction();

try {
    $term = AcademicTerm::findOrFail(34);
    $cashierUser = User::where('email', 'accounting.seed@grc.test')->firstOrFail();
    $facultyUser = User::where('email', 'faculty.seed@grc.test')->firstOrFail();
    $context = new AuditRequestContext('seed-term34-ccs', null, '127.0.0.1', 'CLI');

    // 1. Assign faculty.seed@grc.test to key sections
    $assignMap = [
        'IT101' => 'ITC',
        'IT201' => 'DBMSYS',
        'IT301' => 'ARTAPP',
        'IT401' => 'SPI',
    ];

    foreach ($assignMap as $sectionCode => $subjectCode) {
        $sec = Section::where('academic_term_id', 34)
            ->where('section_code', $sectionCode)
            ->whereHas('subject', fn($q) => $q->where('code', $subjectCode))
            ->first();

        if ($sec) {
            $sec->update(['professor_id' => $facultyUser->id]);
            echo "Assigned {$facultyUser->name} ({$facultyUser->email}) to Section {$sectionCode} - {$subjectCode} (ID: {$sec->id})\n";
        }
    }

    // 2. Pre-fetch sections
    $secIT101 = Section::where('academic_term_id', 34)->where('section_code', 'IT101')->with('subject')->get();
    $secIT102 = Section::where('academic_term_id', 34)->where('section_code', 'IT102')->with('subject')->get();
    $secIT201 = Section::where('academic_term_id', 34)->where('section_code', 'IT201')->with('subject')->get();
    $secIT202 = Section::where('academic_term_id', 34)->where('section_code', 'IT202')->with('subject')->get();
    $secIT301 = Section::where('academic_term_id', 34)->where('section_code', 'IT301')->with('subject')->get();
    $secIT305 = Section::where('academic_term_id', 34)->where('section_code', 'IT305')->with('subject')->get();
    $secIT401 = Section::where('academic_term_id', 34)->where('section_code', 'IT401')->with('subject')->get();
    $secIT402 = Section::where('academic_term_id', 34)->where('section_code', 'IT402')->with('subject')->get();

    // Specific irregular subsets
    $irregY1Codes = ['ITC', 'ITCL', 'ITP1', 'ITP1L', 'KOMFIL', 'MATHWRLD', 'NSTP 1', 'UNDSELF'];
    $secIrregY1 = $secIT102->filter(fn($s) => in_array($s->subject->code, $irregY1Codes));

    $irregY2Codes = ['AVE', 'CPROG2', 'DBMSYS', 'ENVISCI', 'IPT1', 'NW1', 'WST'];
    $secIrregY2 = $secIT202->filter(fn($s) => in_array($s->subject->code, $irregY2Codes));

    $irregY3Codes = ['ARTAPP', 'BMC', 'CAO', 'DMATH', 'PRELEC2', 'PT', 'SIA2'];
    $secIrregY3 = $secIT305->filter(fn($s) => in_array($s->subject->code, $irregY3Codes));

    $irregY4Codes = ['BUSANA', 'CAPS2', 'IAS2', 'SPI', 'IT-ELEC1'];
    $secIrregY4 = $secIT402->filter(fn($s) => in_array($s->subject->code, $irregY4Codes));

    $studentConfig = [
        ['pattern' => 'TEST-REG-Y1-%', 'sections' => $secIT101, 'category' => 'regular', 'year' => 1],
        ['pattern' => 'TEST-IRREG-Y1-%', 'sections' => $secIrregY1, 'category' => 'irregular', 'year' => 1],
        ['pattern' => 'TEST-REG-Y2-%', 'sections' => $secIT201, 'category' => 'regular', 'year' => 2],
        ['pattern' => 'TEST-IRREG-Y2-%', 'sections' => $secIrregY2, 'category' => 'irregular', 'year' => 2],
        ['pattern' => 'TEST-REG-Y3-%', 'sections' => $secIT301, 'category' => 'regular', 'year' => 3],
        ['pattern' => 'TEST-IRREG-Y3-%', 'sections' => $secIrregY3, 'category' => 'irregular', 'year' => 3],
        ['pattern' => 'TEST-REG-Y4-%', 'sections' => $secIT401, 'category' => 'regular', 'year' => 4],
        ['pattern' => 'TEST-IRREG-Y4-%', 'sections' => $secIrregY4, 'category' => 'irregular', 'year' => 4],
    ];

    $assessEnrollment = app(AssessEnrollment::class);
    $buildCorSnapshot = app(BuildCorSnapshot::class);
    $auditRecorder = app(AuditRecorder::class);

    $enrolledCount = 0;
    $now = now();

    foreach ($studentConfig as $cfg) {
        $students = StudentProfile::where('student_number', 'LIKE', $cfg['pattern'])
            ->with('user', 'program')
            ->orderBy('id')
            ->get();

        $sectionsToEnroll = $cfg['sections'];
        $totalUnits = (float) $sectionsToEnroll->sum(fn($s) => $s->subject->units);

        echo "\nProcessing {$cfg['pattern']} ({$students->count()} students, {$sectionsToEnroll->count()} sections, {$totalUnits} units)...\n";

        foreach ($students as $student) {
            // Ensure student profile category and year level are set
            $student->update([
                'enrollment_category' => $cfg['category'],
                'year_level' => $cfg['year'],
            ]);

            // Clean up any existing enrollment in term 34 for idempotency
            $existing = Enrollment::where('academic_term_id', 34)->where('student_id', $student->id)->get();
            foreach ($existing as $ex) {
                Payment::where('enrollment_id', $ex->id)->delete();
                EnrollmentDocument::where('enrollment_id', $ex->id)->delete();
                EnrollmentSubject::where('enrollment_id', $ex->id)->delete();
                DB::table('assessments')->where('enrollment_id', $ex->id)->delete();
                $ex->delete();
            }

            // Create enrollment
            $enrollment = Enrollment::create([
                'student_id' => $student->id,
                'academic_term_id' => 34,
                'status' => EnrollmentStatus::Enrolled,
                'total_units' => $totalUnits,
                'requires_overload_approval' => false,
                'submitted_at' => $now,
                'registrar_decided_at' => $now,
                'payment_confirmed_at' => $now,
                'enrolled_at' => $now,
                'active_academic_term_id' => 34,
            ]);

            // Create enrollment subjects
            foreach ($sectionsToEnroll as $sec) {
                EnrollmentSubject::create([
                    'enrollment_id' => $enrollment->id,
                    'section_id' => $sec->id,
                    'status' => EnrollmentSubjectStatus::Enrolled,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Create assessment
            $assessment = $assessEnrollment->execute($enrollment);

            // Create payment
            $payment = Payment::create([
                'enrollment_id' => $enrollment->id,
                'confirmed_by' => $cashierUser->id,
                'external_reference' => 'CASH-' . strtoupper(Str::random(8)),
                'amount' => $assessment->total_amount,
                'promissory_note_on_file' => false,
                'confirmed_at' => $now,
            ]);

            $payment->setRelation('confirmer', $cashierUser);

            // Create official COR Document
            $freshEnrollment = $enrollment->fresh([
                'student.user',
                'student.program',
                'academicTerm',
                'enrollmentSubjects.section.subject',
                'assessment.items',
            ]);

            $corSnapshot = $buildCorSnapshot->execute($freshEnrollment, $payment);

            EnrollmentDocument::create([
                'enrollment_id' => $enrollment->id,
                'document_type' => EnrollmentDocumentType::Cor,
                'document_number' => sprintf('COR%06d', $enrollment->id),
                'storage_path' => null,
                'content_hash' => hash('sha256', json_encode($corSnapshot)),
                'metadata' => $corSnapshot,
                'issued_at' => $now,
            ]);

            $enrolledCount++;
            echo "  ✓ Enrolled {$student->student_number} ({$student->user->name}) -> Enrollment #{$enrollment->id} (COR" . sprintf('%06d', $enrollment->id) . ", ₱" . number_format((float)$assessment->total_amount, 2) . ")\n";
        }
    }

    // 3. Recompute enrolled_count on sections
    DB::statement("
        UPDATE sections s
        SET enrolled_count = (
            SELECT COUNT(*)
            FROM enrollment_subjects es
            JOIN enrollments e ON e.id = es.enrollment_id
            WHERE es.section_id = s.id
              AND e.status = 'enrolled'
        )
        WHERE s.academic_term_id = 34
    ");

    DB::commit();
    echo "\n=== ALL 40 CCS TEST STUDENTS SUCCESSFULLY ENROLLED IN TERM 34 ===\n";
    echo "Total Enrolled: {$enrolledCount} students.\n";

} catch (\Throwable $e) {
    DB::rollBack();
    echo "\n[ERROR] Enrollment script failed: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

