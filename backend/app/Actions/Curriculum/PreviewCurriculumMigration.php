<?php

namespace App\Actions\Curriculum;

use App\Domain\Academic\GradeStatus;
use App\Domain\Academic\PrerequisiteEvaluator;
use App\Domain\Curriculum\CurriculumStatus;
use App\Models\AcademicGrade;
use App\Models\Curriculum;
use App\Models\CurriculumSubjectEquivalency;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final readonly class PreviewCurriculumMigration
{
    public function __construct(private PrerequisiteEvaluator $evaluator) {}

    /**
     * @return array{student: StudentProfile, source_curriculum: Curriculum, target_curriculum: Curriculum, credit_candidates: list<array{equivalency: CurriculumSubjectEquivalency, grade: AcademicGrade}>}
     */
    public function execute(User $actor, Curriculum $target, StudentProfile $student): array
    {
        $target->loadMissing(['program', 'equivalencySourceCurriculum']);
        $student->loadMissing('program');

        if ($actor->college === null || $target->program->college !== $actor->college) {
            throw new AuthorizationException;
        }
        if ($target->status !== CurriculumStatus::Active) {
            throw ValidationException::withMessages(['curriculum' => 'Only an active curriculum can receive a student migration.']);
        }
        $source = $target->equivalencySourceCurriculum;
        if ($source === null || $student->program_id !== $target->program_id || $student->curriculum_id !== $source->id) {
            throw ValidationException::withMessages(['student_id' => 'The student must currently follow this target curriculum\'s configured old curriculum.']);
        }

        $equivalencies = CurriculumSubjectEquivalency::query()
            ->where('source_curriculum_id', $source->id)
            ->where('target_curriculum_id', $target->id)
            ->with(['sourceSubject', 'targetSubject'])
            ->orderBy('id')
            ->get();
        $latestGrades = AcademicGrade::query()
            ->where('student_id', $student->id)
            ->where('status', GradeStatus::Locked)
            ->whereIn('subject_id', $equivalencies->pluck('source_subject_id'))
            ->orderByDesc('academic_term_id')
            ->orderByDesc('id')
            ->get()
            ->unique('subject_id');

        /** @var Collection<int, AcademicGrade> $gradesBySubject */
        $gradesBySubject = $latestGrades->keyBy('subject_id');
        $candidates = [];
        foreach ($equivalencies as $equivalency) {
            $grade = $gradesBySubject->get($equivalency->source_subject_id);
            if ($grade instanceof AcademicGrade && $this->isPassing($grade)) {
                $candidates[] = ['equivalency' => $equivalency, 'grade' => $grade];
            }
        }

        return ['student' => $student, 'source_curriculum' => $source, 'target_curriculum' => $target, 'credit_candidates' => $candidates];
    }

    private function isPassing(AcademicGrade $grade): bool
    {
        if ($grade->mark?->isPassing() === true) {
            return true;
        }

        return $this->evaluator->evaluate(
            $grade->final_grade,
            (string) config('enrollment.grading.passing_grade'),
        )->isSatisfied();
    }
}
