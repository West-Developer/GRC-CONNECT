<?php

/**
 * One-off data-population script (owner-requested, 2026-09-28): every
 * academic term in the dev DB — including the current ongoing term — had
 * `enrollment_platform` unset, so every student's COR showed "Not
 * specified" for Platform. Per D2, this is a term-level field the Registrar
 * Head sets once for the whole enrolling population; it had simply never
 * been used yet.
 *
 * Owner-confirmed values (AskUserQuestion, 2026-09-28): Face-to-Face for the
 * current ongoing term (the real, current answer), and Face-to-Face for
 * every archived term too (explicitly acknowledged as a filler default for
 * historical terms that predate the online option, not a recovered record —
 * same spirit as the room/schedule backfill).
 *
 * Run: php scripts/backfill_academic_term_platform.php
 */

use App\Domain\Enrollment\EnrollmentPlatform;
use App\Models\AcademicTerm;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$updated = AcademicTerm::whereNull('enrollment_platform')->update([
    'enrollment_platform' => EnrollmentPlatform::FaceToFace,
]);

echo "Terms updated: {$updated}\n";

foreach (AcademicTerm::orderByDesc('id')->get(['id', 'school_year', 'semester', 'status', 'enrollment_platform']) as $t) {
    echo "  Term {$t->id} {$t->school_year} {$t->semester} {$t->status->value}: platform=".($t->enrollment_platform?->value ?? 'NULL')."\n";
}
