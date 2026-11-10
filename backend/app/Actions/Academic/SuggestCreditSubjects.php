<?php

namespace App\Actions\Academic;

use App\Domain\Academic\CreditSubjectSuggestion;
use App\Domain\Academic\GradeStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Models\AcademicGrade;
use App\Models\CurriculumSubject;
use App\Models\StudentProfile;
use App\Models\TransfereeCredit;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which subjects of the student's own curriculum a subject taken elsewhere
 * could be credited to, best match first (ADR 0026).
 *
 * This is advice for the Program Chair and nothing more: it is computed on
 * every request, never stored, never written to `transferee_credits.subject_id`
 * (that column is only ever the Chair's decision), and it encodes no
 * institutional equivalence rule. It scores three plain signals:
 *
 *   - the subject code, compared with spaces and punctuation ignored;
 *   - the title, word by word (a word matches when it is the same or one is a
 *     prefix of the other, so "Intro" matches "Introduction"), ignoring small
 *     filler words and reading Roman numerals I to V as digits;
 *   - the units.
 *
 * Only subjects on the student's own curriculum are candidates, and a subject
 * the student already has (passed with a locked grade, credited, or already
 * mapped by another open or approved credit of theirs) is left out.
 *
 * `forCredits` does the same for several credits of one student at once and
 * shares what does not depend on the credit (the curriculum, the subjects the
 * student already has, the student's mapped credits), so the Program Chair's
 * per-student screen costs a handful of queries however many subjects the
 * student listed. `execute` is the one-credit case of it.
 */
final readonly class SuggestCreditSubjects
{
    private const LIMIT = 5;

    private const MIN_SCORE = 0.4;

    /** @var list<string> */
    private const STOP_WORDS = ['a', 'an', 'and', 'of', 'the', 'to', 'in', 'for', 'on', 'with'];

    /** @var array<string, string> */
    private const ROMAN_NUMERALS = ['i' => '1', 'ii' => '2', 'iii' => '3', 'iv' => '4', 'v' => '5'];

    public function __construct(private ResolveCreditedSubjectIds $creditedSubjects) {}

    /**
     * @return list<CreditSubjectSuggestion>
     */
    public function execute(TransfereeCredit $credit): array
    {
        $credit->loadMissing('student');

        return $this->forCredits($credit->student, new Collection([$credit]))[$credit->id];
    }

    /**
     * @param  Collection<int, TransfereeCredit>  $credits  every one of them belongs to `$student`
     * @return array<int, list<CreditSubjectSuggestion>> keyed by credit id
     */
    public function forCredits(StudentProfile $student, Collection $credits): array
    {
        $placements = CurriculumSubject::query()
            ->where('curriculum_id', $student->curriculum_id)
            ->with('subject')
            ->get();
        $alreadyHave = $this->subjectsTheStudentAlreadyHas($student);

        // Mapped by a credit that is still in the workflow or approved: crediting
        // one subject twice helps nobody. A credit's own mapping never counts
        // against itself, so each credit is compared with the OTHER mapped ones.
        $mapped = TransfereeCredit::query()
            ->where('student_id', $student->id)
            ->whereNotNull('subject_id')
            ->whereIn('status', ['pending', 'endorsed', 'approved'])
            ->get(['id', 'subject_id']);

        $result = [];

        foreach ($credits as $credit) {
            $excluded = $alreadyHave;

            foreach ($mapped as $other) {
                if ($other->id !== $credit->id) {
                    $excluded[(int) $other->subject_id] = true;
                }
            }

            $result[$credit->id] = $this->rank($credit, $placements, $excluded);
        }

        return $result;
    }

    /**
     * @param  Collection<int, CurriculumSubject>  $placements
     * @param  array<int, true>  $excluded
     * @return list<CreditSubjectSuggestion>
     */
    private function rank(TransfereeCredit $credit, Collection $placements, array $excluded): array
    {
        $sourceCode = self::normalizeCode($credit->source_subject_code);
        $sourceTokens = self::titleTokens($credit->source_subject_title);

        $suggestions = [];

        foreach ($placements as $placement) {
            $subject = $placement->subject;

            if ($subject->status !== SubjectStatus::Active || isset($excluded[$subject->id])) {
                continue;
            }

            $codeMatches = $sourceCode !== '' && $sourceCode === self::normalizeCode($subject->code);
            $titleScore = self::titleSimilarity($sourceTokens, self::titleTokens($subject->title));
            $unitsMatch = abs($subject->units - $credit->credited_units) < 0.01;

            $score = min(1.0, ($codeMatches ? 0.5 : 0.0) + 0.5 * $titleScore + ($unitsMatch ? 0.1 : 0.0));
            if ($score < self::MIN_SCORE) {
                continue;
            }

            $reasons = [];
            if ($codeMatches) {
                $reasons[] = 'Same subject code';
            }
            if ($titleScore >= 0.999) {
                $reasons[] = 'Same title';
            } elseif ($titleScore >= 0.5) {
                $reasons[] = sprintf('Similar title (%d%% match)', (int) round($titleScore * 100));
            }
            if ($unitsMatch) {
                $reasons[] = 'Same units';
            }

            $suggestions[] = new CreditSubjectSuggestion(
                $subject->id,
                $subject->code,
                $subject->title,
                $subject->units,
                $placement->year_level,
                $placement->semester,
                round($score, 2),
                $reasons,
            );
        }

        usort(
            $suggestions,
            static fn (CreditSubjectSuggestion $a, CreditSubjectSuggestion $b): int => [-$a->score, $a->subjectCode] <=> [-$b->score, $b->subjectCode],
        );

        return array_slice($suggestions, 0, self::LIMIT);
    }

    /**
     * Credited (curriculum migration or approved transferee credit) or passed
     * with a locked grade.
     *
     * @return array<int, true>
     */
    private function subjectsTheStudentAlreadyHas(StudentProfile $student): array
    {
        $studentId = $student->id;
        $excluded = $this->creditedSubjects->forStudents([$studentId], $student->curriculum_id)[$studentId] ?? [];

        $passingGrade = (float) config('enrollment.grading.passing_grade');
        $grades = AcademicGrade::query()
            ->where('student_id', $studentId)
            ->where('status', GradeStatus::Locked)
            ->get(['subject_id', 'mark', 'final_grade']);
        foreach ($grades as $grade) {
            // Rows locked before `mark` existed only carry `final_grade`.
            $passed = $grade->mark !== null
                ? $grade->mark->isPassing()
                : (is_numeric($grade->final_grade) && (float) $grade->final_grade <= $passingGrade);

            if ($passed) {
                $excluded[$grade->subject_id] = true;
            }
        }

        return $excluded;
    }

    private static function normalizeCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $code));
    }

    /**
     * @return list<string>
     */
    private static function titleTokens(string $title): array
    {
        $words = explode(' ', trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($title))));
        $tokens = [];

        foreach ($words as $word) {
            if ($word === '' || in_array($word, self::STOP_WORDS, true)) {
                continue;
            }

            $tokens[] = self::ROMAN_NUMERALS[$word] ?? $word;
        }

        return $tokens;
    }

    /**
     * Dice coefficient over words, where two words match when they are the
     * same or one is a prefix of the other (at least four letters long).
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function titleSimilarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $unused = $b;
        $matched = 0;

        foreach ($a as $word) {
            foreach ($unused as $index => $candidate) {
                if (self::wordsMatch($word, $candidate)) {
                    $matched++;
                    unset($unused[$index]);
                    break;
                }
            }
        }

        return (2 * $matched) / (count($a) + count($b));
    }

    private static function wordsMatch(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $shorter = strlen($a) <= strlen($b) ? $a : $b;
        $longer = $shorter === $a ? $b : $a;

        return strlen($shorter) >= 4 && str_starts_with($longer, $shorter);
    }
}
