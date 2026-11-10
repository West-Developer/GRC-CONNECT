<?php

namespace Tests\Unit\Domain\Scheduling;

use App\Domain\Faculty\SpecializationProficiency;
use App\Domain\Scheduling\FacultyLoadPlanner;
use PHPUnit\Framework\TestCase;

final class FacultyLoadPlannerTest extends TestCase
{
    public function test_it_prefers_ranked_subject_expertise_then_balances_existing_units_between_available_faculty(): void
    {
        $result = (new FacultyLoadPlanner)->choose([
            ['id' => 10, 'preference_rank' => 2, 'availability_match' => true, 'conflict_free' => true, 'assigned_units' => 0],
            ['id' => 11, 'preference_rank' => 1, 'availability_match' => true, 'conflict_free' => true, 'assigned_units' => 9],
            ['id' => 12, 'preference_rank' => 1, 'availability_match' => true, 'conflict_free' => true, 'assigned_units' => 3],
            ['id' => 13, 'preference_rank' => 1, 'availability_match' => false, 'conflict_free' => true, 'assigned_units' => 0],
        ]);

        self::assertSame(12, $result['professor_id']);
        self::assertSame('preference_rank_1', $result['rationale'][0]);
        self::assertContains('availability_match', $result['rationale']);
    }

    public function test_it_reports_no_recommendation_when_every_matching_faculty_member_is_unavailable_or_conflicted(): void
    {
        $result = (new FacultyLoadPlanner)->choose([
            ['id' => 10, 'preference_rank' => 1, 'availability_match' => false, 'conflict_free' => true, 'assigned_units' => 0],
            ['id' => 11, 'preference_rank' => 2, 'availability_match' => true, 'conflict_free' => false, 'assigned_units' => 0],
        ]);

        self::assertNull($result['professor_id']);
        self::assertSame(['no_available_preferred_faculty'], $result['rationale']);
    }

    public function test_it_uses_prior_teaching_evidence_to_break_equal_preference_ranks_before_load_balancing(): void
    {
        $result = (new FacultyLoadPlanner)->choose([
            [
                'id' => 10,
                'preference_rank' => 1,
                'teaching_history_evidence' => 0,
                'availability_match' => true,
                'conflict_free' => true,
                'assigned_units' => 0,
            ],
            [
                'id' => 11,
                'preference_rank' => 1,
                'teaching_history_evidence' => 3,
                'availability_match' => true,
                'conflict_free' => true,
                'assigned_units' => 6,
            ],
        ]);

        self::assertSame(11, $result['professor_id']);
        self::assertContains('prior_teaching_evidence', $result['rationale']);
    }

    public function test_a_secondary_specialization_breaks_an_equal_preference_rank_tie(): void
    {
        $result = (new FacultyLoadPlanner)->choose([
            [
                'id' => 10,
                'preference_rank' => 1,
                'specialization_match' => SpecializationProficiency::Secondary,
                'availability_match' => true,
                'conflict_free' => true,
                'assigned_units' => 9,
            ],
            [
                'id' => 11,
                'preference_rank' => 1,
                'availability_match' => true,
                'conflict_free' => true,
                'assigned_units' => 0,
            ],
        ]);

        self::assertSame(10, $result['professor_id']);
    }
}
