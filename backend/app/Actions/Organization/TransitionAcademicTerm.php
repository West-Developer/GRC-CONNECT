<?php

namespace App\Actions\Organization;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Scheduling\ScheduleProposalStatus;
use App\Models\AcademicTerm;
use App\Models\ScheduleProposal;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class TransitionAcademicTerm
{
    /** @var array<string, AcademicTermStatus> */
    private const TARGET_STATUS = [
        'open_enrollment' => AcademicTermStatus::SemesterOngoing,
        'close' => AcademicTermStatus::SemesterClosed,
        'archive' => AcademicTermStatus::Archived,
    ];

    /** @var array<string, AcademicTermStatus> */
    private const REQUIRED_STATUS = [
        'open_enrollment' => AcademicTermStatus::Draft,
        'close' => AcademicTermStatus::SemesterOngoing,
        'archive' => AcademicTermStatus::SemesterClosed,
    ];

    /** @var array<string, string> */
    private const AUDIT_ACTION = [
        'open_enrollment' => AuditAction::ACADEMIC_TERM_ENROLLMENT_OPENED,
        'close' => AuditAction::ACADEMIC_TERM_CLOSED,
        'archive' => AuditAction::ACADEMIC_TERM_ARCHIVED,
    ];

    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function execute(
        AcademicTerm $term,
        string $action,
        User $actor,
        AuditRequestContext $context,
    ): AcademicTerm {
        if (! isset(self::TARGET_STATUS[$action])) {
            throw new InvalidArgumentException('Unknown academic-term transition.');
        }

        return DB::transaction(function () use ($term, $action, $actor, $context): AcademicTerm {
            $lockedTerm = AcademicTerm::query()->whereKey($term->id)->lockForUpdate()->firstOrFail();
            $target = self::TARGET_STATUS[$action];

            if ($lockedTerm->status === $target) {
                return $lockedTerm;
            }

            $required = self::REQUIRED_STATUS[$action];
            $archiveFromOngoing = $action === 'archive'
                && $lockedTerm->status === AcademicTermStatus::SemesterOngoing;
            $openFromForDeanApproval = $action === 'open_enrollment'
                && $lockedTerm->status === AcademicTermStatus::ForDeanApproval;

            if (
                $lockedTerm->status !== $required
                && ! $archiveFromOngoing
                && ! $openFromForDeanApproval
            ) {
                throw ValidationException::withMessages([
                    'action' => "This action requires the term to currently be '{$required->value}'; "
                        ."it is currently '{$lockedTerm->status->value}'.",
                ]);
            }

            if ($action === 'open_enrollment') {
                $hasPublishedSchedule = ScheduleProposal::query()
                    ->where('academic_term_id', $lockedTerm->id)
                    ->where('status', ScheduleProposalStatus::Published->value)
                    ->exists();

                if (! $hasPublishedSchedule) {
                    throw ValidationException::withMessages([
                        'action' => 'At least one college must publish its schedule before enrollment can open.',
                    ]);
                }
            }

            $before = self::snapshot($lockedTerm);
            $updates = ['status' => $target];

            if ($action === 'close') {
                $updates['closed_at'] = now();
            } elseif ($action === 'archive') {
                if ($lockedTerm->closed_at === null) {
                    $updates['closed_at'] = now();
                }
                $updates['archived_at'] = now();
                DB::table('academic_term_current_slots')
                    ->where('id', 1)
                    ->where('academic_term_id', $lockedTerm->id)
                    ->update(['academic_term_id' => null, 'updated_at' => now()]);
            }

            $lockedTerm->update($updates);
            $lockedTerm->refresh();

            $this->auditRecorder->record(
                $actor,
                self::AUDIT_ACTION[$action],
                AuditableType::ACADEMIC_TERM,
                $lockedTerm->id,
                $before,
                self::snapshot($lockedTerm),
                null,
                $context,
            );

            return $lockedTerm;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(AcademicTerm $term): array
    {
        return [
            'school_year' => $term->school_year,
            'semester' => $term->semester,
            'status' => $term->status->value,
            'closed_at' => $term->closed_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'archived_at' => $term->archived_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
