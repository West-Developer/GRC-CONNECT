<?php

namespace App\Domain\Analytics;

/**
 * Selects the local demonstration cohort that intentionally does not continue
 * into a comparison term. It is pure so automation and tests share one stable
 * cohort rule without exposing student identities through analytics.
 */
final class SelectAttritionHoldouts
{
    private const RATE = 0.05;

    /**
     * @param  list<array{id: int, student_number: string, program_code: string, year_level: int}>  $students
     * @param  list<int>  $alreadyStartedStudentIds
     * @return list<int>
     */
    public function select(array $students, int $comparisonTermId, array $alreadyStartedStudentIds = []): array
    {
        $alreadyStarted = array_fill_keys($alreadyStartedStudentIds, true);

        /** @var array<string, list<array{id: int, student_number: string, program_code: string, year_level: int}>> $cohorts */
        $cohorts = [];
        foreach ($students as $student) {
            $cohorts[$student['program_code'].'|'.$student['year_level']][] = $student;
        }

        $holdouts = [];
        foreach ($cohorts as $cohort) {
            $holdoutCount = (int) round(count($cohort) * self::RATE, 0, PHP_ROUND_HALF_UP);
            if ($holdoutCount === 0) {
                continue;
            }

            $candidates = array_values(array_filter(
                $cohort,
                fn (array $student): bool => ! isset($alreadyStarted[$student['id']]),
            ));
            usort($candidates, fn (array $left, array $right): int => $this->rank($left['student_number'], $comparisonTermId) <=> $this->rank($right['student_number'], $comparisonTermId));

            foreach (array_slice($candidates, 0, $holdoutCount) as $student) {
                $holdouts[] = $student['id'];
            }
        }

        sort($holdouts);

        return $holdouts;
    }

    private function rank(string $studentNumber, int $comparisonTermId): string
    {
        return hash('sha256', $comparisonTermId.'|'.$studentNumber);
    }
}
