<?php

namespace App\Actions\Organization;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\EnrollmentAudience;
use App\Domain\Organization\AcademicTermCollegeWorkflowStage;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Models\AcademicTerm;
use App\Models\AcademicTermCollegeWorkflow;
use App\Models\AcademicTermEnrollmentWindow;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateAcademicTerm
{
    private const AUDIT_FIELDS = [
        'school_year', 'semester', 'starts_at', 'ends_at',
        'enrollment_opens_at', 'enrollment_closes_at',
        'add_drop_deadline_at', 'grading_deadline_at', 'status',
    ];

    public function __construct(
        private readonly AuditRecorder $auditRecorder,
    ) {}

    /**
     * The three date fields are nullable: a term created at the end of the
     * previous cycle (`ArchiveAndCreateNextTerm`) carries only a school
     * year and semester, and the Registrar sets its enrollment dates
     * afterwards on the enrollment schedule card. The columns have always
     * been nullable; only this contract assumed otherwise.
     *
     * @param  array{school_year: string, semester: string, enrollment_opens_at?: ?string, enrollment_closes_at?: ?string, add_drop_deadline_at?: ?string}  $validatedData
     */
    public function execute(User $actor, array $validatedData, AuditRequestContext $context): AcademicTerm
    {
        return DB::transaction(function () use ($actor, $validatedData, $context): AcademicTerm {
            $currentSlot = DB::table('academic_term_current_slots')
                ->where('id', 1)
                ->lockForUpdate()
                ->first();

            if ($currentSlot?->academic_term_id !== null) {
                throw ValidationException::withMessages([
                    'school_year' => 'Archive the current semester before creating a new school year and semester.',
                ]);
            }

            $existing = AcademicTerm::query()
                ->where('school_year', $validatedData['school_year'])
                ->where('semester', $validatedData['semester'])
                ->first();

            if ($existing) {
                $existing->update([
                    'status' => AcademicTermStatus::Draft,
                    'enrollment_opens_at' => $validatedData['enrollment_opens_at'] ?? null,
                    'enrollment_closes_at' => $validatedData['enrollment_closes_at'] ?? null,
                    'add_drop_opens_at' => null,
                    'add_drop_deadline_at' => $validatedData['add_drop_deadline_at'] ?? null,
                ]);
                $term = $existing->refresh();
            } else {
                $term = AcademicTerm::create([
                    ...$validatedData,
                    'status' => AcademicTermStatus::Draft,
                ]);
            }

            DB::table('academic_term_current_slots')
                ->where('id', 1)
                ->update(['academic_term_id' => $term->id, 'updated_at' => now()]);

            foreach (CollegeCode::cases() as $college) {
                AcademicTermCollegeWorkflow::firstOrCreate([
                    'academic_term_id' => $term->id,
                    'college' => $college,
                ], [
                    'stage' => AcademicTermCollegeWorkflowStage::Draft,
                ]);
            }

            // One row per EnrollmentAudience (year_1..4, irregular),
            // defaulting to the term-wide enrollment window so the term
            // stays fully functional even if the Registrar never customizes
            // a specific audience's schedule.
            foreach (EnrollmentAudience::cases() as $audience) {
                AcademicTermEnrollmentWindow::firstOrCreate([
                    'academic_term_id' => $term->id,
                    'audience' => $audience,
                ], [
                    'opens_at' => $term->enrollment_opens_at,
                    'closes_at' => $term->enrollment_closes_at,
                ]);
            }

            $this->auditRecorder->record(
                $actor,
                AuditAction::ACADEMIC_TERM_CREATED,
                AuditableType::ACADEMIC_TERM,
                $term->id,
                null,
                $term->only(self::AUDIT_FIELDS),
                null,
                $context,
            );

            return $term;
        });
    }
}
