<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$s = \App\Models\StudentProfile::where('student_number', '2026-06-01024')->first();
$t = \App\Models\AcademicTerm::find(34);
$slip = app(\App\Actions\Academic\BuildGradeSlip::class)->execute($s, $t);
$res = (new \App\Http\Resources\Api\V1\GradeSlipResource($slip))->toArray(request());

echo "Student: {$res['student_number']}\n";
echo "Year level: {$res['year_level']}\n";
echo "Category: {$res['enrollment_category']}\n";
echo "Category label: {$res['enrollment_category_label']}\n";

