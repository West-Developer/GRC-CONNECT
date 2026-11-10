<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\ChedProgramName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChedProgramNameTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function names(): array
    {
        return [
            'BS with a major' => [
                'BS Business Administration major in Financial Management',
                'Bachelor of Science in Business Administration',
                'Financial Management',
            ],
            'BS without a major' => ['BS Information Technology', 'Bachelor of Science in Information Technology', ''],
            'already in full, with a major' => [
                'Bachelor of Secondary Education major in Filipino',
                'Bachelor of Secondary Education',
                'Filipino',
            ],
            'already in full, no major' => ['Bachelor of Elementary Education', 'Bachelor of Elementary Education', ''],
            'not a degree abbreviation' => ['Teacher Certificate Program', 'Teacher Certificate Program', ''],
            'major keyword in any case' => [
                'BS Business Administration Major In Marketing Management',
                'Bachelor of Science in Business Administration',
                'Marketing Management',
            ],
            'BA is Bachelor of Arts' => ['BA Communication', 'Bachelor of Arts in Communication', ''],
            'surrounding spaces are trimmed' => ['  BS Accountancy ', 'Bachelor of Science in Accountancy', ''],
        ];
    }

    #[DataProvider('names')]
    public function test_it_writes_the_program_in_full_and_splits_off_the_major(string $stored, string $program, string $major): void
    {
        $this->assertSame(['program' => $program, 'major' => $major], ChedProgramName::split($stored));
    }
}
