<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Section;
use App\Models\AcademicGrade;
use App\Models\User;
use App\Models\AcademicTerm;

$currentTerm = AcademicTerm::where('status', 'semester_ongoing')->first();
echo "Current Term: ID={$currentTerm->id}, {$currentTerm->school_year} - {$currentTerm->semester} ({$currentTerm->status->value})" . PHP_EOL;

$facultyUsers = [
    'faculty.seed@grc.test',
    'faculty.sample.ccs@grc.test',
];

foreach ($facultyUsers as $email) {
    $user = User::where('email', $email)->first();
    if (!$user) {
        echo "User {$email} not found!" . PHP_EOL;
        continue;
    }
    $visible = Section::query()->visibleTo($user)->with(['subject', 'academicTerm'])->get();
    echo "\nVisible sections for {$email} (ID={$user->id}): {$visible->count()}" . PHP_EOL;
    foreach ($visible->take(5) as $s) {
        $enrolled = $s->enrollmentSubjects()->count();
        $recorded = AcademicGrade::where('section_id', $s->id)->count();
        echo "  ID: {$s->id} | Code: {$s->section_code} | Term: {$s->academic_term_id} | Status: {$s->status->value} | Enrolled: {$enrolled} | Recorded: {$recorded}" . PHP_EOL;
    }
}
