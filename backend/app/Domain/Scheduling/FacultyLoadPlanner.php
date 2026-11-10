<?php

namespace App\Domain\Scheduling;

use App\Domain\Faculty\SpecializationProficiency;

/**
 * Deterministic, explainable faculty recommendation ranking.
 *
 * Availability and existing-conflict checks are hard constraints. A primary
 * specialization outranks every bare preference; otherwise a lower declared
 * preference rank wins. A secondary specialization only breaks equal-rank
 * ties, followed by source-backed teaching evidence, lower current unit total,
 * and the stable identifier tie-break.
 */
final class FacultyLoadPlanner
{
    /**
     * @param  list<array{id: int, preference_rank: int, specialization_match?: ?SpecializationProficiency, teaching_history_evidence?: int, availability_match: bool, conflict_free: bool, assigned_units: float|int}>  $candidates
     * @return array{professor_id: ?int, rationale: list<string>}
     */
    public function choose(array $candidates): array
    {
        $eligible = array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => $candidate['availability_match'] && $candidate['conflict_free'],
        ));

        if ($eligible === []) {
            return [
                'professor_id' => null,
                'rationale' => ['no_available_preferred_faculty'],
            ];
        }

        usort($eligible, fn (array $left, array $right): int => [
            $this->isPrimarySpecialization($left) ? 0 : 1,
            $left['preference_rank'],
            $this->hasSecondarySpecialization($left) ? 0 : 1,
            -((int) ($left['teaching_history_evidence'] ?? 0)),
            (float) $left['assigned_units'],
            $left['id'],
        ] <=> [
            $this->isPrimarySpecialization($right) ? 0 : 1,
            $right['preference_rank'],
            $this->hasSecondarySpecialization($right) ? 0 : 1,
            -((int) ($right['teaching_history_evidence'] ?? 0)),
            (float) $right['assigned_units'],
            $right['id'],
        ]);

        $selected = $eligible[0];

        $rationale = ['preference_rank_'.$selected['preference_rank']];
        if (($selected['teaching_history_evidence'] ?? 0) > 0) {
            $rationale[] = 'prior_teaching_evidence';
        }
        $rationale[] = 'availability_match';
        $rationale[] = 'conflict_free';
        $rationale[] = 'load_balanced';

        return ['professor_id' => $selected['id'], 'rationale' => $rationale];
    }

    /** @param array{specialization_match?: ?SpecializationProficiency} $candidate */
    private function isPrimarySpecialization(array $candidate): bool
    {
        return ($candidate['specialization_match'] ?? null) === SpecializationProficiency::Primary;
    }

    /** @param array{specialization_match?: ?SpecializationProficiency} $candidate */
    private function hasSecondarySpecialization(array $candidate): bool
    {
        return ($candidate['specialization_match'] ?? null) === SpecializationProficiency::Secondary;
    }
}
