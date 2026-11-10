<?php

namespace Tests\Unit\Domain\Organization;

use App\Domain\Organization\StudentRosterReader;
use Tests\TestCase;

final class StudentRosterReaderTest extends TestCase
{
    public function test_it_reads_only_student_rows_from_the_committed_local_roster(): void
    {
        $rows = (new StudentRosterReader)->read(base_path('../Subject And Prerequisuite/Students-Profile.md'));

        $this->assertCount(3210, $rows);
        $this->assertSame('2026-06-01001', $rows[0]['student_number']);
        $this->assertSame('BEED', $rows[0]['program_code']);
        $this->assertSame(1, $rows[0]['year_level']);
    }
}
