<?php

namespace App\Actions\Enrollment;

use App\Models\AcademicTerm;
use App\Models\EnrollmentSubjectWaiver;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;

/**
 * What the Registrar Head needs to decide on prerequisite waivers for one
 * student in one term: the waivers already granted (active and revoked), and
 * the subjects the student is currently blocked from ONLY because of an unmet
 * prerequisite (a subject that is already completed, already selected, or has
 * no open section is not something a waiver would change, so it is not listed).
 */
final readonly class ListSubjectWaiverOverview
{
    public function __construct(private BuildEligibleSubjectPool $pool) {}

    /**
     * @return array{
     *     waivers: Collection<int, EnrollmentSubjectWaiver>,
     *     blocked_subjects: list<array{subject_id: int, subject_code: string, subject_title: string, reasons: list<string>}>
     * }
     */
    public function execute(StudentProfile $student, AcademicTerm $term): array
    {
        $waivers = EnrollmentSubjectWaiver::query()
            ->where('student_id', $student->id)
            ->where('academic_term_id', $term->id)
            ->with('subject')
            ->orderByDesc('granted_at')
            ->get();

        $blocked = [];

        foreach ($this->pool->execute($student, $term) as $entry) {
            if ($entry->isEligible) {
                continue;
            }

            $codes = array_column($entry->reasons, 'code');

            if (! in_array('prerequisite', $codes, true) || array_intersect($codes, ['completed', 'already_selected']) !== []) {
                continue;
            }

            $blocked[] = [
                'subject_id' => $entry->subject->id,
                'subject_code' => $entry->subject->code,
                'subject_title' => $entry->subject->title,
                'reasons' => array_values(array_map(
                    fn (array $reason): string => $reason['message'],
                    array_filter($entry->reasons, fn (array $reason): bool => $reason['code'] === 'prerequisite'),
                )),
            ];
        }

        return ['waivers' => $waivers, 'blocked_subjects' => $blocked];
    }
}
