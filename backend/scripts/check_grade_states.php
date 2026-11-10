<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Section;
use App\Models\AcademicGrade;
use App\Actions\Academic\BuildSectionGradeSummary;

$user = User::where('email', 'faculty.seed@grc.test')->first();
$sections = Section::query()->visibleTo($user)->with(['subject', 'academicTerm'])->get();

$summaryBuilder = app(BuildSectionGradeSummary::class);

echo "Total sections for {$user->email}: " . $sections->count() . PHP_EOL;

foreach ($sections as $s) {
    $summary = $summaryBuilder->execute($s);
    echo "Section {$s->id} ({$s->section_code} - {$s->subject->code}): Enrolled={$summary['enrolled_count']}, Recorded={$summary['recorded_count']}, State={$summary['state']}" . PHP_EOL;
}

