<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Actions\Academic\UpdateAcademicGrade;
use App\Domain\Audit\AuditRequestContext;
use App\Models\AcademicGrade;
use App\Models\User;

echo "=== Locking Remaining Submitted Grades for CCS in Term 34 ===\n\n";

$updater = app(UpdateAcademicGrade::class);
$registrar = User::where('email', 'registrar-head.seed@grc.test')->firstOrFail();
$context = new AuditRequestContext(
    requestId: (string) \Illuminate\Support\Str::uuid(),
    ipAddress: '127.0.0.1'
);

$submittedGrades = AcademicGrade::where('academic_term_id', 34)
    ->where('status', 'submitted')
    ->with('student', 'subject', 'section')
    ->get();

echo "Found {$submittedGrades->count()} submitted grades to lock.\n";

$lockedCount = 0;
foreach ($submittedGrades as $grade) {
    $updater->execute($grade, ['action' => 'lock'], $registrar, $context);
    $lockedCount++;
}

echo "Successfully locked {$lockedCount} grades in Term 34!\n";
echo "Total grades now locked for Term 34: " . AcademicGrade::where('academic_term_id', 34)->where('status', 'locked')->count() . "\n";
