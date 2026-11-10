<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$irregulars = \App\Models\StudentProfile::where('enrollment_category', 'irregular')->get();
$restored = 0;
foreach ($irregulars as $s) {
    if ($s->student_type === 'transferee') {
        continue;
    }
    $hasFail = \App\Models\AcademicGrade::where('student_id', $s->id)
        ->where('status', \App\Domain\Academic\GradeStatus::Locked)
        ->whereNotNull('mark')
        ->get()
        ->contains(fn($g) => !($g->mark?->isPassing() ?? true));
    
    if (!$hasFail) {
        $s->update([
            'enrollment_category' => 'regular',
            'enrollment_category_derived_at' => now(),
        ]);
        $restored++;
    }
}
echo "Restored {$restored} regular students who had no failing grades.\n";

