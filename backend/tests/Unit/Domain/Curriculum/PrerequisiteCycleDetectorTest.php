<?php

namespace Tests\Unit\Domain\Curriculum;

use App\Domain\Curriculum\PrerequisiteCycleDetector;
use PHPUnit\Framework\TestCase;

final class PrerequisiteCycleDetectorTest extends TestCase
{
    private PrerequisiteCycleDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new PrerequisiteCycleDetector;
    }

    public function test_an_empty_graph_has_no_cycle(): void
    {
        self::assertFalse($this->detector->hasCycle([]));
    }

    public function test_a_single_valid_prerequisite_edge_has_no_cycle(): void
    {
        // Subject 2 requires subject 1.
        self::assertFalse($this->detector->hasCycle([
            ['subject_id' => 2, 'prerequisite_subject_id' => 1],
        ]));
    }

    public function test_a_linear_chain_has_no_cycle(): void
    {
        // 3 requires 2, 2 requires 1.
        self::assertFalse($this->detector->hasCycle([
            ['subject_id' => 3, 'prerequisite_subject_id' => 2],
            ['subject_id' => 2, 'prerequisite_subject_id' => 1],
        ]));
    }

    public function test_a_subject_cannot_be_its_own_prerequisite(): void
    {
        self::assertTrue($this->detector->hasCycle([
            ['subject_id' => 1, 'prerequisite_subject_id' => 1],
        ]));
    }

    public function test_a_direct_two_subject_cycle_is_detected(): void
    {
        // 1 requires 2, and 2 requires 1.
        self::assertTrue($this->detector->hasCycle([
            ['subject_id' => 1, 'prerequisite_subject_id' => 2],
            ['subject_id' => 2, 'prerequisite_subject_id' => 1],
        ]));
    }

    public function test_a_transitive_three_subject_cycle_is_detected(): void
    {
        // 1 requires 2, 2 requires 3, 3 requires 1.
        self::assertTrue($this->detector->hasCycle([
            ['subject_id' => 1, 'prerequisite_subject_id' => 2],
            ['subject_id' => 2, 'prerequisite_subject_id' => 3],
            ['subject_id' => 3, 'prerequisite_subject_id' => 1],
        ]));
    }

    public function test_a_shared_prerequisite_across_two_subjects_is_not_a_cycle(): void
    {
        // Both 2 and 3 require 1; 2 and 3 have no relation to each other.
        self::assertFalse($this->detector->hasCycle([
            ['subject_id' => 2, 'prerequisite_subject_id' => 1],
            ['subject_id' => 3, 'prerequisite_subject_id' => 1],
        ]));
    }

    public function test_a_diamond_shaped_graph_is_not_a_cycle(): void
    {
        // 4 requires 2 and 3; 2 and 3 both require 1.
        self::assertFalse($this->detector->hasCycle([
            ['subject_id' => 4, 'prerequisite_subject_id' => 2],
            ['subject_id' => 4, 'prerequisite_subject_id' => 3],
            ['subject_id' => 2, 'prerequisite_subject_id' => 1],
            ['subject_id' => 3, 'prerequisite_subject_id' => 1],
        ]));
    }
}
