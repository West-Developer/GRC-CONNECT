<?php

namespace App\Domain\Organization;

use RuntimeException;
use SplFileObject;

/** Parses the supplied catalog's organization, subject, and professor-surname columns. */
final class CatalogFaculty
{
    /**
     * @return list<array{college: CollegeCode, surname: string, subject_code: string}>
     */
    public static function fromCsv(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Catalog faculty CSV could not be found at {$path}.");
        }

        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $header = null;
        $faculty = [];
        $seen = [];

        foreach ($file as $line) {
            if (! is_array($line) || $line === [null]) {
                continue;
            }

            if ($header === null) {
                $header = array_map(static fn (?string $value): string => trim((string) $value), $line);
                if (isset($header[0]) && str_starts_with($header[0], "\xEF\xBB\xBF")) {
                    $header[0] = substr($header[0], 3);
                }

                continue;
            }

            if (count($line) !== count($header)) {
                continue;
            }

            /** @var array<string, string> $row */
            $row = array_combine($header, $line);
            $college = CollegeCode::tryFrom(strtolower(trim($row['organization'] ?? '')));
            $subjectCode = trim($row['subject_code'] ?? '');

            if ($college === null || $subjectCode === '') {
                continue;
            }

            foreach (explode('|', $row['professor_surnames'] ?? '') as $surname) {
                $surname = strtoupper(trim($surname));
                if ($surname === '' || $surname === 'UNASSIGNED') {
                    continue;
                }

                $key = "{$college->value}:{$surname}:{$subjectCode}";
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $faculty[] = ['college' => $college, 'surname' => $surname, 'subject_code' => $subjectCode];
            }
        }

        return $faculty;
    }
}
