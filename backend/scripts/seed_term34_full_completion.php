<?php

/**
 * seed_term34_full_completion.php
 *
 * Completes Term 34 (2026-2027 · 1st Semester) data:
 *  1. Confirms payment for all `pending_payment` enrollments → `enrolled`.
 *  2. Assigns a college-appropriate faculty to every section that has
 *     enrolled students but no professor_id.
 *  3. Creates missing AcademicGrade records for enrolled enrollment_subjects.
 *  4. Submits and locks all outstanding draft/submitted grades so that every
 *     professor has "submitted" their grades and every grade is finalized.
 *
 * Safe to re-run: each step is idempotent or guarded by existence checks.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Actions\Billing\AssessEnrollment;
use App\Actions\Enrollment\BuildCorSnapshot;
use App\Domain\Enrollment\EnrollmentDocumentType;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use App\Models\EnrollmentDocument;
use App\Models\EnrollmentSubject;
use App\Models\Payment;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

echo "=== Term 34 Full Completion Script ===\n";
echo "Started at: " . now()->toDateTimeString() . "\n\n";

// -----------------------------------------------------------------------
// Constants
// -----------------------------------------------------------------------
const TERM_ID = 34;

// Numeric grades used in PH grading (1.0 = best, 5.0 = failing)
const PASSING_GRADES   = [1.00, 1.25, 1.50, 1.75, 2.00, 2.25, 2.50, 2.75, 3.00];
const FAILING_GRADE    = 5.00;
const NSTP_PE_CODES    = ['NSTP', 'PATHFIT', 'PE'];

// -----------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------
function isNstpOrPe(string $code): bool
{
    foreach (NSTP_PE_CODES as $prefix) {
        if (stripos($code, $prefix) === 0) {
            return true;
        }
    }
    return false;
}

function pickGrade(string $subjectCode): float|null
{
    // NSTP / PATHFIT / PE subjects get a mark (C = Completed) not a numeric grade
    if (isNstpOrPe($subjectCode)) {
        return null; // null final_grade + mark='C'
    }

    // Realistic distribution: mostly passing, ~5% fail
    $rand = mt_rand(1, 100);
    if ($rand <= 5) {
        return FAILING_GRADE;
    }
    $grades = PASSING_GRADES;
    return $grades[array_rand($grades)];
}

function gradeToMark(?float $grade): string
{
    if ($grade === null) return 'C';         // Completed (NSTP/PE)
    if ($grade >= 5.0)   return '5.00';      // Failed
    return number_format($grade, 2);
}

// -----------------------------------------------------------------------
// Pre-flight checks
// -----------------------------------------------------------------------
$term = AcademicTerm::find(TERM_ID);
if (! $term) {
    echo "[FATAL] Academic Term " . TERM_ID . " not found.\n";
    exit(1);
}
echo "Working on: Term {$term->id} — {$term->school_year} · {$term->semester} Semester\n\n";

$cashierUser      = User::where('email', 'accounting.seed@grc.test')->firstOrFail();
$registrarUser    = User::where('email', 'registrar-head.seed@grc.test')->firstOrFail();
$assessEnrollment = app(AssessEnrollment::class);
$buildCorSnapshot  = app(BuildCorSnapshot::class);

// Faculty pool by college (use existing faculty from the DB)
$facultyByCollege = [];
foreach (['ccs', 'coe', 'cbae', 'coa'] as $college) {
    $facultyByCollege[$college] = User::where('role', 'faculty')
        ->where('college', $college)
        ->pluck('id')
        ->toArray();
}
// Fallback: faculty with no college set — grab the known seed faculty
$facultySeed = User::where('email', 'faculty.seed@grc.test')->first();
$fallbackFaculty = $facultySeed ? [$facultySeed->id] : [];

echo "Faculty pool:\n";
foreach ($facultyByCollege as $college => $ids) {
    echo "  {$college}: " . count($ids) . " faculty\n";
}
echo "\n";

// -----------------------------------------------------------------------
// STEP 1 — Confirm payment for `pending_payment` enrollments
// -----------------------------------------------------------------------
echo "=== STEP 1: Confirming payment for pending_payment enrollments ===\n";

$pendingEnrollments = Enrollment::where('academic_term_id', TERM_ID)
    ->where('status', EnrollmentStatus::PendingPayment->value)
    ->with(['student.user', 'student.program', 'academicTerm', 'enrollmentSubjects.section.subject', 'assessment.items'])
    ->get();

echo "Found {$pendingEnrollments->count()} pending_payment enrollment(s).\n";

$confirmedCount = 0;
$now = now();

foreach ($pendingEnrollments as $enrollment) {
    DB::beginTransaction();
    try {
        // Run assessment if it doesn't exist
        $assessment = $enrollment->assessment;
        if (! $assessment) {
            $assessment = $assessEnrollment->execute($enrollment);
        }

        // Confirm payment
        $payment = Payment::firstOrCreate(
            ['enrollment_id' => $enrollment->id],
            [
                'confirmed_by'         => $cashierUser->id,
                'external_reference'   => 'CASH-' . strtoupper(Str::random(8)),
                'amount'               => $assessment->total_amount,
                'promissory_note_on_file' => false,
                'confirmed_at'         => $now,
            ]
        );

        // Move enrollment to enrolled
        $enrollment->update([
            'status'                => EnrollmentStatus::Enrolled->value,
            'payment_confirmed_at'  => $now,
            'enrolled_at'           => $now,
        ]);

        // Generate COR if not existing
        $existingDoc = EnrollmentDocument::where('enrollment_id', $enrollment->id)
            ->where('document_type', EnrollmentDocumentType::Cor->value)
            ->exists();

        if (! $existingDoc) {
            $freshEnrollment = $enrollment->fresh([
                'student.user',
                'student.program',
                'academicTerm',
                'enrollmentSubjects.section.subject',
                'assessment.items',
            ]);
            $payment->setRelation('confirmer', $cashierUser);
            $corSnapshot = $buildCorSnapshot->execute($freshEnrollment, $payment);

            EnrollmentDocument::create([
                'enrollment_id' => $enrollment->id,
                'document_type' => EnrollmentDocumentType::Cor->value,
                'document_number' => sprintf('COR%06d', $enrollment->id),
                'storage_path' => null,
                'content_hash' => hash('sha256', json_encode($corSnapshot)),
                'metadata'     => $corSnapshot,
                'issued_at'    => $now,
            ]);
        }

        DB::commit();
        $studentName = $enrollment->student->user->name ?? 'Unknown';
        echo "  ✓ Confirmed payment for Enrollment #{$enrollment->id} — {$studentName}\n";
        $confirmedCount++;
    } catch (\Throwable $e) {
        DB::rollBack();
        echo "  [ERROR] Enrollment #{$enrollment->id}: " . $e->getMessage() . "\n";
    }
}

echo "  → Confirmed: {$confirmedCount} enrollment(s).\n\n";

// Recompute enrolled_count for sections after payment confirmations
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
// STEP 2 — Assign faculty to sections missing a professor
// -----------------------------------------------------------------------
echo "=== STEP 2: Assigning faculty to sections with no professor ===\n";

// Sections that have at least 1 enrolled student but no professor assigned
$sectionsNeedingFaculty = Section::where('academic_term_id', TERM_ID)
    ->whereNull('professor_id')
    ->whereHas('enrollmentSubjects.enrollment', fn($q) => $q->where('status', 'enrolled'))
    ->with(['subject'])
    ->get();

echo "Found {$sectionsNeedingFaculty->count()} section(s) without faculty.\n";

$assignedCount = 0;
$collegeCounters = [];  // For round-robin faculty assignment

foreach ($sectionsNeedingFaculty as $section) {
    $rawCollege = $section->subject->college ?? '';
    // Handle CollegeCode enum (backed enum has ->value)
    if ($rawCollege instanceof \BackedEnum) {
        $rawCollege = $rawCollege->value;
    }
    $subjectCollege = strtolower((string) $rawCollege);
    $pool = $facultyByCollege[$subjectCollege] ?? $fallbackFaculty;

    if (empty($pool)) {
        echo "  [WARN] No faculty found for college '{$subjectCollege}' — section #{$section->id} skipped.\n";
        continue;
    }

    // Round-robin within college pool
    $idx = ($collegeCounters[$subjectCollege] ?? 0) % count($pool);
    $facultyId = $pool[$idx];
    $collegeCounters[$subjectCollege] = $idx + 1;

    $section->update(['professor_id' => $facultyId]);
    $assignedCount++;
}

echo "  → Assigned faculty to {$assignedCount} section(s).\n\n";

// Ensure all academic grades have encoded_by synced with the section's assigned professor
DB::statement("
    UPDATE academic_grades ag
    JOIN sections s ON s.id = ag.section_id
    SET ag.encoded_by = s.professor_id
    WHERE ag.academic_term_id = " . TERM_ID . "
      AND s.professor_id IS NOT NULL
");


// -----------------------------------------------------------------------
// STEP 3 — Create missing grade records for enrolled subjects
// -----------------------------------------------------------------------
echo "=== STEP 3: Creating missing grade records ===\n";

// Load all enrolled subjects in term 34 (status = enrolled)
$enrolledSubjects = EnrollmentSubject::query()
    ->join('enrollments', 'enrollments.id', '=', 'enrollment_subjects.enrollment_id')
    ->where('enrollments.academic_term_id', TERM_ID)
    ->where('enrollments.status', 'enrolled')
    ->whereNotExists(function ($query) {
        // No matching grade: same student + same section + same term
        $query->select(DB::raw(1))
            ->from('academic_grades')
            ->whereColumn('academic_grades.student_id', 'enrollments.student_id')
            ->whereColumn('academic_grades.section_id', 'enrollment_subjects.section_id')
            ->where('academic_grades.academic_term_id', TERM_ID);
    })
    ->select(
        'enrollment_subjects.id as es_id',
        'enrollment_subjects.section_id',
        'enrollments.student_id',
    )
    ->get();

echo "Found {$enrolledSubjects->count()} enrollment subject(s) without grades.\n";

// Cache subject info per section
$sectionSubjectCache = [];
$sectionFacultyCache = [];

$gradeCreatedCount = 0;

foreach ($enrolledSubjects as $es) {
    $sectionId = $es->section_id;

    if (! isset($sectionSubjectCache[$sectionId])) {
        $sec = Section::with('subject')->find($sectionId);
        $sectionSubjectCache[$sectionId] = $sec;
        $sectionFacultyCache[$sectionId] = $sec?->professor_id;
    }

    $section  = $sectionSubjectCache[$sectionId];
    $subject  = $section?->subject;
    $encodedBy = $sectionFacultyCache[$sectionId] ?? $registrarUser->id;

    if (! $subject) {
        echo "  [WARN] Section #{$sectionId} has no subject — skipping.\n";
        continue;
    }

    $finalGrade = pickGrade($subject->code);
    $mark       = gradeToMark($finalGrade);

    try {
        AcademicGrade::create([
            'student_id'       => $es->student_id,
            'subject_id'       => $subject->id,
            'section_id'       => $sectionId,
            'academic_term_id' => TERM_ID,
            'final_grade'      => $finalGrade,
            'mark'             => $mark,
            'remarks'          => null,
            'status'           => 'draft',
            'encoded_by'       => $encodedBy,
            'submitted_at'     => null,
            'locked_at'        => null,
        ]);
        $gradeCreatedCount++;
    } catch (\Throwable $e) {
        echo "  [ERROR] Grade for student #{$es->student_id} / section #{$sectionId}: " . $e->getMessage() . "\n";
    }
}

echo "  → Created {$gradeCreatedCount} grade record(s).\n\n";

// -----------------------------------------------------------------------
// STEP 4 — Submit all draft grades for Term 34
// -----------------------------------------------------------------------
echo "=== STEP 4: Submitting all draft grades ===\n";

$submittedNow = now();

$draftGradesUpdated = AcademicGrade::where('academic_term_id', TERM_ID)
    ->where('status', 'draft')
    ->update([
        'status'       => 'submitted',
        'submitted_at' => $submittedNow,
        'updated_at'   => $submittedNow,
    ]);

echo "  → Submitted {$draftGradesUpdated} draft grade(s).\n\n";

// -----------------------------------------------------------------------
// STEP 5 — Lock all submitted grades for Term 34
// -----------------------------------------------------------------------
echo "=== STEP 5: Locking all submitted grades ===\n";

$lockedNow = now();

$lockedGradesUpdated = AcademicGrade::where('academic_term_id', TERM_ID)
    ->where('status', 'submitted')
    ->update([
        'status'     => 'locked',
        'locked_at'  => $lockedNow,
        'updated_at' => $lockedNow,
    ]);

echo "  → Locked {$lockedGradesUpdated} grade(s).\n\n";

// -----------------------------------------------------------------------
// STEP 6 — Final summary
// -----------------------------------------------------------------------
echo "=== FINAL SUMMARY ===\n";

$enrolled     = Enrollment::where('academic_term_id', TERM_ID)->where('status', 'enrolled')->count();
$pendingLeft  = Enrollment::where('academic_term_id', TERM_ID)->whereIn('status', ['pending_payment', 'pending_registrar_approval', 'draft'])->count();
$gradesLocked = AcademicGrade::where('academic_term_id', TERM_ID)->where('status', 'locked')->count();
$gradesSubmit = AcademicGrade::where('academic_term_id', TERM_ID)->where('status', 'submitted')->count();
$gradesDraft  = AcademicGrade::where('academic_term_id', TERM_ID)->where('status', 'draft')->count();
$sectNoFac    = Section::where('academic_term_id', TERM_ID)
    ->whereNull('professor_id')
    ->whereHas('enrollmentSubjects.enrollment', fn($q) => $q->where('status', 'enrolled'))
    ->count();

echo "  Enrolled students (term 34): {$enrolled}\n";
echo "  Still pending/draft:         {$pendingLeft}\n";
echo "  Grades — locked:             {$gradesLocked}\n";
echo "  Grades — submitted:          {$gradesSubmit}\n";
echo "  Grades — draft:              {$gradesDraft}\n";
echo "  Sections missing faculty:    {$sectNoFac}\n";
echo "\nCompleted at: " . now()->toDateTimeString() . "\n";
echo "=== DONE ===\n";
