<?php

namespace App\Actions\Curriculum;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\SubjectOffering;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class ReplaceSubjectOfferings
{
    private const SNAPSHOT_FIELDS = [
        'subject_id', 'year_level', 'semester',
        'min_section_capacity', 'max_section_capacity', 'recommended_sections',
    ];

    public function __construct(
        private readonly AuditRecorder $auditRecorder,
    ) {}

    /**
     * @param  list<array{subject_id: int, year_level: int, semester: string, min_section_capacity: int, max_section_capacity: int, recommended_sections: int}>  $offerings
     * @return Collection<int, SubjectOffering>
     */
    public function execute(
        User $actor,
        int $academicTermId,
        int $curriculumId,
        array $offerings,
        AuditRequestContext $context,
    ): Collection {
        return DB::transaction(function () use ($actor, $academicTermId, $curriculumId, $offerings, $context): Collection {
            $existing = SubjectOffering::query()
                ->where('academic_term_id', $academicTermId)
                ->where('curriculum_id', $curriculumId)
                ->get();

            $beforeValues = $this->snapshot($academicTermId, $curriculumId, $existing);

            $existing->each(fn (SubjectOffering $offering) => $offering->delete());

            $created = new Collection;

            foreach ($offerings as $offering) {
                $created->push(SubjectOffering::create([
                    'academic_term_id' => $academicTermId,
                    'curriculum_id' => $curriculumId,
                    ...$offering,
                ]));
            }

            $afterValues = $this->snapshot($academicTermId, $curriculumId, $created);

            $this->auditRecorder->record(
                $actor,
                AuditAction::SUBJECT_OFFERINGS_REPLACED,
                AuditableType::SUBJECT_OFFERING,
                null,
                $beforeValues,
                $afterValues,
                null,
                $context,
            );

            return $created;
        });
    }

    /**
     * @param  Collection<int, SubjectOffering>  $offerings
     * @return array{academic_term_id: int, curriculum_id: int, offerings: array<int, array<string, int|string>>}
     */
    private function snapshot(int $academicTermId, int $curriculumId, Collection $offerings): array
    {
        return [
            'academic_term_id' => $academicTermId,
            'curriculum_id' => $curriculumId,
            'offerings' => $offerings->map(
                fn (SubjectOffering $offering): array => $offering->only(self::SNAPSHOT_FIELDS),
            )->values()->all(),
        ];
    }
}
