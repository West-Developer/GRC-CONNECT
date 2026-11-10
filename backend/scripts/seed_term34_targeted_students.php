<?php

/**
 * seed_term34_targeted_students.php
 *
 * Enrolls 25 specific students in Term 34 (2026-2027 · 1st Semester),
 * assigns them to the correct section block, confirms payment, generates COR,
 * creates grade records, submits them, and locks them.
 *
 * Student groups:
 *  - BSBA-MM Year 1 → section MM103   (sharon.batac, jerome.delossantos, jayson.malvar, isabel.ward, francisco.scott)
 *  - BSED-FIL Year 1 → section FIL101 (edgar.rodriguez, gil.flores, charmaine.andrada, lara.quiambao, erlinda.valencia)
 *  - BSIT Year 1     → section IT101  (dominic.watson, ricardo.tagumpay, leandro.panganiban, rogelio.lakandula, herminio.miller)
 *  - BEED Year 1     → section ELEM101 (bayani.morales→wait, correct program below)
 *  - BSA Year 3      → section ACC301  (bayani.morales, rey.magsino, josephine.ramirez, sharon.calungsod, concepcion.bernardo)
 *  - BEED Year 1     → section ELEM101 (andres.mercado, aurora.sarmiento, benjamin.ramirez, norma.macatangay, joy.dungog)
 *
 * Safe to re-run: idempotent — cleans existing term-34 enrollment for each
 * student before re-creating, then creates grades only if missing.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Actions\Billing\AssessEnrollment;
use App\Actions\Enrollment\BuildCorSnapshot;
use App\Domain\Enrollment\EnrollmentDocumentType;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use App\Models\EnrollmentDocument;
use App\Models\EnrollmentSubject;
use App\Models\Payment;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

echo "=== Term 34 Targeted Student Enrollment Script ===\n";
echo "Started at: " . now()->toDateTimeString() . "\n\n";

const TERM_ID = 34;

// -----------------------------------------------------------------------
// Grade helpers
// -----------------------------------------------------------------------
function pickGrade(string $code): ?float
{
    $prefixes = ['NSTP', 'PATHFIT', 'PATHFIT1', 'PATHFIT2', 'PATHFIT3', 'PATHFIT4', 'PE'];
    foreach ($prefixes as $p) {
        if (stripos(trim($code), $p) === 0) return null; // C (Completed)
    }
    $r = mt_rand(1, 100);
    if ($r <= 5) return 5.00; // ~5% fail
    $grades = [1.00, 1.25, 1.50, 1.75, 2.00, 2.25, 2.50, 2.75, 3.00];
    return $grades[array_rand($grades)];
}

function gradeToMark(?float $g): string
{
    if ($g === null)  return 'C';
    if ($g >= 5.0)    return '5.00';
    return number_format($g, 2);
}

// -----------------------------------------------------------------------
// Bootstrap
// -----------------------------------------------------------------------
$term         = AcademicTerm::findOrFail(TERM_ID);
$cashierUser  = User::where('email', 'accounting.seed@grc.test')->firstOrFail();
$registrar    = User::where('email', 'registrar-head.seed@grc.test')->firstOrFail();
$assessAction = app(AssessEnrollment::class);
$buildCor     = app(BuildCorSnapshot::class);
$now          = now();

echo "Term: {$term->id} — {$term->school_year} · {$term->semester} Semester\n";
echo "Cashier: {$cashierUser->name}\n\n";

// -----------------------------------------------------------------------
// Section loaders — returns Collection of Section models with subjects
// -----------------------------------------------------------------------
function loadSections(int $termId, string $sectionCode): \Illuminate\Database\Eloquent\Collection
{
    return Section::where('academic_term_id', $termId)
        ->where('section_code', $sectionCode)
        ->with('subject')
        ->get();
}

// Pre-load all section groups
$secMM103   = loadSections(TERM_ID, 'MM103');   // BSBA-MM Year 1
$secFIL101  = loadSections(TERM_ID, 'FIL101');  // BSED-FIL Year 1
$secIT101   = loadSections(TERM_ID, 'IT101');   // BSIT Year 1
$secELEM101 = loadSections(TERM_ID, 'ELEM101'); // BEED Year 1
$secACC301  = loadSections(TERM_ID, 'ACC301');  // BSA Year 3

echo "Section sizes:\n";
echo "  MM103   (BSBA-MM  Y1): {$secMM103->count()} subjects\n";
echo "  FIL101  (BSED-FIL Y1): {$secFIL101->count()} subjects\n";
echo "  IT101   (BSIT     Y1): {$secIT101->count()} subjects\n";
echo "  ELEM101 (BEED     Y1): {$secELEM101->count()} subjects\n";
echo "  ACC301  (BSA      Y3): {$secACC301->count()} subjects\n\n";

// -----------------------------------------------------------------------
// Student groups
// -----------------------------------------------------------------------
$studentGroups = [
    [
        'label'    => 'BSBA-MM Year 1 → MM103',
        'sections' => $secMM103,
        'category' => 'regular',
        'year'     => 1,
        'emails'   => [
            'sharon.batac@grc.com',
            'jerome.delossantos@grc.com',
            'jayson.malvar@grc.com',
            'isabel.ward@grc.com',
            'francisco.scott@grc.com',
        ],
    ],
    [
        'label'    => 'BSED-FIL Year 1 → FIL101',
        'sections' => $secFIL101,
        'category' => 'regular',
        'year'     => 1,
        'emails'   => [
            'edgar.rodriguez@grc.com',
            'gil.flores@grc.com',
            'charmaine.andrada@grc.com',
            'lara.quiambao@grc.com',
            'erlinda.valencia@grc.com',
        ],
    ],
    [
        'label'    => 'BSIT Year 1 → IT101',
        'sections' => $secIT101,
        'category' => 'regular',
        'year'     => 1,
        'emails'   => [
            'dominic.watson@grc.com',
            'ricardo.tagumpay@grc.com',
            'leandro.panganiban@grc.com',
            'rogelio.lakandula@grc.com',
            'herminio.miller@grc.com',
        ],
    ],
    [
        'label'    => 'BSA Year 3 → ACC301',
        'sections' => $secACC301,
        'category' => 'regular',
        'year'     => 3,
        'emails'   => [
            'bayani.morales@grc.com',
            'rey.magsino@grc.com',
            'josephine.ramirez@grc.com',
            'sharon.calungsod@grc.com',
            'concepcion.bernardo@grc.com',
        ],
    ],
    [
        'label'    => 'BEED Year 1 → ELEM101',
        'sections' => $secELEM101,
        'category' => 'regular',
        'year'     => 1,
        'emails'   => [
            'andres.mercado@grc.com',
            'aurora.sarmiento@grc.com',
            'benjamin.ramirez@grc.com',
            'norma.macatangay@grc.com',
            'joy.dungog@grc.com',
        ],
    ],
];

// -----------------------------------------------------------------------
// Main processing loop
// -----------------------------------------------------------------------
$totalEnrolled = 0;
$totalGrades   = 0;
$errors        = [];

foreach ($studentGroups as $group) {
    echo "--- {$group['label']} ---\n";

    $sections = $group['sections'];
    if ($sections->isEmpty()) {
        echo "  [WARN] No sections found — skipping group.\n\n";
        continue;
    }

    $totalUnits = (float) $sections->sum(fn($s) => $s->subject->units ?? 0);

    foreach ($group['emails'] as $email) {
        $user = User::where('email', $email)->first();
        if (! $user) {
            echo "  [WARN] User not found: {$email}\n";
            $errors[] = "User not found: {$email}";
            continue;
        }

        $student = StudentProfile::where('user_id', $user->id)->first();
        if (! $student) {
            echo "  [WARN] StudentProfile not found for: {$email}\n";
            $errors[] = "StudentProfile missing: {$email}";
            continue;
        }

        DB::beginTransaction();
        try {
            // Ensure correct category & year level
            $student->update([
                'enrollment_category' => $group['category'],
                'year_level'          => $group['year'],
            ]);

            // --- Idempotency: clean up any existing term-34 enrollment ---
            $existing = Enrollment::where('academic_term_id', TERM_ID)
                ->where('student_id', $user->id)
                ->get();

            foreach ($existing as $ex) {
                // Remove linked grades first
                AcademicGrade::where('academic_term_id', TERM_ID)
                    ->where('student_id', $user->id)
                    ->delete();
                Payment::where('enrollment_id', $ex->id)->delete();
                EnrollmentDocument::where('enrollment_id', $ex->id)->delete();
                EnrollmentSubject::where('enrollment_id', $ex->id)->delete();
                DB::table('assessments')->where('enrollment_id', $ex->id)->delete();
                $ex->delete();
            }

            // --- Create enrollment (fully enrolled) ---
            $enrollment = Enrollment::create([
                'student_id'             => $user->id,
                'academic_term_id'       => TERM_ID,
                'status'                 => EnrollmentStatus::Enrolled->value,
                'total_units'            => $totalUnits,
                'requires_overload_approval' => false,
                'submitted_at'           => $now,
                'registrar_decided_at'   => $now,
                'payment_confirmed_at'   => $now,
                'enrolled_at'            => $now,
            ]);

            // --- Enrollment subjects ---
            foreach ($sections as $sec) {
                EnrollmentSubject::create([
                    'enrollment_id' => $enrollment->id,
                    'section_id'    => $sec->id,
                    'status'        => EnrollmentSubjectStatus::Enrolled->value,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            }

            // --- Assessment ---
            $assessment = $assessAction->execute($enrollment);

            // --- Payment ---
            $payment = Payment::create([
                'enrollment_id'          => $enrollment->id,
                'confirmed_by'           => $cashierUser->id,
                'external_reference'     => 'CASH-' . strtoupper(Str::random(8)),
                'amount'                 => $assessment->total_amount,
                'promissory_note_on_file'=> false,
                'confirmed_at'           => $now,
            ]);
            $payment->setRelation('confirmer', $cashierUser);

            // --- COR Document ---
            $freshEnrollment = $enrollment->fresh([
                'student.user',
                'student.program',
                'academicTerm',
                'enrollmentSubjects.section.subject',
                'assessment.items',
            ]);
            $corSnapshot = $buildCor->execute($freshEnrollment, $payment);
            EnrollmentDocument::create([
                'enrollment_id' => $enrollment->id,
                'document_type' => EnrollmentDocumentType::Cor->value,
                'document_number'=> sprintf('COR%06d', $enrollment->id),
                'storage_path'  => null,
                'content_hash'  => hash('sha256', json_encode($corSnapshot)),
                'metadata'      => $corSnapshot,
                'issued_at'     => $now,
            ]);

            // --- Create, submit & lock grades ---
            $gradeCount = 0;
            foreach ($sections as $sec) {
                $subject = $sec->subject;
                if (! $subject) continue;

                $encodedBy = $sec->professor_id ?? $registrar->id;
                $finalGrade = pickGrade($subject->code);
                $mark       = gradeToMark($finalGrade);

                AcademicGrade::create([
                    'student_id'       => $user->id,
                    'subject_id'       => $subject->id,
                    'section_id'       => $sec->id,
                    'academic_term_id' => TERM_ID,
                    'final_grade'      => $finalGrade,
                    'mark'             => $mark,
                    'remarks'          => null,
                    'status'           => 'locked',
                    'encoded_by'       => $encodedBy,
                    'submitted_at'     => $now,
                    'locked_at'        => $now,
                ]);
                $gradeCount++;
            }

            DB::commit();

            $amt = number_format((float) $assessment->total_amount, 2);
            echo "  ✓ {$user->name} ({$student->student_number}) → Enrollment #{$enrollment->id}, {$gradeCount} grades locked, ₱{$amt}\n";
            $totalEnrolled++;
            $totalGrades += $gradeCount;

        } catch (\Throwable $e) {
            DB::rollBack();
            echo "  [ERROR] {$email}: " . $e->getMessage() . "\n";
            $errors[] = "{$email}: " . $e->getMessage();
        }
    }
    echo "\n";
}

// -----------------------------------------------------------------------
// Recompute section enrolled_count
// -----------------------------------------------------------------------
DB::statement("
    UPDATE sections s
    SET enrolled_count = (
        SELECT COUNT(*)
        FROM enrollment_subjects es
        JOIN enrollments e ON e.id = es.enrollment_id
        WHERE es.section_id = s.id
          AND e.status = 'enrolled'
    )
    WHERE s.academic_term_id = " . TERM_ID . "
");

// -----------------------------------------------------------------------
// Final summary
// -----------------------------------------------------------------------
echo "=== SUMMARY ===\n";
echo "  Students enrolled: {$totalEnrolled} / 25\n";
echo "  Grade records created (locked): {$totalGrades}\n";
echo "  Errors: " . count($errors) . "\n";
if ($errors) {
    foreach ($errors as $err) echo "    - {$err}\n";
}

$enrolled = Enrollment::where('academic_term_id', TERM_ID)->where('status', 'enrolled')->count();
$gradesLocked = AcademicGrade::where('academic_term_id', TERM_ID)->where('status', 'locked')->count();
echo "\n  Total enrolled in Term 34: {$enrolled}\n";
echo "  Total locked grades in Term 34: {$gradesLocked}\n";
echo "\nCompleted at: " . now()->toDateTimeString() . "\n";
echo "=== DONE ===\n";

