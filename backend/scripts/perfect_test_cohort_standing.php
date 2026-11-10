<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\StudentProfile;
use App\Models\AcademicGrade;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;

echo "=== Aligning Standing & Prerequisite Omissions for 160 Test Cohort ===\n\n";

DB::beginTransaction();

try {
    // 1. Ensure profile enrollment_category matches student number naming
    $regCount = StudentProfile::where('student_number', 'LIKE', 'TEST-%')
        ->where('student_number', 'NOT LIKE', '%IRREG%')
        ->update(['enrollment_category' => 'regular']);
    echo "Set enrollment_category = 'regular' for {$regCount} regular students.\n";

    $irregCount = StudentProfile::where('student_number', 'LIKE', 'TEST-%IRREG%')
        ->update(['enrollment_category' => 'irregular']);
    echo "Set enrollment_category = 'irregular' for {$irregCount} irregular students.\n";

    // 2. Resolve LEAD subject IDs
    $leadMap = [
        1 => Subject::where('code', 'LIKE', 'LEAD%1')->pluck('id'),
        2 => Subject::where('code', 'LIKE', 'LEAD%3')->pluck('id'),
        3 => Subject::where('code', 'LIKE', 'LEAD%5')->pluck('id'),
        4 => Subject::where('code', 'LIKE', 'LEAD%7')->pluck('id'),
    ];

    $totalDeleted = 0;

    for ($y = 1; $y <= 4; $y++) {
        $irregStudents = StudentProfile::where('student_number', 'LIKE', 'TEST-%IRREG%')
            ->where('year_level', $y)
            ->pluck('id');

        $leadSubjectIds = $leadMap[$y];

        $deleted = AcademicGrade::where('academic_term_id', 5)
            ->whereIn('student_id', $irregStudents)
            ->whereIn('subject_id', $leadSubjectIds)
            ->delete();

        $totalDeleted += $deleted;
        echo "Year $y Irregular: Removed prerequisite LEAD subject for {$irregStudents->count()} students ({$deleted} grade rows deleted).\n";
    }

    DB::commit();
    echo "\n=== SUCCESSFULLY COMMITTED COHORT ALIGNMENT ===\n";

} catch (\Throwable $e) {
    DB::rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
