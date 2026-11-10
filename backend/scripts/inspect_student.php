<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$s = \App\Models\StudentProfile::where('student_number', '2026-06-01024')->first();
$term37 = \App\Models\AcademicTerm::find(37); // 2027-2028 1st
$classifier = app(\App\Actions\Academic\ClassifyEnrollmentStanding::class);

echo "With year_level = 1:\n";
$s->year_level = 1;
$v1 = $classifier->classify($s, $term37);
echo "  Verdict: " . ($v1 ? $v1->category->value : "null") . "\n";

echo "With year_level = 2 (promoted):\n";
$s->year_level = 2;
$v2 = $classifier->classify($s, $term37);
echo "  Verdict: " . ($v2 ? $v2->category->value : "null") . "\n";
if ($v2 && $v2->category->value === 'irregular') {
    foreach ($v2->reasons as $r) {
        echo "    Reason: {$r['code']} - {$r['message']}\n";
    }
}

