<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\AcademicTerm;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\V1\ScheduleGenerationRunController;
use App\Actions\Scheduling\BuildFacultyLoadReport;

$term = AcademicTerm::find(9);
$chair = User::where('email', 'chair.ccs@grc.test')->first();

auth()->setUser($chair);

$controller = app(ScheduleGenerationRunController::class);
$request = Request::create("/api/v1/academic-terms/{$term->id}/schedule-generation-runs", 'POST');
$request->setUserResolver(fn() => $chair);

try {
    $response = $controller->store($request, $term);
    echo "Store Response Status: " . $response->getStatusCode() . "\n";
    echo "Store Response Data: " . json_encode($response->getData(), JSON_PRETTY_PRINT) . "\n";
} catch (\Throwable $e) {
    echo "Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}

