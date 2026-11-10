<?php

namespace App\Actions\Curriculum;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Curriculum\CurriculumStatus;
use App\Models\Curriculum;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\CurriculumAuditSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateCurriculum
{
    private const RESOURCE_RELATIONS = [
        'equivalencySourceCurriculum',
        'targetEquivalencies.sourceSubject',
        'subjectPlacements.subject',
        'subjectPlacements.prerequisites.prerequisiteSubject',
    ];

    public function __construct(
        private readonly SynchronizeCurriculumSubjects $synchronizer,
        private readonly CurriculumAuditSnapshot $snapshot,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    /**
     * @param  array{name: string}  $validatedData
     * @param  list<array{subject_id: int, year_level: int, semester: string, is_required: bool, prerequisites: list<array{prerequisite_subject_id: int, minimum_grade: string}>}>  $subjects
     */
    public function execute(
        User $actor,
        array $validatedData,
        array $subjects,
        Curriculum $curriculum,
        AuditRequestContext $context,
    ): Curriculum {
        return DB::transaction(function () use ($actor, $validatedData, $subjects, $curriculum, $context): Curriculum {
            if ($curriculum->status !== CurriculumStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => 'Only a Draft curriculum can be edited.',
                ]);
            }

            $beforeValues = $this->snapshot->capture($curriculum);

            $curriculum->update([
                'name' => $validatedData['name'],
            ]);
            $this->synchronizer->execute($curriculum, $subjects);
            $curriculum->refresh();
            $afterValues = $this->snapshot->capture($curriculum);

            $this->auditRecorder->record(
                $actor,
                AuditAction::CURRICULUM_UPDATED,
                AuditableType::CURRICULUM,
                $curriculum->id,
                $beforeValues,
                $afterValues,
                null,
                $context,
            );

            return $curriculum->load(self::RESOURCE_RELATIONS);
        });
    }
}
