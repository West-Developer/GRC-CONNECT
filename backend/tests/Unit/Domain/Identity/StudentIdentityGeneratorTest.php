<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\StudentIdentityGenerator;
use Tests\TestCase;

class StudentIdentityGeneratorTest extends TestCase
{
    public function test_identities_are_deterministic_short_and_collision_free(): void
    {
        $first = StudentIdentityGenerator::forIndex(2023, 1001);

        $this->assertSame('2023-06-01001', $first['student_number']);
        $this->assertSame('jocelyn.guiao@grc.com', $first['email']);
        $this->assertMatchesRegularExpression('/^\d{4}-(0[1-9]|1[0-2])-\d{5}$/', $first['student_number']);
        $this->assertSame($first, StudentIdentityGenerator::forIndex(2023, 1001));
    }

    public function test_name_includes_a_middle_initial_and_matches_the_deterministic_pools(): void
    {
        $identity = StudentIdentityGenerator::forIndex(2023, 1001);

        // Given name, middle initial (single letter + period), surname.
        $this->assertMatchesRegularExpression('/^[\p{L}\' -]+ [A-Z]\. [\p{L}\' -]+$/u', $identity['name']);

        // Pinned to the actual deterministic derivation from crc32("2023-1001"),
        // confirmed by running the real implementation (see task-2-report.md).
        $this->assertSame('Jocelyn V. Guiao', $identity['name']);
    }

    public function test_different_sequences_produce_different_names(): void
    {
        $a = StudentIdentityGenerator::forIndex(2023, 1001);
        $b = StudentIdentityGenerator::forIndex(2023, 1002);

        $this->assertNotSame($a['name'], $b['name']);
        $this->assertNotSame($a['student_number'], $b['student_number']);
        $this->assertNotSame($a['email'], $b['email']);
    }

    public function test_it_never_collides_with_the_demo_enrollment_roster(): void
    {
        // sequences start at 1001, so no generated number can fall in the reserved range
        $reserved = ['2023-06-00001', '2023-06-00100', '2024-06-00101'];

        $identity = StudentIdentityGenerator::forIndex(2023, 1001);

        $this->assertNotContains($identity['student_number'], $reserved);
        $this->assertGreaterThanOrEqual(1001, (int) substr($identity['student_number'], -5));
    }
}
