<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Actions\Academic\ListFacultyGradeSections;
use App\Http\Resources\Api\V1\SectionGradeSummaryResource;

$user = User::where('email', 'faculty.seed@grc.test')->first();
$action = app(ListFacultyGradeSections::class);
$sections = $action->execute($user);

echo "Sections count from ListFacultyGradeSections: " . $sections->count() . PHP_EOL;

if ($sections->count() > 0) {
    $resource = SectionGradeSummaryResource::collection($sections);
    $json = $resource->response()->getContent();
    echo "Sample JSON:\n" . substr($json, 0, 500) . "..." . PHP_EOL;
}

