<?php

namespace App\Domain\Curriculum;

/**
 * Detects direct or transitive prerequisite cycles (FR-SCH-002) in a
 * proposed subject-prerequisite graph, scoped to a single curriculum.
 *
 * An edge `subject_id -> prerequisite_subject_id` means "subject_id
 * requires prerequisite_subject_id first." The caller assembles the full
 * edge list (existing DB rows plus any proposed additions) before calling
 * this class, so the same pure check covers both curriculum creation (no
 * rows exist yet) and updates (existing rows plus proposed changes).
 */
final class PrerequisiteCycleDetector
{
    /**
     * @param  list<array{subject_id: int, prerequisite_subject_id: int}>  $edges
     */
    public function hasCycle(array $edges): bool
    {
        $adjacency = [];

        foreach ($edges as $edge) {
            $adjacency[$edge['subject_id']][] = $edge['prerequisite_subject_id'];
        }

        $visiting = [];
        $visited = [];

        foreach (array_keys($adjacency) as $node) {
            if (isset($visited[$node])) {
                continue;
            }

            if ($this->hasCycleFrom($node, $adjacency, $visiting, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, list<int>>  $adjacency
     * @param  array<int, true>  $visiting
     * @param  array<int, true>  $visited
     */
    private function hasCycleFrom(int $node, array $adjacency, array &$visiting, array &$visited): bool
    {
        $visiting[$node] = true;

        foreach ($adjacency[$node] ?? [] as $neighbor) {
            if (isset($visiting[$neighbor])) {
                return true;
            }

            if (! isset($visited[$neighbor]) && $this->hasCycleFrom($neighbor, $adjacency, $visiting, $visited)) {
                return true;
            }
        }

        unset($visiting[$node]);
        $visited[$node] = true;

        return false;
    }
}
