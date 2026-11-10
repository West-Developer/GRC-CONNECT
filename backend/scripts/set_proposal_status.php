<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ScheduleProposal;

$id = (int) ($argv[1] ?? 11);
$status = $argv[2] ?? 'published';

$updated = ScheduleProposal::where('id', $id)->update(['status' => $status]);
echo "Updated proposal {$id} status to {$status}: {$updated}\n";

