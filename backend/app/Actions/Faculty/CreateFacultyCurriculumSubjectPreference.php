<?php

namespace App\Actions\Faculty;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\FacultyCurriculumSubjectPreference;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

final class CreateFacultyCurriculumSubjectPreference
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    /** @param array{curriculum_id: int, subject_id: int, semester: string, rank?: int} $validatedData */
    public function execute(User $actor, array $validatedData, AuditRequestContext $context): FacultyCurriculumSubjectPreference
    {
        return DB::transaction(function () use ($actor, $validatedData, $context): FacultyCurriculumSubjectPreference {
            $rank = $validatedData['rank'] ?? ((int) FacultyCurriculumSubjectPreference::query()
                ->where('professor_id', $actor->id)
                ->where('curriculum_id', $validatedData['curriculum_id'])
                ->where('semester', $validatedData['semester'])
                ->max('rank') + 1);

            $preference = FacultyCurriculumSubjectPreference::create([
                'professor_id' => $actor->id,
                'curriculum_id' => $validatedData['curriculum_id'],
                'subject_id' => $validatedData['subject_id'],
                'semester' => $validatedData['semester'],
                'rank' => $rank,
                'origin' => 'declared',
            ]);
            $preference->refresh();

            $this->auditRecorder->record(
                $actor,
                AuditAction::FACULTY_CURRICULUM_SUBJECT_PREFERENCE_CREATED,
                AuditableType::FACULTY_CURRICULUM_SUBJECT_PREFERENCE,
                $preference->id,
                null,
                self::snapshot($preference),
                null,
                $context,
            );

            return $preference;
        });
    }

    /** @return array<string, int|string> */
    public static function snapshot(FacultyCurriculumSubjectPreference $preference): array
    {
        return [
            'professor_id' => $preference->professor_id,
            'curriculum_id' => $preference->curriculum_id,
            'subject_id' => $preference->subject_id,
            'semester' => $preference->semester,
            'rank' => $preference->rank,
            'origin' => $preference->origin,
        ];
    }
}
