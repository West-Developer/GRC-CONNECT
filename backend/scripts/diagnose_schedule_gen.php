<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Analytics\SectionDemandPredictionClient;

$client = app(SectionDemandPredictionClient::class);

$observations = [
    ['cohort_size' => 24, 'enrolled_count' => 24, 'section_count' => 1, 'offered_capacity' => 40, 'year_level' => 1, 'semester' => '1st'],
    ['cohort_size' => 23, 'enrolled_count' => 23, 'section_count' => 1, 'offered_capacity' => 40, 'year_level' => 1, 'semester' => '1st'],
    ['cohort_size' => 25, 'enrolled_count' => 25, 'section_count' => 1, 'offered_capacity' => 40, 'year_level' => 1, 'semester' => '1st'],
    ['cohort_size' => 25, 'enrolled_count' => 25, 'section_count' => 1, 'offered_capacity' => 40, 'year_level' => 1, 'semester' => '1st'],
    ['cohort_size' => 230, 'enrolled_count' => 230, 'section_count' => 6, 'offered_capacity' => 240, 'year_level' => 1, 'semester' => '1st'],
    ['cohort_size' => 205, 'enrolled_count' => 205, 'section_count' => 6, 'offered_capacity' => 240, 'year_level' => 1, 'semester' => '1st'],
    ['cohort_size' => 233, 'enrolled_count' => 233, 'section_count' => 6, 'offered_capacity' => 240, 'year_level' => 1, 'semester' => '1st'],
];
$targets = [
    ['key' => '13:1', 'cohort_size' => 273, 'section_count' => 6, 'recommended_capacity' => 40, 'year_level' => 1, 'semester' => '1st'],
];

try {
    $res = $client->predict($observations, $targets);
    echo "Prediction Response:\n" . json_encode($res, JSON_PRETTY_PRINT) . "\n";
} catch (\Throwable $e) {
    echo "Prediction Error: " . $e->getMessage() . "\n";
}

