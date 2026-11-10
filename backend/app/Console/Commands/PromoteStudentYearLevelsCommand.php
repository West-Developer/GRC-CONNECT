<?php

namespace App\Console\Commands;

use App\Actions\Academic\PromoteEligibleStudents;
use App\Domain\Audit\AuditRequestContext;
use App\Models\User;
use Illuminate\Console\Command;

final class PromoteStudentYearLevelsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'academic:promote-year-levels
                            {--program= : Limit promotion to a specific program ID}
                            {--dry-run : Only preview which students qualify for promotion without modifying database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Evaluate locked academic grades and promote eligible students to the next year level.';

    public function handle(PromoteEligibleStudents $promoter): int
    {
        $programOption = $this->option('program');
        $programId = $programOption !== null ? (int) $programOption : null;
        $dryRun = (bool) $this->option('dry-run');
        $systemUser = User::query()->first();
        $context = new AuditRequestContext('console:academic:promote-year-levels', null);

        $this->info($dryRun
            ? 'Evaluating eligible students for year-level promotion (DRY RUN)...'
            : 'Promoting eligible students to their next year level...');

        $result = $promoter->execute(
            programId: $programId,
            actor: $systemUser,
            context: $context,
            dryRun: $dryRun,
        );

        $count = $result['promoted_count'];

        if ($count === 0) {
            $this->info('No students currently eligible for year-level promotion.');

            return self::SUCCESS;
        }

        $headers = ['Student ID', 'Student Number', 'Old Year Level', 'New Year Level', 'Back Subjects'];
        $rows = array_map(fn ($p) => [
            $p['student_id'],
            $p['student_number'],
            $p['old_year_level'],
            $p['new_year_level'],
            $p['back_subject_codes'] === [] ? '-' : implode(', ', $p['back_subject_codes']),
        ], $result['promoted']);

        $this->table($headers, $rows);
        $this->info("Successfully processed {$count} student promotion(s).");

        return self::SUCCESS;
    }
}
