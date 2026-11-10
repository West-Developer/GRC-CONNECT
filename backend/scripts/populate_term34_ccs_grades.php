<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Domain\Academic\GradeMark;
use App\Domain\Academic\GradeStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Facades\DB;

echo "=== Populating Grades for 40 CCS Enrolled Students in Term 34 ===\n\n";

DB::beginTransaction();

try {
    $term = AcademicTerm::findOrFail(34);
    $defaultFaculty = User::where('email', 'faculty.seed@grc.test')->firstOrFail();

    // Get all enrollments for term 34 with their subjects
    $enrollments = Enrollment::where('academic_term_id', 34)
        ->where('status', 'enrolled')
        ->with('student.user', 'enrollmentSubjects.section.subject')
        ->get();

    echo "Found {$enrollments->count()} enrolled students in Term 34.\n";

    $gradeCount = 0;
    $now = now();

    // Grade options for realistic distribution
    $passingMarks = ['1.25', '1.50', '1.75', '2.00', '2.25'];

    foreach ($enrollments as $enrollment) {
        $student = $enrollment->student;
        $isRegular = ($student->enrollment_category === 'regular');

        foreach ($enrollment->enrollmentSubjects as $idx => $es) {
            $section = $es->section;
            if (!$section) continue;

            $subject = $section->subject;
            if (!$subject) continue;

            // Ensure section has a professor assigned
            if (!$section->professor_id) {
                $section->update(['professor_id' => $defaultFaculty->id]);
            }

            // Clean up existing grade for this student+subject+term
            AcademicGrade::where('student_id', $student->id)
                ->where('subject_id', $subject->id)
                ->where('academic_term_id', 34)
                ->delete();

            // Select mark
            if (str_contains($subject->code, 'LEAD')) {
                $markStr = 'C';
                $numGrade = null;
            } else {
                $markStr = $passingMarks[($student->id + $idx) % count($passingMarks)];
                $numGrade = $markStr;
            }

            $mark = GradeMark::from($markStr);

            // For section 13657 (IT101 - ITC), we leave it as DRAFT so faculty can submit via Playwright browser!
            $isDemoSection = ($section->id === 13657);
            $status = $isDemoSection ? GradeStatus::Draft : GradeStatus::Submitted;

            AcademicGrade::create([
                'student_id' => $student->id,
                'subject_id' => $subject->id,
                'section_id' => $section->id,
                'academic_term_id' => 34,
                'final_grade' => $numGrade,
                'mark' => $mark,
                'remarks' => $isDemoSection ? 'Class participation & exams' : 'Completed coursework',
                'status' => $status,
                'encoded_by' => $section->professor_id ?? $defaultFaculty->id,
                'submitted_at' => $isDemoSection ? null : $now,
            ]);

            $gradeCount++;
        }
    }

    DB::commit();
    echo "\n=== Successfully populated {$gradeCount} grades in Term 34 ===\n";
    echo "Section 13657 (IT101 - ITC) is set to 'draft' for live Playwright UI submission.\n";
    echo "All other sections are set to 'submitted' ready for Registrar locking.\n";

} catch (\Throwable $e) {
    DB::rollBack();
    echo "\n[ERROR] Failed to populate grades: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

