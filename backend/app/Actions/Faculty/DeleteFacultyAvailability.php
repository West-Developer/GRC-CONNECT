<?php

namespace App\Actions\Faculty;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\FacultyAvailability;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

final class DeleteFacultyAvailability
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function execute(User $actor, FacultyAvailability $availability, AuditRequestContext $context): void
    {
        DB::transaction(function () use ($actor, $availability, $context): void {
            $beforeValues = self::snapshot($availability);
            $availability->delete();

            $this->auditRecorder->record(
                $actor,
                AuditAction::FACULTY_AVAILABILITY_DELETED,
                AuditableType::FACULTY_AVAILABILITY,
                $availability->id,
                $beforeValues,
                null,
                null,
                $context,
            );
        });
    }

    /** @return array{professor_id: int, day_of_week: int, starts_at_time: string, ends_at_time: string} */
    private static function snapshot(FacultyAvailability $availability): array
    {
        return [
            'professor_id' => $availability->professor_id,
            'day_of_week' => $availability->day_of_week,
            'starts_at_time' => $availability->starts_at_time,
            'ends_at_time' => $availability->ends_at_time,
        ];
    }
}
