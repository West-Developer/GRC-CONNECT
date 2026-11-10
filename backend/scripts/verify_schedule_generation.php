<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\ScheduleGenerationRun;
use App\Models\PredictionRun;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\V1\ScheduleGenerationRunController;
use App\Actions\Scheduling\BuildFacultyLoadReport;

$term = AcademicTerm::where('status', 'semester_ongoing')->first();
$chair = User::where('email', 'chair.ccs@grc.test')->first();
auth()->login($chair);

echo "========================================================\n";
echo "TEST 1: Schedule Generation with Machine Learning WORKING\n";
echo "========================================================\n";

// Ensure clean start
Section::where('academic_term_id', $term->id)->delete();
ScheduleGenerationRun::where('academic_term_id', $term->id)->delete();

$controller = app(ScheduleGenerationRunController::class);
$request = Request::create("/api/v1/academic-terms/{$term->id}/schedule-generation-runs", 'POST');
$request->setUserResolver(fn() => $chair);

$response = $controller->store($request, $term);
$data = $response->getData(true)['data'];

echo "Response Status: " . $response->getStatusCode() . "\n";
echo "Run ID: {$data['id']}\n";
echo "Run Status: {$data['status']}\n";

$run = ScheduleGenerationRun::find($data['id']);
$predictionRun = PredictionRun::find($run->prediction_run_id);
$strategy = $predictionRun->metrics['strategy'] ?? 'unknown';
$modelVersion = $predictionRun->model_version;
$secCount = Section::where('academic_term_id', $term->id)->whereHas('sectionPlan', fn($q) => $q->where('college', 'ccs'))->count();

echo "Prediction Run Strategy: {$strategy}\n";
echo "Prediction Model Version: {$modelVersion}\n";
echo "CCS Sections Generated: {$secCount}\n";
echo "Warnings Count: " . count($run->warnings ?? []) . "\n";
if (!empty($run->warnings)) {
    foreach ($run->warnings as $w) {
        echo "  - [{$w['type']}] {$w['message']}\n";
    }
}

if ($strategy !== 'random_forest') {
    echo "FAILED: Expected strategy 'random_forest', got '{$strategy}'\n";
    exit(1);
}
if ($secCount === 0) {
    echo "FAILED: Expected sections to be generated, got 0\n";
    exit(1);
}
echo "✓ TEST 1 PASSED: Random Forest model used, sections generated successfully!\n\n";

echo "============================================================\n";
echo "TEST 2: Schedule Generation with Machine Learning NOT WORKING\n";
echo "============================================================\n";

// Reset for test 2
Section::where('academic_term_id', $term->id)->delete();
ScheduleGenerationRun::where('academic_term_id', $term->id)->delete();

// Point prediction service to non-existent port to simulate ML service failure
config(['services.prediction.base_url' => 'http://127.0.0.1:59999']);
config(['services.prediction.timeout' => 1]);

$request2 = Request::create("/api/v1/academic-terms/{$term->id}/schedule-generation-runs", 'POST');
$request2->setUserResolver(fn() => $chair);

$response2 = $controller->store($request2, $term);
$data2 = $response2->getData(true)['data'];

echo "Response Status: " . $response2->getStatusCode() . "\n";
echo "Run ID: {$data2['id']}\n";
echo "Run Status: {$data2['status']}\n";

$run2 = ScheduleGenerationRun::find($data2['id']);
$predictionRun2 = PredictionRun::find($run2->prediction_run_id);
$strategy2 = $predictionRun2->metrics['strategy'] ?? 'unknown';
$modelVersion2 = $predictionRun2->model_version;
$secCount2 = Section::where('academic_term_id', $term->id)->whereHas('sectionPlan', fn($q) => $q->where('college', 'ccs'))->count();

echo "Prediction Run Strategy: {$strategy2}\n";
echo "Prediction Model Version: {$modelVersion2}\n";
echo "CCS Sections Generated: {$secCount2}\n";
echo "Warnings Count: " . count($run2->warnings ?? []) . "\n";
if (!empty($run2->warnings)) {
    foreach ($run2->warnings as $w) {
        echo "  - [{$w['type']}] {$w['message']}\n";
    }
}

if ($strategy2 !== 'historical_baseline') {
    echo "FAILED: Expected strategy 'historical_baseline', got '{$strategy2}'\n";
    exit(1);
}
if ($secCount2 === 0) {
    echo "FAILED: Expected sections to be generated with fallback, got 0\n";
    exit(1);
}
echo "✓ TEST 2 PASSED: Historical Baseline used when ML is not working, sections generated successfully!\n\n";

// Reset state after test
Section::where('academic_term_id', $term->id)->delete();
ScheduleGenerationRun::where('academic_term_id', $term->id)->delete();
App\Models\AcademicTermSectionPlan::where('academic_term_id', $term->id)->update([
    'status' => 'draft',
    'submitted_by' => null,
    'submitted_at' => null,
    'recommendation_source' => 'predictive',
    'recommendation_is_overridden' => false,
]);
echo "✓ State restored to clean draft baseline.\n";
