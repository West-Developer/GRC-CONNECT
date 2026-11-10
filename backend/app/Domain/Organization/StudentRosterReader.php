<?php

namespace App\Domain\Organization;

use Illuminate\Support\Facades\File;
use RuntimeException;

/** Reads the committed local roster without interpreting section history. */
final class StudentRosterReader
{
    private const STUDENT_NUMBER_PATTERN = '/^\d{4}-\d{2}-\d{5}$/';

    /**
     * @return list<array{student_number: string, name: string, email: string, program_code: string, year_level: int}>
     */
    public function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Student roster file was not found at {$path}");
        }

        $rows = [];
        $inSection = false;

        foreach (preg_split('/\R/u', File::get($path)) ?: [] as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '#### ')) {
                $inSection = true;

                continue;
            }

            if (str_starts_with($trimmed, '#')) {
                $inSection = false;

                continue;
            }

            if (! $inSection || $trimmed === '' || ! str_starts_with($trimmed, '|')) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim($trimmed, '|')));
            if (count($cells) !== 7 || preg_match(self::STUDENT_NUMBER_PATTERN, $cells[0]) !== 1) {
                continue;
            }

            $rows[] = [
                'student_number' => $cells[0],
                'name' => $cells[1],
                'email' => $cells[2],
                'program_code' => $cells[3],
                'year_level' => (int) $cells[5],
            ];
        }

        return $rows;
    }
}
