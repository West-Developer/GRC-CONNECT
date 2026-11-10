<?php

namespace App\Actions\Curriculum;

use App\Domain\Curriculum\CurriculumVersion;
use App\Models\Curriculum;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles each student's stored curriculum to the immutable version that
 * applied to their entry year. Profiles without an entry year are never
 * guessed at: they are reported as skipped for a data steward to repair.
 */
final class RepairStudentCurriculumAssignments
{
    /**
     * @return array{examined: int, updated: int, would_update: int, skipped_missing_entry_year: int, unresolved: int}
     */
    public function execute(bool $dryRun = false): array
    {
        /** @var Collection<int, Collection<int, Curriculum>> $curriculaByProgram */
        $curriculaByProgram = Curriculum::query()
            ->orderByDesc('effective_start_year')
            ->get()
            ->groupBy('program_id');

        $result = [
            'examined' => 0,
            'updated' => 0,
            'would_update' => 0,
            'skipped_missing_entry_year' => 0,
            'unresolved' => 0,
        ];

        StudentProfile::query()
            ->orderBy('id')
            ->chunkById(250, function (Collection $profiles) use ($curriculaByProgram, $dryRun, &$result): void {
                foreach ($profiles as $profile) {
                    $result['examined']++;
                    if ($profile->entry_year === null) {
                        $result['skipped_missing_entry_year']++;

                        continue;
                    }

                    /** @var Collection<int, Curriculum> $programCurricula */
                    $programCurricula = $curriculaByProgram->get($profile->program_id, collect());
                    $resolved = CurriculumVersion::resolveForEntryYear($programCurricula, $profile->entry_year);
                    if ($resolved === null) {
                        $result['unresolved']++;

                        continue;
                    }

                    if ($resolved->id === $profile->curriculum_id) {
                        continue;
                    }

                    if ($dryRun) {
                        $result['would_update']++;

                        continue;
                    }

                    DB::transaction(function () use ($profile, $resolved, &$result): void {
                        $lockedProfile = StudentProfile::query()->lockForUpdate()->findOrFail($profile->id);
                        if ($lockedProfile->curriculum_id === $resolved->id) {
                            return;
                        }

                        $lockedProfile->update(['curriculum_id' => $resolved->id]);
                        $result['updated']++;
                    });
                }
            });

        return $result;
    }
}
