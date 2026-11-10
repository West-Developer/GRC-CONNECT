<?php

namespace App\Actions\Scheduling;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Scheduling\ScheduleProposalStatus;
use App\Models\AcademicTerm;
use App\Models\ScheduleProposal;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateScheduleProposal
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function execute(
        int $academicTermId,
        User $submitter,
        AuditRequestContext $context,
    ): ScheduleProposal {
        return DB::transaction(function () use ($academicTermId, $submitter, $context): ScheduleProposal {
            AcademicTerm::query()
                ->whereKey($academicTermId)
                ->lockForUpdate()
                ->firstOrFail();

            $hasActiveProposal = ScheduleProposal::query()
                ->where('academic_term_id', $academicTermId)
                ->when($submitter->college !== null, fn ($query) => $query->where('college', $submitter->college->value))
                ->where('status', '!=', ScheduleProposalStatus::Closed->value)
                ->exists();

            if ($hasActiveProposal) {
                throw ValidationException::withMessages([
                    'academic_term_id' => 'This term already has an active (non-closed) schedule proposal.',
                ]);
            }

            $proposal = ScheduleProposal::create([
                'academic_term_id' => $academicTermId,
                'submitted_by' => $submitter->id,
                'college' => $submitter->college?->value,
                'status' => ScheduleProposalStatus::Draft,
            ]);
            $proposal->refresh();

            $this->auditRecorder->record(
                $submitter,
                AuditAction::SCHEDULE_PROPOSAL_CREATED,
                AuditableType::SCHEDULE_PROPOSAL,
                $proposal->id,
                null,
                self::snapshot($proposal),
                null,
                $context,
            );

            return $proposal;
        });
    }

    /**
     * @return array{
     *     academic_term_id: int,
     *     submitted_by: int,
     *     status: string,
     *     decided_by: ?int,
     *     decided_at: ?string,
     *     decision_reason: ?string
     * }
     */
    private static function snapshot(ScheduleProposal $proposal): array
    {
        return [
            'academic_term_id' => $proposal->academic_term_id,
            'submitted_by' => $proposal->submitted_by,
            'status' => $proposal->status->value,
            'decided_by' => $proposal->decided_by,
            'decided_at' => $proposal->decided_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'decision_reason' => $proposal->decision_reason,
        ];
    }
}
