<?php

namespace App\Actions\Academic;

use App\Domain\Academic\CreditSubjectSuggestion;
use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Models\CurriculumSubject;
use App\Models\StudentProfile;
use App\Models\TransfereeCredit;

/**
 * What the Program Chair's side-by-side screen needs for one student, in one
 * go (ADR 0026, amended): the system's suggestions for every credit that still
 * waits for a mapping (`pending` only: never a draft, never a decided credit),
 * and the subjects of the student's own curriculum to choose from. Advice only:
 * nothing here is stored or written to `subject_id`.
 */
final readonly class BuildCreditMappingWorkspace
{
    public function __construct(private SuggestCreditSubjects $suggester) {}

    /**
     * @return array{
     *     credits: list<array{credit_id: int, suggestions: list<CreditSubjectSuggestion>}>,
     *     curriculum_subjects: list<array{subject_id: int, subject_code: string, subject_title: string, units: float, year_level: int, semester: string}>
     * }
     */
    public function execute(StudentProfile $student): array
    {
        $credits = TransfereeCredit::query()
            ->where('student_id', $student->id)
            ->where('status', TransfereeCreditStatus::Pending->value)
            ->orderBy('id')
            ->get();

        $suggestions = $this->suggester->forCredits($student, $credits);

        $groups = [];
        foreach ($credits as $credit) {
            $groups[] = ['credit_id' => $credit->id, 'suggestions' => $suggestions[$credit->id]];
        }

        return ['credits' => $groups, 'curriculum_subjects' => $this->curriculumSubjects($student)];
    }

    /**
     * Active subjects on the student's curriculum, one entry per subject, by
     * year level, semester and code.
     *
     * @return list<array{subject_id: int, subject_code: string, subject_title: string, units: float, year_level: int, semester: string}>
     */
    private function curriculumSubjects(StudentProfile $student): array
    {
        $options = [];

        $placements = CurriculumSubject::query()
            ->where('curriculum_id', $student->curriculum_id)
            ->with('subject')
            ->get();

        foreach ($placements as $placement) {
            $subject = $placement->subject;

            if ($subject->status !== SubjectStatus::Active || isset($options[$subject->id])) {
                continue;
            }

            $options[$subject->id] = [
                'subject_id' => $subject->id,
                'subject_code' => $subject->code,
                'subject_title' => $subject->title,
                'units' => $subject->units,
                'year_level' => $placement->year_level,
                'semester' => $placement->semester,
            ];
        }

        $list = array_values($options);

        usort(
            $list,
            static fn (array $a, array $b): int => [$a['year_level'], $a['semester'], $a['subject_code']] <=> [$b['year_level'], $b['semester'], $b['subject_code']],
        );

        return $list;
    }
}
