<?php

namespace App\Actions\Dashboard;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Dashboard\EnrollmentStatusGroup;
use App\Domain\Dashboard\EnrollmentStatusStudentDetail;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Fourth (last) level of the drill-down: one student and their current
 * enrollment for the term, in the same narrow field set as the list. A student
 * outside the actor's college is reported as not found, so the endpoint can't
 * be used to probe other colleges' records. Every open is audited (ADR 0024).
 */
final readonly class ShowEnrollmentStatusStudent
{
    public function __construct(
        private EnrollmentStatusPopulation $population,
        private AuditRecorder $auditRecorder,
    ) {}

    public function execute(
        AcademicTerm $term,
        User $actor,
        AuditRequestContext $context,
        int $studentProfileId,
    ): EnrollmentStatusStudentDetail {
        $scope = $this->population->scopeFor($actor);

        $profile = StudentProfile::query()
            ->with(['user', 'program'])
            ->where('is_demo_account', false)
            ->find($studentProfileId);

        if (
            ! $profile instanceof StudentProfile
            || ($scope !== null && $profile->program->college !== $scope)
            // A stage-limited role (Registrar, Accounting, Admission) may only open
            // a student who is at their own stage; anyone else is "not found".
            || ($this->population->isStageLimited($actor)
                && ! $this->population->query($term, $scope, $actor)->where('sp.id', $profile->id)->exists())
        ) {
            throw new NotFoundHttpException('Student not found.');
        }

        $enrollment = $this->currentEnrollment($profile, $term);
        $subjects = $enrollment === null ? [] : $this->subjects($enrollment);

        return DB::transaction(function () use ($term, $actor, $context, $profile, $enrollment, $subjects): EnrollmentStatusStudentDetail {
            $this->auditRecorder->record(
                $actor,
                AuditAction::ENROLLMENT_STATUS_STUDENT_VIEWED,
                AuditableType::STUDENT_PROFILE,
                $profile->id,
                null,
                ['academic_term_id' => $term->id],
                null,
                $context,
            );

            return new EnrollmentStatusStudentDetail(
                academicTermId: $term->id,
                studentProfileId: $profile->id,
                studentNumber: $profile->student_number,
                name: $profile->user->name,
                programCode: $profile->program->code,
                programName: $profile->program->name,
                department: $profile->program->college?->value,
                yearLevel: $profile->year_level,
                enrollmentCategory: $profile->enrollment_category,
                group: EnrollmentStatusGroup::forStatus($enrollment?->status),
                sectionCode: $this->primarySectionCode($subjects),
                enrollment: $enrollment === null ? null : [
                    'id' => $enrollment->id,
                    'status' => $enrollment->status->value,
                    'status_label' => $enrollment->status->label(),
                    'total_units' => (float) $enrollment->total_units,
                    'submitted_at' => $enrollment->submitted_at,
                    'registrar_decided_at' => $enrollment->registrar_decided_at,
                    'payment_confirmed_at' => $enrollment->payment_confirmed_at,
                    'enrolled_at' => $enrollment->enrolled_at,
                ],
                subjects: $subjects,
            );
        });
    }

    /**
     * Same rule as `EnrollmentStatusPopulation`: a non-terminal enrollment if
     * there is one, otherwise the latest terminal one.
     */
    private function currentEnrollment(StudentProfile $profile, AcademicTerm $term): ?Enrollment
    {
        $enrollments = Enrollment::query()
            ->where('student_id', $profile->id)
            ->where('academic_term_id', $term->id)
            ->with('enrollmentSubjects.section.subject')
            ->orderByDesc('id')
            ->get();

        return $enrollments->first(fn (Enrollment $enrollment): bool => ! $enrollment->status->isTerminal())
            ?? $enrollments->first();
    }

    /**
     * @return list<array{subject_code: string, subject_title: string, units: ?float, section_code: ?string, status: string, status_label: string}>
     */
    private function subjects(Enrollment $enrollment): array
    {
        $subjects = [];

        foreach ($enrollment->enrollmentSubjects as $enrollmentSubject) {
            if ($enrollmentSubject->status === EnrollmentSubjectStatus::Dropped) {
                continue;
            }

            $section = $enrollmentSubject->section;
            $subjects[] = [
                'subject_code' => $section->subject->code,
                'subject_title' => $section->subject->title,
                'units' => (float) $section->subject->units,
                'section_code' => $section->section_code,
                'status' => $enrollmentSubject->status->value,
                'status_label' => ucfirst($enrollmentSubject->status->value),
            ];
        }

        usort($subjects, fn (array $a, array $b): int => strcmp($a['subject_code'], $b['subject_code']));

        return $subjects;
    }

    /**
     * The section code covering most subjects, ties by code — the same rule the
     * list and section levels use, so the levels always agree.
     *
     * @param  list<array{section_code: ?string}>  $subjects
     */
    private function primarySectionCode(array $subjects): ?string
    {
        $counts = [];
        foreach ($subjects as $subject) {
            if ($subject['section_code'] !== null) {
                $counts[$subject['section_code']] = ($counts[$subject['section_code']] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return null;
        }

        $codes = array_map('strval', array_keys($counts));
        usort($codes, fn (string $a, string $b): int => ($counts[$b] <=> $counts[$a]) ?: strcmp($a, $b));

        return $codes[0];
    }
}
