<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\User;
use App\Models\AcademicGrade;
use App\Models\Enrollment;

$columns = \Illuminate\Support\Facades\Schema::getColumnListing('academic_terms');
echo "Academic Term Columns: " . implode(', ', $columns) . PHP_EOL;

$ongoingTerm = AcademicTerm::where('status', 'semester_ongoing')->first();
$currentTerm = $ongoingTerm ?: AcademicTerm::latest('id')->first();

echo "Current Term: " . ($currentTerm ? "{$currentTerm->id}: {$currentTerm->school_year} - {$currentTerm->semester} [{$currentTerm->status->value}]" : 'None') . PHP_EOL;
echo "Ongoing Term: " . ($ongoingTerm ? "{$ongoingTerm->id}: {$ongoingTerm->school_year} - {$ongoingTerm->semester} [{$ongoingTerm->status->value}]" : 'None') . PHP_EOL;

if ($currentTerm) {
    $secCount = Section::where('academic_term_id', $currentTerm->id)->count();
    $draftSecCount = Section::where('academic_term_id', $currentTerm->id)->where('status', 'draft')->count();
    $plannedSecCount = Section::where('academic_term_id', $currentTerm->id)->where('status', 'planned')->count();
    $openSecCount = Section::where('academic_term_id', $currentTerm->id)->where('status', 'open')->count();
    echo "Term {$currentTerm->id} Sections: Total={$secCount}, Draft={$draftSecCount}, Planned={$plannedSecCount}, Open={$openSecCount}" . PHP_EOL;
}

echo "Section Columns: " . implode(', ', \Illuminate\Support\Facades\Schema::getColumnListing('sections')) . PHP_EOL;
$roles = User::get()->map(fn($u) => $u->role instanceof \BackedEnum ? $u->role->value : (string)$u->role)->unique();
echo "Roles in Users table: " . implode(', ', $roles->toArray()) . PHP_EOL;

$admissionUsers = User::where('role', 'admission')->orWhere('email', 'like', '%admiss%')->get(['id', 'name', 'email', 'role']);
echo "Admission users count: " . $admissionUsers->count() . PHP_EOL;
foreach ($admissionUsers as $u) {
    $r = $u->role instanceof \BackedEnum ? $u->role->value : (string)$u->role;
    echo "  {$u->id} | {$u->name} | {$u->email} | {$r}" . PHP_EOL;
}

$facultyUsers = User::where('role', 'faculty')->orWhere('email', 'like', '%faculty%')->limit(10)->get(['id', 'name', 'email', 'role']);
echo "Faculty users count: " . $facultyUsers->count() . PHP_EOL;
foreach ($facultyUsers as $f) {
    $r = $f->role instanceof \BackedEnum ? $f->role->value : (string)$f->role;
    echo "  {$f->id} | {$f->name} | {$f->email} | {$r}" . PHP_EOL;
}

echo "\n--- Faculty with most sections ---" . PHP_EOL;
$topFaculty = Section::selectRaw('professor_id, count(*) as cnt')
    ->whereNotNull('professor_id')
    ->groupBy('professor_id')
    ->orderByDesc('cnt')
    ->limit(5)
    ->get();
foreach ($topFaculty as $row) {
    $user = User::find($row->professor_id);
    echo "Professor ID: {$row->professor_id} ({$user?->name} / {$user?->email}) has {$row->cnt} sections" . PHP_EOL;
}

echo "\n--- Academic Grades ---" . PHP_EOL;
$gradeCount = AcademicGrade::count();
echo "Total Academic Grades: {$gradeCount}" . PHP_EOL;
if ($gradeCount > 0) {
    $sampleGrade = AcademicGrade::with(['student.user', 'section', 'subject'])->first();
    if ($sampleGrade) {
        echo "Sample: Student={$sampleGrade->student?->user?->name}, Section={$sampleGrade->section?->section_code}, Subject={$sampleGrade->subject?->code}, Prelim={$sampleGrade->prelim_grade}, Midterm={$sampleGrade->midterm_grade}, Final={$sampleGrade->final_grade}, Mark={$sampleGrade->mark}" . PHP_EOL;
    }
}
