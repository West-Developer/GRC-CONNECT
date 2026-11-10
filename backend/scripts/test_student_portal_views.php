<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Actions\Enrollment\ListEnrollments;
use App\Models\AcademicGrade;
use App\Models\Enrollment;
use App\Models\User;

$listEnrollments = app(ListEnrollments::class);

$testEmails = [
    'edgar.rodriguez@grc.com',
    'gil.flores@grc.com',
    'charmaine.andrada@grc.com',
    'lara.quiambao@grc.com',
    'erlinda.valencia@grc.com',
    'benjamin.ramirez@grc.com',
    'norma.macatangay@grc.com',
    'joy.dungog@grc.com',
    'aurora.sarmiento@grc.com',
    'andres.mercado@grc.com',
    'bayani.morales@grc.com',
    'rey.magsino@grc.com',
    'josephine.ramirez@grc.com',
    'sharon.calungsod@grc.com',
    'concepcion.bernardo@grc.com',
    'dominic.watson@grc.com',
    'ricardo.tagumpay@grc.com',
    'leandro.panganiban@grc.com',
    'rogelio.lakandula@grc.com',
    'herminio.miller@grc.com',
    'sharon.batac@grc.com',
    'jerome.delossantos@grc.com',
    'jayson.malvar@grc.com',
    'isabel.ward@grc.com',
    'francisco.scott@grc.com',
];

echo "=== Verifying 25 Students Visibility in Student Portal ===\n\n";

$passCount = 0;

foreach ($testEmails as $email) {
    $user = User::where('email', $email)->first();
    if (! $user) {
        echo "[FAIL] User not found: {$email}\n";
        continue;
    }

    // 1. Check enrollments visible to this student in 2nd semester (term 36)
    $paginator = $listEnrollments->execute($user, ['academic_term_id' => 36]);
    $enrollments = $paginator->items();

    // 2. Check grades visible to this student in 2nd semester (term 36)
    $grades = AcademicGrade::visibleTo($user)->where('academic_term_id', 36)->with(['subject', 'encoder'])->get();


    if (count($enrollments) === 1 && $enrollments[0]->status->value === 'enrolled' && $grades->count() > 0) {
        $enr = $enrollments[0];
        $sectionCode = $enr->enrollmentSubjects->first()?->section?->section_code ?? 'N/A';
        echo "✓ {$user->name} ({$email}): Enrolled in {$sectionCode} (Enrollment #{$enr->id}), {$grades->count()} grades locked\n";
        $passCount++;
    } else {
        echo "[FAIL] {$email}: Enrollments=" . count($enrollments) . ", Grades=" . $grades->count() . "\n";
    }
}

echo "\nSummary: {$passCount} / " . count($testEmails) . " students verified 100% visible and enrolled.\n";

