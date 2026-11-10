<?php

namespace App\Actions\Organization;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Organization\AcademicTermCollegeWorkflowStage;
use App\Models\AcademicTermCollegeWorkflow;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class TransitionCollegeWorkflow
{
    /** @var array<string, AcademicTermCollegeWorkflowStage> */
    private const TARGET_STAGE = [
        'start_curriculum_preparation' => AcademicTermCollegeWorkflowStage::CurriculumPreparation,
        'complete_curriculum_preparation' => AcademicTermCollegeWorkflowStage::FacultyInput,
        'complete_faculty_input' => AcademicTermCollegeWorkflowStage::SchedulePreparation,
    ];

    /** @var array<string, AcademicTermCollegeWorkflowStage> */
    private const REQUIRED_STAGE = [
        'start_curriculum_preparation' => AcademicTermCollegeWorkflowStage::Draft,
        'complete_curriculum_preparation' => AcademicTermCollegeWorkflowStage::CurriculumPreparation,
        'complete_faculty_input' => AcademicTermCollegeWorkflowStage::FacultyInput,
    ];

    /** @var array<string, string> */
    private const AUDIT_ACTION = [
        'start_curriculum_preparation' => AuditAction::ACADEMIC_TERM_WORKFLOW_CURRICULUM_STARTED,
        'complete_curriculum_preparation' => AuditAction::ACADEMIC_TERM_WORKFLOW_CURRICULUM_COMPLETED,
        'complete_faculty_input' => AuditAction::ACADEMIC_TERM_WORKFLOW_FACULTY_REVIEWED,
    ];

    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function execute(
        AcademicTermCollegeWorkflow $workflow,
        string $action,
        User $actor,
        AuditRequestContext $context,
    ): AcademicTermCollegeWorkflow {
        if (! isset(self::TARGET_STAGE[$action])) {
            throw new InvalidArgumentException('Unknown academic-term workflow transition.');
        }

        return DB::transaction(function () use ($workflow, $action, $actor, $context): AcademicTermCollegeWorkflow {
            $locked = AcademicTermCollegeWorkflow::query()
                ->whereKey($workflow->id)
                ->lockForUpdate()
                ->firstOrFail();
            $required = self::REQUIRED_STAGE[$action];

            if ($locked->stage !== $required) {
                throw ValidationException::withMessages([
                    'action' => "This action requires the workflow to currently be '{$required->value}'; "
                        ."it is currently '{$locked->stage->value}'.",
                ]);
            }

            $before = self::snapshot($locked);
            $now = now();
            $updates = ['stage' => self::TARGET_STAGE[$action]];

            if ($action === 'complete_curriculum_preparation') {
                $updates['curriculum_completed_by'] = $actor->id;
                $updates['curriculum_completed_at'] = $now;
            }

            if ($action === 'complete_faculty_input') {
                $updates['faculty_reviewed_by'] = $actor->id;
                $updates['faculty_reviewed_at'] = $now;
            }

            $locked->update($updates);
            $locked->refresh();

            $this->auditRecorder->record(
                $actor,
                self::AUDIT_ACTION[$action],
                AuditableType::ACADEMIC_TERM_WORKFLOW,
                $locked->id,
                $before,
                self::snapshot($locked),
                null,
                $context,
            );

            return $locked;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(AcademicTermCollegeWorkflow $workflow): array
    {
        return [
            'academic_term_id' => $workflow->academic_term_id,
            'college' => $workflow->college->value,
            'stage' => $workflow->stage->value,
            'curriculum_completed_by' => $workflow->curriculum_completed_by,
            'curriculum_completed_at' => $workflow->curriculum_completed_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'faculty_reviewed_by' => $workflow->faculty_reviewed_by,
            'faculty_reviewed_at' => $workflow->faculty_reviewed_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'schedule_submitted_by' => $workflow->schedule_submitted_by,
            'schedule_submitted_at' => $workflow->schedule_submitted_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
