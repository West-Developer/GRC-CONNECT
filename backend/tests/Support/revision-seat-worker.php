<?php

use App\Actions\Enrollment\ReviseEnrollmentSubjects;
use App\Domain\Audit\AuditRequestContext;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $enrollmentId, $sectionId, $actorId, $ready, $barrier] = $argv;
file_put_contents($ready, 'ready');
$deadline = microtime(true) + 20;
while (! is_file($barrier)) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, 'Barrier timed out');
        exit(1);
    }
    usleep(10000);
    clearstatcache(true, $barrier);
}
try {
    $app->make(ReviseEnrollmentSubjects::class)->execute(
        Enrollment::query()->findOrFail((int) $enrollmentId), [(int) $sectionId],
        User::query()->findOrFail((int) $actorId), new AuditRequestContext('test-seat-race', null), 'Replace my held section.',
    );
    echo 'accepted';
} catch (ValidationException) {
    echo 'unavailable';
}
