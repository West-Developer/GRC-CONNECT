<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Section;
use App\Models\AcademicGrade;
use App\Domain\Academic\GradeStatus;

$sectionId = 1256;
$section = Section::find($sectionId);
if (!$section) {
    echo "Section {$sectionId} not found!" . PHP_EOL;
    exit(1);
}

// Update grades to draft so they can be edited in UI
$updated = AcademicGrade::where('section_id', $sectionId)->update([
    'status' => GradeStatus::Draft->value,
]);

echo "Updated {$updated} grades for Section {$sectionId} ({$section->section_code}) to status 'draft'." . PHP_EOL;

