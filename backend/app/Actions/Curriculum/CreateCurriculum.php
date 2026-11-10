<?php

namespace App\Actions\Curriculum;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Curriculum\CurriculumStatus;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\CurriculumAuditSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateCurriculum
{
    private const RESOURCE_RELATIONS = [
        'equivalencySourceCurriculum',
        'targetEquivalencies.sourceSubject',
        'subjectPlacements.subject',
        'subjectPlacements.prerequisites.prerequisiteSubject',
    ];

    public function __construct(
        private readonly SynchronizeCurriculumSubjects $synchronizer,
        private readonly ResolveCurriculumEffectiveSchoolYear $effectiveSchoolYearResolver,
        private readonly CurriculumAuditSnapshot $snapshot,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    /**
     * @param  array{program_id: int, equivalency_source_curriculum_id?: ?int, name: string}  $validatedData
     * @param  list<array{subject_id: int, year_level: int, semester: string, is_required: bool, prerequisites: list<array{prerequisite_subject_id: int, minimum_grade: string}>}>  $subjects
     */
    public function execute(
        User $actor,
        array $validatedData,
        array $subjects,
        AuditRequestContext $context,
    ): Curriculum {
        return DB::transaction(function () use ($actor, $validatedData, $subjects, $context): Curriculum {
            $program = Program::query()->findOrFail($validatedData['program_id']);
            $sourceId = $validatedData['equivalency_source_curriculum_id'] ?? null;
            $source = $sourceId === null ? null : Curriculum::query()
                ->whereKey($sourceId)->lockForUpdate()->firstOrFail();

            if ($source !== null && ($source->program_id !== $program->id || ! in_array($source->status, [CurriculumStatus::Active, CurriculumStatus::Archived], true))) {
                throw ValidationException::withMessages([
                    'equivalency_source_curriculum_id' => 'Select an active or archived curriculum from the same program.',
                ]);
            }

            $curriculum = Curriculum::create([
                'program_id' => $validatedData['program_id'],
                'equivalency_source_curriculum_id' => $source?->id,
                'name' => $validatedData['name'],
                'effective_school_year' => $this->effectiveSchoolYearResolver->execute(),
                'status' => CurriculumStatus::Draft,
            ]);

            $this->synchronizer->execute($curriculum, $subjects);
            $curriculum->refresh();
            $afterValues = $this->snapshot->capture($curriculum);

            $this->auditRecorder->record(
                $actor,
                AuditAction::CURRICULUM_CREATED,
                AuditableType::CURRICULUM,
                $curriculum->id,
                null,
                $afterValues,
                null,
                $context,
            );

            return $curriculum->load(self::RESOURCE_RELATIONS);
        });
    }
}
