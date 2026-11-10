<?php

/**
 * fix_all_student_enrollments_and_grades.php
 *
 * Ensures all requested students are properly enrolled using their StudentProfile ID
 * (student_profiles.id), not their User ID (users.id).
 *
 * It also assigns professors to all sections, submits and locks all grades,
 * confirms payments, and generates CORs.
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

echo "=== Complete Term 34 Student Enrollment & Grades Fix ===\n";
echo "Started at: " . now()->toDateTimeString() . "\n\n";

const TERM_ID = 34;

function pickGrade(string $code): ?float
{
    $prefixes = ['NSTP', 'PATHFIT', 'PATHFIT1', 'PATHFIT2', 'PATHFIT3', 'PATHFIT4', 'PE'];
    foreach ($prefixes as $p) {
        if (stripos(trim($code), $p) === 0) return null; // C mark for NSTP / PE
    }
    $r = mt_rand(1, 100);
    if ($r <= 5) return 5.00;
    $grades = [1.00, 1.25, 1.50, 1.75, 2.00, 2.25, 2.50, 2.75, 3.00];
    return $grades[array_rand($grades)];
}

function gradeToMark(?float $g): string
{
    if ($g === null) return 'C';
    if ($g >= 5.0)   return '5.00';
    return number_format($g, 2);
}

// 1. Term and system users
$term         = AcademicTerm::findOrFail(TERM_ID);
$cashierUser  = User::where('email', 'accounting.seed@grc.test')->firstOrFail();
$registrar    = User::where('email', 'registrar-head.seed@grc.test')->firstOrFail();
$assessAction = app(AssessEnrollment::class);
$buildCor     = app(BuildCorSnapshot::class);
$now          = now();

// 2. Assign professors to all sections in Term 34 that don't have one
$facultyByCollege = [];
foreach (['cbae', 'ccs', 'coe', 'coa'] as $col) {
    $facultyByCollege[$col] = User::where('role', 'faculty')
        ->where('college', $col)
        ->pluck('id')
        ->toArray();
}
$fallbackFaculty = User::where('email', 'faculty.seed@grc.test')->pluck('id')->toArray();

$unassignedSections = Section::where('academic_term_id', TERM_ID)
    ->whereNull('professor_id')
    ->with('subject')
    ->get();

$colCounters = [];
foreach ($unassignedSections as $sec) {
    $col = strtolower($sec->subject->college?->value ?? $sec->subject->college ?? 'ccs');
    $pool = $facultyByCollege[$col] ?? $fallbackFaculty;
    if (empty($pool)) $pool = $fallbackFaculty;

    $idx = ($colCounters[$col] ?? 0) % count($pool);
    $profId = $pool[$idx];
    $colCounters[$col] = $idx + 1;

    $sec->update(['professor_id' => $profId]);
}
echo "Assigned professors to {$unassignedSections->count()} unassigned sections.\n\n";

// 3. Define target groups
$groups = [
    [
        'label'        => 'BSED-FIL Year 1 → FIL101',
        'section_code' => 'FIL101',
        'category'     => 'regular',
        'year'         => 1,
        'emails'       => [
            'edgar.rodriguez@grc.com',
            'gil.flores@grc.com',
            'charmaine.andrada@grc.com',
            'lara.quiambao@grc.com',
            'erlinda.valencia@grc.com',
        ],
    ],
    [
        'label'        => 'BEED Year 1 → ELEM101',
        'section_code' => 'ELEM101',
        'category'     => 'regular',
        'year'         => 1,
        'emails'       => [
            'benjamin.ramirez@grc.com',
            'norma.macatangay@grc.com',
            'joy.dungog@grc.com',
            'aurora.sarmiento@grc.com',
            'andres.mercado@grc.com',
        ],
    ],
    [
        'label'        => 'BSA Year 3 → ACC301',
        'section_code' => 'ACC301',
        'category'     => 'regular',
        'year'         => 3,
        'emails'       => [
            'bayani.morales@grc.com',
            'rey.magsino@grc.com',
            'josephine.ramirez@grc.com',
            'sharon.calungsod@grc.com',
            'concepcion.bernardo@grc.com',
        ],
    ],
    [
        'label'        => 'BSIT Year 1 → IT101',
        'section_code' => 'IT101',
        'category'     => 'regular',
        'year'         => 1,
        'emails'       => [
            'dominic.watson@grc.com',
            'ricardo.tagumpay@grc.com',
            'leandro.panganiban@grc.com',
            'rogelio.lakandula@grc.com',
            'herminio.miller@grc.com',
        ],
    ],
    [
        'label'        => 'BSBA-MM Year 1 → MM103',
        'section_code' => 'MM103',
        'category'     => 'regular',
        'year'         => 1,
        'emails'       => [
            'sharon.batac@grc.com',
            'jerome.delossantos@grc.com',
            'jayson.malvar@grc.com',
            'isabel.ward@grc.com',
            'francisco.scott@grc.com',
        ],
    ],
];

$enrolledCount = 0;
$gradesCount = 0;

foreach ($groups as $grp) {
    echo "--- {$grp['label']} ---\n";
    $sections = Section::where('academic_term_id', TERM_ID)
        ->where('section_code', $grp['section_code'])
        ->with('subject')
        ->get();

    if ($sections->isEmpty()) {
        echo "  [ERROR] Section {$grp['section_code']} not found in Term 34!\n";
        continue;
    }

    $totalUnits = (float) $sections->sum(fn($s) => $s->subject->units ?? 0);

    foreach ($grp['emails'] as $email) {
        $user = User::where('email', $email)->first();
        if (! $user) {
            echo "  [WARN] User not found: {$email}\n";
            continue;
        }

        $student = StudentProfile::where('user_id', $user->id)->first();
        if (! $student) {
            echo "  [WARN] StudentProfile not found for: {$email}\n";
            continue;
        }

        DB::beginTransaction();
        try {
            // Update student profile category and year level
            $student->update([
                'enrollment_category' => $grp['category'],
                'year_level'          => $grp['year'],
            ]);

            // Clean up any stale/mismatched enrollments for this profile or user
            $existingEnrollments = Enrollment::where('academic_term_id', TERM_ID)
                ->where(function ($q) use ($student, $user) {
                    $q->where('student_id', $student->id)
                      ->orWhere('student_id', $user->id);
                })
                ->get();

            foreach ($existingEnrollments as $ex) {
                Payment::where('enrollment_id', $ex->id)->delete();
                EnrollmentDocument::where('enrollment_id', $ex->id)->delete();
                EnrollmentSubject::where('enrollment_id', $ex->id)->delete();
                DB::table('assessments')->where('enrollment_id', $ex->id)->delete();
                $ex->delete();
            }

            // Clean up any existing grades for this student profile or user in term 34
            AcademicGrade::where('academic_term_id', TERM_ID)
                ->where(function ($q) use ($student, $user) {
                    $q->where('student_id', $student->id)
                      ->orWhere('student_id', $user->id);
                })
                ->delete();

            // Create new Enrollment with student_id = student_profiles.id
            $enrollment = Enrollment::create([
                'student_id'                 => $student->id, // MUST be student_profiles.id!
                'academic_term_id'           => TERM_ID,
                'status'                     => EnrollmentStatus::Enrolled->value,
                'total_units'                => $totalUnits,
                'requires_overload_approval' => false,
                'submitted_at'               => $now,
                'registrar_decided_at'       => $now,
                'payment_confirmed_at'       => $now,
                'enrolled_at'                => $now,
            ]);

            // Create enrollment subjects
            foreach ($sections as $sec) {
                EnrollmentSubject::create([
                    'enrollment_id' => $enrollment->id,
                    'section_id'    => $sec->id,
                    'status'        => EnrollmentSubjectStatus::Enrolled->value,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            }

            // Assessment
            $assessment = $assessAction->execute($enrollment);

            // Payment
            $payment = Payment::create([
                'enrollment_id'           => $enrollment->id,
                'confirmed_by'            => $cashierUser->id,
                'external_reference'      => 'CASH-' . strtoupper(Str::random(8)),
                'amount'                  => $assessment->total_amount,
                'promissory_note_on_file' => false,
                'confirmed_at'            => $now,
            ]);
            $payment->setRelation('confirmer', $cashierUser);

            // COR Document
            $freshEnrollment = $enrollment->fresh([
                'student.user',
                'student.program',
                'academicTerm',
                'enrollmentSubjects.section.subject',
                'assessment.items',
            ]);
            $corSnapshot = $buildCor->execute($freshEnrollment, $payment);
            EnrollmentDocument::create([
                'enrollment_id'  => $enrollment->id,
                'document_type'  => EnrollmentDocumentType::Cor->value,
                'document_number'=> sprintf('COR%06d', $enrollment->id),
                'storage_path'   => null,
                'content_hash'   => hash('sha256', json_encode($corSnapshot)),
                'metadata'       => $corSnapshot,
                'issued_at'      => $now,
            ]);

            // Create locked AcademicGrade records with student_id = student_profiles.id
            $studentGradeCount = 0;
            foreach ($sections as $sec) {
                $subj = $sec->subject;
                if (! $subj) continue;

                $finalGrade = pickGrade($subj->code);
                $mark       = gradeToMark($finalGrade);
                $encodedBy  = $sec->professor_id ?? $registrar->id;

                AcademicGrade::create([
                    'student_id'       => $student->id, // MUST be student_profiles.id!
                    'subject_id'       => $subj->id,
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
                $studentGradeCount++;
            }

            DB::commit();

            echo "  ✓ {$user->name} ({$student->student_number}) [Profile ID: {$student->id}] → Enrolled in {$grp['section_code']} with {$studentGradeCount} locked grades.\n";
            $enrolledCount++;
            $gradesCount += $studentGradeCount;

        } catch (\Throwable $e) {
            DB::rollBack();
            echo "  [ERROR] {$email}: " . $e->getMessage() . "\n";
        }
    }
    echo "\n";
}

// 4. Update section enrolled_count
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

echo "\n=== ALL 25 STUDENTS ENROLLED AND GRADED WITH PROPER PROFILE FOREIGN KEYS ===\n";
echo "Total processed: {$enrolledCount} students, {$gradesCount} grades created.\n";

