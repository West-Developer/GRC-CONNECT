<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\CurriculumSubject;
use App\Models\StudentProfile;
use App\Models\AcademicGrade;
use App\Domain\Academic\GradeStatus;
use App\Domain\Academic\GradeMark;
use App\Models\AcademicTerm;
use Illuminate\Support\Facades\DB;

echo "=== Seeding Prior Prerequisite Grades (Term 5) for COA, CBAE, and COE Test Students ===\n\n";

DB::beginTransaction();

try {
    $testStudents = StudentProfile::with('curriculum')
        ->where('student_number', 'LIKE', 'TEST-%')
        ->get();

    $seededCount = 0;
    $existingGrades = AcademicGrade::where('academic_term_id', 5)->pluck('student_id', 'subject_id');

    foreach ($testStudents as $student) {
        // Skip if student already has historical grades in Term 5 (like CCS)
        $existingCount = AcademicGrade::where('student_id', $student->id)->where('academic_term_id', 5)->count();
        if ($existingCount > 0) {
            continue;
        }

        $priorSubjects = CurriculumSubject::where('curriculum_id', $student->curriculum_id)
            ->where(function ($q) use ($student) {
                $q->where('year_level', '<', $student->year_level)
                  ->orWhere(function ($sub) use ($student) {
                      $sub->where('year_level', $student->year_level)
                          ->where('semester', '1st');
                  });
            })
            ->orderBy('year_level')
            ->orderBy('semester')
            ->get();

        if ($priorSubjects->isEmpty()) {
            continue;
        }

        $isRegular = ($student->enrollment_category === 'regular');

        if ($isRegular) {
            $subjectsToPass = $priorSubjects;
        } else {
            // For irregular students, leave 1 subject unpassed so they maintain irregular standing
            $subjectsToPass = $priorSubjects->count() > 1
                ? $priorSubjects->slice(0, $priorSubjects->count() - 1)
                : $priorSubjects;
        }

        foreach ($subjectsToPass as $cs) {
            AcademicGrade::create([
                'academic_term_id' => 5,
                'student_id' => $student->id,
                'subject_id' => $cs->subject_id,
                'final_grade' => $isRegular ? '1.50' : '2.00',
                'mark' => GradeMark::from($isRegular ? '1.50' : '2.00'),
                'status' => GradeStatus::Locked,
                'encoded_by' => 3,
                'locked_by' => 2,
                'submitted_at' => now(),
                'locked_at' => now(),
            ]);
            $seededCount++;
        }
    }

    DB::commit();
    echo "Successfully seeded {$seededCount} historical prerequisite grades in Term 5 for COA, CBAE, and COE.\n";

} catch (\Throwable $e) {
    DB::rollBack();
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
