<?php

namespace App\Console\Commands;

use App\Actions\Curriculum\RepairStudentCurriculumAssignments;
use Illuminate\Console\Command;

final class RepairStudentCurriculumAssignmentsCommand extends Command
{
    protected $signature = 'curricula:repair-student-assignments
        {--dry-run : Report required changes without writing student profiles}';

    protected $description = 'Assign each student to the curriculum effective for their entry year';

    public function handle(RepairStudentCurriculumAssignments $repairer): int
    {
        $result = $repairer->execute((bool) $this->option('dry-run'));

        $this->info(implode(' ', [
            "examined={$result['examined']}",
            "updated={$result['updated']}",
            "would_update={$result['would_update']}",
            "skipped_missing_entry_year={$result['skipped_missing_entry_year']}",
            "unresolved={$result['unresolved']}",
        ]));

        return self::SUCCESS;
    }
}
