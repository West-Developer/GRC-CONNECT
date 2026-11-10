<?php

namespace App\Actions\Academic;

use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Models\NoCreditDeclaration;
use App\Models\StudentProfile;
use App\Models\TransfereeCredit;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records one credit: saved by the Student for themselves, or recorded by a
 * Program Chair for a student in their college (ADR 0026, amended).
 *
 * A Student's credit starts as a `draft`: it sits in their own list, grants
 * nothing, is seen by nobody else and notifies nobody until they send the whole
 * list (`SubmitTransfereeCredits`). A Program Chair's credit starts `pending`
 * at once, because the Chair is already the one who maps it. Neither is ever
 * mapped to a subject here for a Student: `subject_id` is the Program Chair's
 * decision (`SuggestCreditSubjects` only advises).
 *
 * Who the credit is for is decided here, not by the payload: a Student's
 * `student_id` is pinned to their own profile and anything they send is
 * ignored, and a Program Chair may only name a student in their own college.
 *
 * Saving twice is safe: an identical request (same student, school, subject
 * title and code) that is still in the student's list or in the workflow
 * returns the existing row instead of a duplicate. A Program Chair's request is
 * compared only with rows the Chair can see (not the student's drafts).
 */
final readonly class CreateTransfereeCredit
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws AuthorizationException
     */
    public function execute(array $validated, User $actor, AuditRequestContext $context): TransfereeCredit
    {
        $student = $this->resolveStudent($validated, $actor);
        $isStudent = $actor->role === UserRole::Student;

        $institution = trim((string) $validated['source_institution']);
        $title = trim((string) $validated['source_subject_title']);
        $code = trim((string) ($validated['source_subject_code'] ?? ''));

        $reusableStatuses = array_map(
            static fn (TransfereeCreditStatus $status): string => $status->value,
            $isStudent
                ? [TransfereeCreditStatus::Draft, TransfereeCreditStatus::Pending, TransfereeCreditStatus::Endorsed]
                : [TransfereeCreditStatus::Pending, TransfereeCreditStatus::Endorsed],
        );

        return DB::transaction(function () use ($validated, $actor, $context, $student, $isStudent, $institution, $title, $code, $reusableStatuses): TransfereeCredit {
            $existing = TransfereeCredit::query()
                ->where('student_id', $student->id)
                ->whereIn('status', $reusableStatuses)
                ->whereRaw('LOWER(TRIM(source_institution)) = ?', [mb_strtolower($institution)])
                ->whereRaw('LOWER(TRIM(source_subject_title)) = ?', [mb_strtolower($title)])
                ->whereRaw('LOWER(TRIM(source_subject_code)) = ?', [mb_strtolower($code)])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing->load(['student.user', 'subject']);
            }

            $credit = TransfereeCredit::create([
                'student_id' => $student->id,
                'source_institution' => $institution,
                'source_subject_code' => $code,
                'source_subject_title' => $title,
                'source_grade' => $validated['source_grade'] ?? null,
                'credited_units' => $validated['credited_units'],
                'source_school_year' => $validated['source_school_year'] ?? null,
                'source_semester' => $validated['source_semester'] ?? null,
                'subject_id' => $isStudent ? null : ($validated['subject_id'] ?? null),
                'requested_by' => $actor->id,
                'status' => $isStudent ? TransfereeCreditStatus::Draft : TransfereeCreditStatus::Pending,
            ]);

            // A credit that now exists replaces a "no subject to credit" the Program Chair has not confirmed yet.
            NoCreditDeclaration::query()
                ->where('student_id', $student->id)
                ->whereNull('confirmed_at')
                ->delete();

            $this->auditRecorder->record(
                $actor,
                AuditAction::TRANSFEREE_CREDIT_CREATED,
                AuditableType::TRANSFEREE_CREDIT,
                $credit->id,
                null,
                [
                    'student_id' => $credit->student_id,
                    'subject_id' => $credit->subject_id,
                    'credited_units' => $credit->credited_units,
                    'status' => $credit->status->value,
                ],
                null,
                $context,
            );

            return $credit->refresh()->load(['student.user', 'subject']);
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws AuthorizationException
     */
    private function resolveStudent(array $validated, User $actor): StudentProfile
    {
        if ($actor->role === UserRole::Student) {
            $profile = StudentProfile::query()->with('program')->where('user_id', $actor->id)->first();

            if ($profile === null) {
                throw ValidationException::withMessages(['student_id' => 'Your student record could not be found.']);
            }

            return $profile;
        }

        $studentId = $validated['student_id'] ?? null;
        if ($studentId === null) {
            throw ValidationException::withMessages(['student_id' => 'Choose the student this credit is for.']);
        }

        $student = StudentProfile::query()->with('program')->findOrFail((int) $studentId);

        if (
            $actor->role === UserRole::ProgramChair
            && $actor->college !== null
            && $student->program->college !== $actor->college
        ) {
            throw new AuthorizationException('This student is not in your college.');
        }

        return $student;
    }
}
