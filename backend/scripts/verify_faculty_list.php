<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

$lines = file(__DIR__ . '/../../Subject And Prerequisuite/Professor_Department_List.md');
$checked = 0;
$mismatches = [];

foreach ($lines as $line) {
    if (!preg_match('/^\|\s*(.*?)\s*\|\s*([^|\s]+@[^|\s]+)\s*\|\s*(.*?)\s*\|/u', $line, $matches)) {
        continue;
    }
    $name = trim($matches[1]);
    $email = trim($matches[2]);
    $dept = trim($matches[3]);

    if ($name === 'Professor Name') {
        continue;
    }

    $checked++;
    $user = User::where('email', $email)->first();

    if (!$user) {
        $mismatches[] = "User not found for email: $email ($name)";
        continue;
    }

    if ($user->name !== $name) {
        $mismatches[] = "Name mismatch for ID {$user->id}: MD '$name' vs DB '{$user->name}'";
    }

    $collegeValue = $user->college?->value ?? '';
    if (strtoupper($collegeValue) !== strtoupper($dept)) {
        $mismatches[] = "College mismatch for ID {$user->id}: MD '$dept' vs DB '$collegeValue'";
    }

    if ($user->status->value !== 'active') {
        $mismatches[] = "Status not active for ID {$user->id}: {$user->status->value}";
    }

    if (!Hash::check('password', $user->password)) {
        $mismatches[] = "Password check failed for ID {$user->id}";
    }
}

echo "Total checked rows: $checked\n";
echo "Total mismatches: " . count($mismatches) . "\n";

if (count($mismatches) > 0) {
    print_r($mismatches);
    exit(1);
} else {
    echo "SUCCESS: ALL $checked PROFESSORS MATCH 100% IN MARIADB!\n";
}

