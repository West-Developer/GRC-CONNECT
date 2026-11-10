<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Actions\Academic\ListFacultyGradeSections;
use App\Http\Resources\Api\V1\SectionGradeSummaryResource;

$user = User::where('email', 'faculty.seed@grc.test')->first();
$sections = app(ListFacultyGradeSections::class)->execute($user);
$resource = SectionGradeSummaryResource::collection($sections);
$json = $resource->response()->getContent();
file_put_contents(__DIR__ . '/../../frontend/scripts/test_faculty_payload.json', $json);
echo "Wrote " . strlen($json) . " bytes to test_faculty_payload.json" . PHP_EOL;

