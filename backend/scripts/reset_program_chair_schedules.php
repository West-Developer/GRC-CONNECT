<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\AcademicGrade;
use App\Models\EnrollmentSubject;
use App\Models\AcademicTermSectionPlan;
use App\Models\ScheduleProposal;
use App\Models\ScheduleGenerationRun;
use Illuminate\Support\Facades\DB;

DB::transaction(function () {
    // 1. Ensure Term 9 is the active semester_ongoing term
    AcademicTerm::where('id', '!=', 9)->where('status', 'semester_ongoing')->update(['status' => 'closed']);
    AcademicTerm::where('id', 9)->update(['status' => 'semester_ongoing']);

    // 2. Find all sections in Term 9
    $term9SecIds = Section::where('academic_term_id', 9)->pluck('id');
    echo "Found " . $term9SecIds->count() . " sections in Term 9." . PHP_EOL;

    if ($term9SecIds->isNotEmpty()) {
        // Delete dependent grades and enrollment subjects for these draft sections
        $deletedGrades = AcademicGrade::whereIn('section_id', $term9SecIds)->delete();
        echo "Deleted {$deletedGrades} academic grades in Term 9 draft sections." . PHP_EOL;

        $deletedEnrollmentSubjects = EnrollmentSubject::whereIn('section_id', $term9SecIds)->delete();
        echo "Deleted {$deletedEnrollmentSubjects} enrollment subjects in Term 9 draft sections." . PHP_EOL;

        $deletedSections = Section::whereIn('id', $term9SecIds)->delete();
        echo "Deleted {$deletedSections} sections in Term 9." . PHP_EOL;
    }

    // 3. Clear any schedule proposals for Term 9
    $deletedProposals = ScheduleProposal::where('academic_term_id', 9)->delete();
    echo "Deleted {$deletedProposals} schedule proposals for Term 9." . PHP_EOL;

    // 4. Clear any schedule generation runs for Term 9
    $deletedRuns = ScheduleGenerationRun::where('academic_term_id', 9)->delete();
    echo "Deleted {$deletedRuns} schedule generation runs for Term 9." . PHP_EOL;

    // 5. Reset section plans for Term 9 to status 'draft' and clean state
    $resetPlans = AcademicTermSectionPlan::where('academic_term_id', 9)->update([
        'status' => 'draft',
        'submitted_by' => null,
        'submitted_at' => null,
        'recommendation_source' => 'predictive',
        'recommendation_is_overridden' => false,
    ]);
    echo "Reset {$resetPlans} section plans for Term 9 to clean draft status." . PHP_EOL;
});

echo "\n--- VERIFICATION OF FINAL STATE ---" . PHP_EOL;
$term = AcademicTerm::find(9);
echo "Active Term: ID={$term->id} | {$term->school_year} - {$term->semester} | Status: {$term->status->value}" . PHP_EOL;
echo "Sections in Term 9: " . Section::where('academic_term_id', 9)->count() . PHP_EOL;
echo "Schedule Proposals in Term 9: " . ScheduleProposal::where('academic_term_id', 9)->count() . PHP_EOL;
echo "Schedule Generation Runs in Term 9: " . ScheduleGenerationRun::where('academic_term_id', 9)->count() . PHP_EOL;

