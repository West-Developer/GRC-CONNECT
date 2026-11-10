<?php

namespace App\Console\Commands;

use App\Actions\Enrollment\BackfillCertificatesOfRegistration;
use Illuminate\Console\Command;

final class BackfillCertificatesOfRegistrationCommand extends Command
{
    protected $signature = 'cor:backfill {--enrollment-id=}';

    protected $description = 'Create or complete immutable COR snapshots for paid enrolled records.';

    public function handle(BackfillCertificatesOfRegistration $backfill): int
    {
        $id = $this->option('enrollment-id');
        $changed = $backfill->execute($id === null ? null : (int) $id);
        $this->components->info("COR records changed: {$changed}");

        return self::SUCCESS;
    }
}
