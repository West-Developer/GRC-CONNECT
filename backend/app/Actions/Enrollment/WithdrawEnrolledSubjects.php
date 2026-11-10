<?php

namespace App\Actions\Enrollment;

use App\Domain\Academic\GradeMark;
use App\Domain\Academic\GradeStatus;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicGrade;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Notification;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Registrar Head withdraws one or more of an enrolled student's subjects
 * for the term: each becomes `dropped` on the enrollment (so the schedule, COR
 * and rosters stop listing it), its section seat is released once (only when
 * `enrollment.withdrawal.releases_seats` is true, the same switch the whole-
 * enrollment withdrawal uses), and a locked `W` grade is written so the
 * Prospectus shows the subject as Withdrawn and the student has to take it
 * again. No fee is changed here: the assessment stays as billed (the COR's
 * withdrawal terms decide what is owed, and GRC has no refund process, ADR 0046).
 *
 * Idempotent: a subject that is no longer active is refused, so a repeated
 * request can never release a seat or write a grade twice. When the last
 * active subject of an enrollment is withdrawn the enrollment itself becomes
 * `withdrawn`, which frees the student's active-enrollment slot for the term.
 *
 * Lock order is PROFILE -> ENROLLMENT -> ENROLLMENT SUBJECTS -> grade, the
 * order the other enrollment actions use.
 */
final readonly class WithdrawEnrolledSubjects
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * @param  list<int>  $enrollmentSubjectIds
     * @return list<AcademicGrade>
     */
    public function execute(
        StudentProfile $student,
        array $enrollmentSubjectIds,
        string $reason,
        User $actor,
        AuditRequestContext $context,
    ): array {
        $ids = array_values(array_unique($enrollmentSubjectIds));

        return DB::transaction(function () use ($student, $ids, $reason, $actor, $context): array {
            StudentProfile::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();

            $enrollments = Enrollment::query()
                ->where('student_id', $student->id)
                ->whereHas('enrollmentSubjects', fn ($query) => $query->whereIn('id', $ids))
                ->with('academicTerm')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $subjects = EnrollmentSubject::query()
                ->whereIn('id', $ids)
                ->whereIn('enrollment_id', $enrollments->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->with('section')
                ->get();

            if ($subjects->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'enrollment_subject_ids' => 'One or more of these subjects do not belong to this student.',
                ]);
            }

            foreach ($subjects as $subject) {
                $enrollment = $enrollments->get($subject->enrollment_id);

                if ($enrollment === null || $enrollment->status !== EnrollmentStatus::Enrolled) {
                    throw ValidationException::withMessages([
                        'enrollment_subject_ids' => 'Only the subjects of an enrolled student can be withdrawn.',
                    ]);
                }

                if (in_array($enrollment->academicTerm->status, [AcademicTermStatus::SemesterClosed, AcademicTermStatus::Archived], true)) {
                    throw ValidationException::withMessages([
                        'enrollment_subject_ids' => 'This term is already closed, so its subjects can no longer be withdrawn.',
                    ]);
                }

                if (! $subject->status->occupiesSeat()) {
                    throw ValidationException::withMessages([
                        'enrollment_subject_ids' => "{$subject->section->section_code} is already dropped or withdrawn.",
                    ]);
                }
            }

            $releaseSeats = (bool) config('enrollment.withdrawal.releases_seats', true);
            $grades = [];

            foreach ($subjects as $subject) {
                $section = $subject->section;
                $enrollment = $enrollments->get($subject->enrollment_id);
                $grades[] = $this->recordWithdrawal($student, $section, $enrollment, $reason, $actor, $context);

                $subject->update(['status' => EnrollmentSubjectStatus::Dropped]);

                if ($releaseSeats) {
                    Section::query()->whereKey($section->id)->decrement('enrolled_count');
                }
            }

            foreach ($enrollments as $enrollment) {
                $stillActive = EnrollmentSubject::query()
                    ->where('enrollment_id', $enrollment->id)
                    ->where('status', '!=', EnrollmentSubjectStatus::Dropped->value)
                    ->exists();

                if (! $stillActive) {
                    $enrollment->update(['status' => EnrollmentStatus::Withdrawn]);
                }
            }

            $codes = collect($grades)->map(fn (AcademicGrade $grade): string => $grade->subject->code)->implode(', ');

            Notification::create([
                'user_id' => $student->user_id,
                'type' => NotificationType::SubjectWithdrawn,
                'message' => "The Registrar Head withdrew you from {$codes}. It is marked W on your Prospectus. Reason: {$reason}",
            ]);

            return $grades;
        });
    }

    private function recordWithdrawal(
        StudentProfile $student,
        Section $section,
        Enrollment $enrollment,
        string $reason,
        User $actor,
        AuditRequestContext $context,
    ): AcademicGrade {
        $existing = AcademicGrade::query()
            ->where('student_id', $student->id)
            ->where('subject_id', $section->subject_id)
            ->where('academic_term_id', $enrollment->academic_term_id)
            ->lockForUpdate()
            ->first();

        if ($existing !== null && $existing->status === GradeStatus::Locked) {
            throw ValidationException::withMessages([
                'enrollment_subject_ids' => 'A final grade is already locked for one of these subjects, so it can no longer be withdrawn.',
            ]);
        }

        $before = $existing === null ? null : [
            'mark' => $existing->mark?->value,
            'status' => $existing->status->value,
        ];

        $attributes = [
            'section_id' => $section->id,
            'mark' => GradeMark::Withdrawn,
            'final_grade' => null,
            'remarks' => $reason,
            'status' => GradeStatus::Locked,
            'encoded_by' => $actor->id,
            'submitted_at' => now(),
            'locked_at' => now(),
        ];

        if ($existing === null) {
            $grade = AcademicGrade::create([
                'student_id' => $student->id,
                'subject_id' => $section->subject_id,
                'academic_term_id' => $enrollment->academic_term_id,
                ...$attributes,
            ]);
        } else {
            $existing->update($attributes);
            $grade = $existing;
        }

        $this->auditRecorder->record(
            $actor,
            AuditAction::ENROLLMENT_SUBJECT_WITHDRAWN,
            AuditableType::ACADEMIC_GRADE,
            $grade->id,
            $before,
            [
                'student_id' => $student->id,
                'subject_id' => $section->subject_id,
                'section_id' => $section->id,
                'academic_term_id' => $enrollment->academic_term_id,
                'mark' => GradeMark::Withdrawn->value,
            ],
            $reason,
            $context,
        );

        return $grade->refresh()->load('subject');
    }
}
