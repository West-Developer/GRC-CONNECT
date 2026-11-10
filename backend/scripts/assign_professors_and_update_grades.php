<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\AcademicGrade;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Facades\DB;

echo "=== Assign Professors to Sections & Update Grades ===\n\n";

const TERM_ID = 34;

// 1. Get faculty pool by college
$facultyByCollege = [];
foreach (['cbae', 'ccs', 'coe', 'coa'] as $college) {
    $facultyByCollege[$college] = User::where('role', 'faculty')
        ->where('college', $college)
        ->get();
}

$facultySeed = User::where('email', 'faculty.seed@grc.test')->first();

// 2. Find all sections in term 34 that have enrolled students
$sections = Section::where('academic_term_id', TERM_ID)
    ->whereHas('enrollmentSubjects.enrollment', fn($q) => $q->where('status', 'enrolled'))
    ->with(['subject', 'professor'])
    ->get();

echo "Found {$sections->count()} active sections with enrolled students.\n";

$assignedCount = 0;
$counters = [];

foreach ($sections as $section) {
    $college = strtolower($section->subject->college?->value ?? $section->subject->college ?? 'ccs');
    $pool = $facultyByCollege[$college] ?? collect([$facultySeed]);
    
    if ($pool->isEmpty()) {
        $pool = collect([$facultySeed]);
    }

    if (! $section->professor_id) {
        $idx = ($counters[$college] ?? 0) % $pool->count();
        $prof = $pool[$idx];
        $counters[$college] = $idx + 1;

        $section->update(['professor_id' => $prof->id]);
        echo "Assigned {$prof->name} ({$prof->email}) to {$section->section_code} - {$section->subject->code}\n";
        $assignedCount++;
    }

    // Now update all academic grades for this section so encoded_by is the professor
    if ($section->professor_id) {
        AcademicGrade::where('section_id', $section->id)
            ->where('academic_term_id', TERM_ID)
            ->update([
                'encoded_by' => $section->professor_id,
                'status' => 'locked',
                'submitted_at' => DB::raw('COALESCE(submitted_at, NOW())'),
                'locked_at' => DB::raw('COALESCE(locked_at, NOW())'),
            ]);
    }
}

echo "\nAssigned professors to {$assignedCount} previously unassigned sections.\n";
echo "Updated all academic grades to be encoded and submitted by the section professors.\n";

