<?php

namespace App\Actions\Enrollment;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\PreferredTimeBlock;
use App\Models\StudentProfile;
use App\Models\StudentSchedulePreference;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

final class SaveStudentSchedulePreference
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    /**
     * @param  array{
     *     preferred_days?: ?list<int>,
     *     preferred_time_block?: string,
     *     preferred_modality?: ?string,
     *     max_days_on_campus?: ?int,
     *     avoid_early_first_class?: bool,
     *     notes?: ?string,
     * }  $validatedData
     */
    public function execute(
        StudentProfile $student,
        User $actor,
        array $validatedData,
        AuditRequestContext $context,
    ): StudentSchedulePreference {
        return DB::transaction(function () use ($student, $actor, $validatedData, $context): StudentSchedulePreference {
            $existing = StudentSchedulePreference::query()->where('student_id', $student->id)->first();
            $before = $existing !== null ? self::snapshot($existing) : null;

            $preference = StudentSchedulePreference::query()->updateOrCreate(
                ['student_id' => $student->id],
                [
                    'preferred_days' => $validatedData['preferred_days'] ?? null,
                    'preferred_time_block' => $validatedData['preferred_time_block'] ?? PreferredTimeBlock::Any->value,
                    'preferred_modality' => $validatedData['preferred_modality'] ?? null,
                    'max_days_on_campus' => $validatedData['max_days_on_campus'] ?? null,
                    'avoid_early_first_class' => $validatedData['avoid_early_first_class'] ?? false,
                    'notes' => $validatedData['notes'] ?? null,
                ],
            );
            $preference->refresh();

            $this->auditRecorder->record(
                $actor,
                AuditAction::STUDENT_SCHEDULE_PREFERENCE_SAVED,
                AuditableType::STUDENT_SCHEDULE_PREFERENCE,
                $preference->id,
                $before,
                self::snapshot($preference),
                null,
                $context,
            );

            return $preference;
        });
    }

    /**
     * @return array{
     *     student_id: int,
     *     preferred_days: ?array<int, int>,
     *     preferred_time_block: string,
     *     preferred_modality: ?string,
     *     max_days_on_campus: ?int,
     *     avoid_early_first_class: bool,
     *     notes: ?string,
     * }
     */
    private static function snapshot(StudentSchedulePreference $preference): array
    {
        return [
            'student_id' => $preference->student_id,
            'preferred_days' => $preference->preferred_days,
            'preferred_time_block' => $preference->preferred_time_block->value,
            'preferred_modality' => $preference->preferred_modality,
            'max_days_on_campus' => $preference->max_days_on_campus,
            'avoid_early_first_class' => $preference->avoid_early_first_class,
            'notes' => $preference->notes,
        ];
    }
}
