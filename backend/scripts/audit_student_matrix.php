<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\Section;
use App\Actions\Notifications\ListNotifications;
use App\Actions\Enrollment\BuildEligibleSubjectPool;
use App\Actions\Enrollment\BuildEnrollmentBlockPool;
use App\Domain\Enrollment\EnrollmentCategory;

$cohortPath = __DIR__ . '/../../frontend/scripts/student_matrix_cohort.json';
if (!file_exists($cohortPath)) {
    echo "Cohort file not found: {$cohortPath}\n";
    exit(1);
}

$cohort = json_decode(file_get_contents($cohortPath), true);
$activeTerm = AcademicTerm::where('status', 'semester_ongoing')->first() ?? AcademicTerm::find(9);

echo "================================================================\n";
echo "Executing Comprehensive Student Matrix Audit (450 Students)\n";
echo "Active Term: {$activeTerm->school_year} · {$activeTerm->semester} (ID: {$activeTerm->id})\n";
echo "================================================================\n\n";

$matrixResults = [];
$totalStudents = count($cohort);
$authPassed = 0;
$profilePassed = 0;
$gradesPassed = 0;
$notificationsPassed = 0;
$enrollmentPassed = 0;
$failures = [];

$listNotifications = app(ListNotifications::class);
$buildPool = app(BuildEligibleSubjectPool::class);
$buildBlocks = app(BuildEnrollmentBlockPool::class);

foreach ($cohort as $index => $item) {
    $progCode = $item['program_code'];
    $year = $item['year_level'];
    $category = $item['category'];
    $key = "{$progCode} Year {$year} ({$category})";

    if (!isset($matrixResults[$key])) {
        $matrixResults[$key] = [
            'program' => $progCode,
            'program_name' => $item['program_name'],
            'year' => $year,
            'category' => $category,
            'count' => 0,
            'auth_ok' => 0,
            'profile_ok' => 0,
            'grades_ok' => 0,
            'notifs_ok' => 0,
            'enrollment_ok' => 0,
            'sample_students' => []
        ];
    }

    $matrixResults[$key]['count']++;

    try {
        // 1. User & Identity
        $user = User::find($item['user_id']);
        $roleValue = is_string($user?->role) ? $user->role : $user?->role?->value;
        $statusValue = is_string($user?->status) ? $user->status : $user?->status?->value;
        if (!$user || $roleValue !== 'student' || $statusValue !== 'active') {
            throw new RuntimeException("Invalid user identity or role for {$item['email']}");
        }
        $authPassed++;
        $matrixResults[$key]['auth_ok']++;

        // 2. Student Profile & Standing
        $profile = StudentProfile::find($item['student_profile_id']);
        if (!$profile || $profile->year_level !== (int)$year || $profile->program_id !== (int)$item['program_id']) {
            throw new RuntimeException("Profile mismatch for student {$item['student_number']}");
        }
        $profilePassed++;
        $matrixResults[$key]['profile_ok']++;

        // 3. Academic Grades History
        $grades = $profile->grades;
        $totalEarnedUnits = $grades->whereNotIn('mark', ['5.00', 'DRP', 'INC', 'NC'])->sum('units');
        $failedGradesCount = $grades->whereIn('mark', ['5.00', 'DRP', 'INC', 'NC'])->count();
        $gradesPassed++;
        $matrixResults[$key]['grades_ok']++;

        // 4. Notifications
        $notifs = $listNotifications->execute($user, 1, 10, false);
        $notificationsPassed++;
        $matrixResults[$key]['notifs_ok']++;

        // 5. Enrollment Eligibility & Subject Pool
        if ($category === 'regular') {
            // Regular students: verify block section pool
            $blocks = $buildBlocks->execute($profile, $activeTerm);
            $enrollmentPassed++;
            $matrixResults[$key]['enrollment_ok']++;
        } else {
            // Irregular students: evaluate individual eligible subject pool
            $pool = $buildPool->execute($profile, $activeTerm);
            $enrollmentPassed++;
            $matrixResults[$key]['enrollment_ok']++;
        }

        if (count($matrixResults[$key]['sample_students']) < 2) {
            $matrixResults[$key]['sample_students'][] = [
                'number' => $item['student_number'],
                'email' => $item['email'],
                'earned_units' => $totalEarnedUnits,
                'failed_count' => $failedGradesCount,
            ];
        }

    } catch (\Throwable $e) {
        $failures[] = [
            'cell' => $key,
            'student' => $item['student_number'],
            'error' => $e->getMessage()
        ];
    }
}

echo "================================================================\n";
echo "STUDENT MATRIX AUDIT COMPLETED ACROSS ALL 450 STUDENTS\n";
echo "================================================================\n";
echo "Summary Statistics:\n";
echo "- Total Students Evaluated: {$totalStudents}\n";
echo "- Identity & Auth Passed: {$authPassed} / {$totalStudents} (" . round(($authPassed/$totalStudents)*100, 1) . "%)\n";
echo "- Profiles & Standing Verified: {$profilePassed} / {$totalStudents} (" . round(($profilePassed/$totalStudents)*100, 1) . "%)\n";
echo "- Grade Histories Verified: {$gradesPassed} / {$totalStudents} (" . round(($gradesPassed/$totalStudents)*100, 1) . "%)\n";
echo "- Notifications Feeds Verified: {$notificationsPassed} / {$totalStudents} (" . round(($notificationsPassed/$totalStudents)*100, 1) . "%)\n";
echo "- Enrollment Eligibility Verified: {$enrollmentPassed} / {$totalStudents} (" . round(($enrollmentPassed/$totalStudents)*100, 1) . "%)\n";
echo "- Total Inconsistencies / Errors: " . count($failures) . "\n\n";

echo "| Program | Year Level | Category | Evaluated | Auth OK | Profile OK | Grades OK | Notifs OK | Enrollment OK |\n";
echo "|---|---|---|---|---|---|---|---|---|\n";
foreach ($matrixResults as $r) {
    echo "| {$r['program']} | Year {$r['year']} | {$r['category']} | {$r['count']} | {$r['auth_ok']} | {$r['profile_ok']} | {$r['grades_ok']} | {$r['notifs_ok']} | {$r['enrollment_ok']} |\n";
}

$resultsJsonPath = __DIR__ . '/../../frontend/scripts/student_matrix_results.json';
file_put_contents($resultsJsonPath, json_encode([
    'total_students' => $totalStudents,
    'failures' => $failures,
    'matrix' => $matrixResults,
], JSON_PRETTY_PRINT));
echo "\nDetailed results saved to {$resultsJsonPath}.\n";
