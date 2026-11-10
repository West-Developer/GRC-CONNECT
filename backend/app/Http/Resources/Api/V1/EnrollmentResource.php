<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Identity\UserRole;
use App\Models\AssessmentItem;
use App\Models\Enrollment;
use App\Models\EnrollmentRevision;
use App\Models\EnrollmentSubject;
use App\Models\User;
use App\Support\Http\ProfessorDisclosure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Enrollment $resource
 */
final class EnrollmentResource extends JsonResource
{
    /**
     * Exact key set. No attribute is passed through implicitly.
     * `student_number` is exposed to every permitted enrollment viewer.
     * `student_name` and `student_year_level` are a narrower Registrar-only
     * exception needed to review an enrollment: Registrar Staff and Registrar
     * Head receive them, while a Student and Accounting Staff receive null.
     * `student_financial_status` is informational only
     * (`App\Domain\Identity\FinancialStatus`), never read by fee computation.
     *
     * `assessment` is nullable: every enrollment created before this slice,
     * and any created directly (not through `registrar_approve`) — as most
     * test fixtures do — has none. Every role permitted to view an
     * enrollment at all (student own-record, Registrar Head/Staff all,
     * Accounting Staff `pending_payment` only — see `Enrollment::scopeVisibleTo`)
     * is also permitted to see its assessment; there is no narrower
     * visibility boundary to enforce here.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     student_id: int,
     *     student_number: string,
     *     student_name: ?string,
     *     student_year_level: ?int,
     *     student_financial_status: ?string,
     *     student_financial_status_label: ?string,
     *     student_enrollment_category: ?string,
     *     is_irregular: bool,
     *     is_late_enrollee: bool,
     *     academic_term_id: int,
     *     status: string,
     *     status_label: string,
     *     total_units: float,
     *     requires_overload_approval: bool,
     *     regular_unit_maximum: ?float,
     *     unit_maximum: ?float,
     *     exceeds_unit_maximum: bool,
     *     program_head_comment: ?string,
     *     submitted_at: ?string,
     *     program_head_decided_at: ?string,
     *     registrar_decided_at: ?string,
     *     payment_confirmed_at: ?string,
     *     enrolled_at: ?string,
     *     subjects: list<array{
     *         section_id: int,
     *         section_code: string,
     *         subject_id: int,
     *         subject_code: string,
     *         paired_subject_id: ?int,
     *         subject_title: string,
     *         units: float,
     *         schedule_days: ?string,
     *         starts_at_time: ?string,
     *         ends_at_time: ?string,
     *         room: ?string,
     *         modality: ?string,
     *         professor_name: ?string,
     *         status: string,
     *         status_label: string
     *     }>,
     *     revisions: list<array{id: int, note: string, added_subjects: list<array<string, mixed>>, removed_subjects: list<array<string, mixed>>, units_before: float, units_after: float, status: string, student_reason: ?string, proposed_at: ?string, responded_at: ?string}>,
     *     queue_ticket: ?array{ticket_number: string, queue_date: string, status: string, status_label: string, priority: string, priority_label: string, position: ?int},
     *     assessment: ?array{
     *         total_amount: ?string,
     *         currency: string,
     *         assessed_at: string,
     *         items: list<array{id: int, category: string, category_label: string, label: string, quantity: ?string, unit_amount: ?string, amount: ?string}>
     *     }
     * }
     */
    public function toArray(Request $request): array
    {
        $queueTicket = $this->resource->queueTicket;
        $assessment = $this->resource->assessment;
        $actor = $request->user();
        $mayViewStudentContext = $actor instanceof User && in_array($actor->role, [
            UserRole::RegistrarHead,
            UserRole::RegistrarStaff,
            UserRole::Dean,
            UserRole::ExecutiveDirector,
            UserRole::ProgramChair,
        ], true);
        $student = $this->resource->student;
        $curriculum = $student->relationLoaded('curriculum') ? $student->curriculum : null;
        // A Student does not see the professor until the enrollment and add/drop
        // windows are over (ProfessorVisibility).
        $hideProfessor = ProfessorDisclosure::hiddenFrom($request, $this->resource->academic_term_id);

        return [
            'type' => 'enrollment',
            'id' => $this->resource->id,
            'student_id' => $this->resource->student_id,
            'student_number' => $student->student_number,
            'student_name' => $mayViewStudentContext ? $student->user->name : null,
            'student_year_level' => $mayViewStudentContext ? $student->year_level : null,
            'student_financial_status' => $student->financial_status?->value,
            'student_financial_status_label' => $student->financial_status?->label(),
            'student_enrollment_category' => $student->enrollment_category,
            'is_irregular' => $student->enrollment_category === 'irregular',
            'is_late_enrollee' => $this->resource->isLateEnrollee(),
            'academic_term_id' => $this->resource->academic_term_id,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'total_units' => $this->resource->total_units,
            'requires_overload_approval' => $this->resource->requires_overload_approval,
            'regular_unit_maximum' => $curriculum?->effectiveRegularUnits()
                ?? (config('enrollment.max_regular_units') !== null ? (float) config('enrollment.max_regular_units') : null),
            // The maximum the student was measured against, and whether the load is over it (an irregular student may
            // submit one after confirming; the Program Chair then corrects it).
            'unit_maximum' => $this->resource->unit_maximum
                ?? ($curriculum?->effectiveMaxUnits() ?? (config('enrollment.overload_max_units') !== null ? (float) config('enrollment.overload_max_units') : null)),
            'exceeds_unit_maximum' => $this->resource->unit_maximum !== null && $this->resource->total_units > $this->resource->unit_maximum,
            'program_head_comment' => $this->resource->program_head_comment,
            'submitted_at' => $this->resource->submitted_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'program_head_decided_at' => $this->resource->program_head_decided_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'registrar_decided_at' => $this->resource->registrar_decided_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'payment_confirmed_at' => $this->resource->payment_confirmed_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'enrolled_at' => $this->resource->enrolled_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'subjects' => array_values(
                $this->resource->enrollmentSubjects
                    ->filter(fn (EnrollmentSubject $enrollmentSubject): bool => $enrollmentSubject->status !== EnrollmentSubjectStatus::Dropped || $this->resource->status->isTerminal())
                    ->map(fn (EnrollmentSubject $enrollmentSubject): array => [
                        'section_id' => $enrollmentSubject->section_id,
                        'section_code' => $enrollmentSubject->section->section_code,
                        'subject_id' => $enrollmentSubject->section->subject_id,
                        'subject_code' => $enrollmentSubject->section->subject->code,
                        'paired_subject_id' => $enrollmentSubject->section->subject->paired_subject_id,
                        'subject_title' => $enrollmentSubject->section->subject->title,
                        'units' => (float) $enrollmentSubject->section->subject->units,
                        'schedule_days' => $enrollmentSubject->section->schedule_days,
                        'starts_at_time' => $enrollmentSubject->section->starts_at_time,
                        'ends_at_time' => $enrollmentSubject->section->ends_at_time,
                        'room' => $enrollmentSubject->section->room,
                        'modality' => $enrollmentSubject->section->modality?->value,
                        'professor_name' => $hideProfessor ? null : $enrollmentSubject->section->professor?->name,
                        'status' => $enrollmentSubject->status->value,
                        'status_label' => $enrollmentSubject->status->label(),
                    ])
                    ->all(),
            ),
            // The Program Chair's change proposals, oldest first. Empty when the relation was not
            // loaded (the listing and the action responses load it), so no caller lazy-loads.
            'revisions' => $this->resource->relationLoaded('revisions')
                ? array_values($this->resource->revisions->map(fn (EnrollmentRevision $revision): array => [
                    'id' => $revision->id,
                    'proposed_by_role' => $revision->proposed_by === null ? null : ($revision->proposed_by === $student->user_id ? 'student' : 'program_chair'),
                    'note' => $revision->note,
                    'added_subjects' => $revision->added_subjects,
                    'removed_subjects' => $revision->removed_subjects,
                    'units_before' => $revision->units_before,
                    'units_after' => $revision->units_after,
                    'status' => $revision->status,
                    'student_reason' => $revision->student_reason,
                    'proposed_at' => $revision->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                    'responded_at' => $revision->responded_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                ])->all())
                : [],
            'queue_ticket' => $queueTicket === null ? null : [
                'ticket_number' => $queueTicket->ticket_number,
                'queue_date' => $queueTicket->queue_date->toDateString(),
                'status' => $queueTicket->status->value,
                'status_label' => $queueTicket->status->label(),
                'priority' => $queueTicket->priority->value,
                'priority_label' => $queueTicket->priority->label(),
                // How many others are ahead — never the whole queue
                // (privacy). Null once the ticket has left `waiting`.
                'position' => $queueTicket->position(),
            ],
            'assessment' => $assessment === null ? null : [
                'total_amount' => $assessment->total_amount,
                'currency' => $assessment->currency,
                'assessed_at' => $assessment->assessed_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'items' => array_values(
                    $assessment->items
                        ->map(fn (AssessmentItem $item): array => [
                            'id' => $item->id,
                            'category' => $item->category->value,
                            'category_label' => $item->category->label(),
                            'label' => $item->label,
                            'quantity' => $item->quantity,
                            'unit_amount' => $item->unit_amount,
                            'amount' => $item->amount,
                        ])
                        ->all(),
                ),
            ],
        ];
    }
}
