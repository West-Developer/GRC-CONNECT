<?php

namespace Database\Seeders;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CatalogFaculty;
use App\Domain\Organization\CollegeCode;
use App\Models\AcademicTerm;
use App\Models\FacultySubjectPreference;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Local/test CSV-sourced Faculty records and their declared teachable subjects. */
final class CatalogFacultySeeder extends Seeder
{
    private const CSV_PATH = __DIR__.'/data/organizations-subjects-prerequisites.csv';

    private const PASSWORD = 'password';

    public function run(): void
    {
        $this->guardEnvironment();
        $facultyRows = CatalogFaculty::fromCsv(self::CSV_PATH);

        DB::transaction(function () use ($facultyRows): void {
            User::updateOrCreate(
                ['email' => 'faculty.seed@grc.test'],
                [
                    'name' => 'Testing Faculty',
                    'password' => self::PASSWORD,
                    'role' => UserRole::Faculty,
                    'college' => CollegeCode::Ccs,
                    'status' => UserStatus::Active,
                ],
            );

            $terms = AcademicTerm::query()
                ->whereNotIn('status', ['archived', 'semester_closed'])
                ->get();
            $users = [];
            $nextRanks = [];

            foreach ($facultyRows as $facultyRow) {
                $college = $facultyRow['college'];
                $surname = $facultyRow['surname'];
                $emailLocalPart = trim((string) preg_replace('/[^a-z0-9]+/u', '-', strtolower($surname)), '-');
                $email = "faculty.{$college->value}.{$emailLocalPart}@grc.test";
                $userKey = "{$college->value}:{$surname}";
                $users[$userKey] ??= User::updateOrCreate(
                    ['email' => $email],
                    [
                        'name' => $surname,
                        'password' => self::PASSWORD,
                        'role' => UserRole::Faculty,
                        'college' => $college,
                        'status' => UserStatus::Active,
                    ],
                );

                $subject = Subject::query()
                    ->where('college', $college->value)
                    ->where('code', $facultyRow['subject_code'])
                    ->first();
                if ($subject === null) {
                    continue;
                }

                foreach ($terms as $term) {
                    $preference = FacultySubjectPreference::firstOrNew([
                        'professor_id' => $users[$userKey]->id,
                        'academic_term_id' => $term->id,
                        'subject_id' => $subject->id,
                    ]);
                    if ($preference->exists) {
                        continue;
                    }

                    $rankKey = "{$term->id}:{$users[$userKey]->id}";
                    $nextRanks[$rankKey] ??= (int) FacultySubjectPreference::query()
                        ->where('professor_id', $users[$userKey]->id)
                        ->where('academic_term_id', $term->id)
                        ->max('rank');
                    $preference->rank = ++$nextRanks[$rankKey];
                    $preference->save();
                }
            }
        });
    }

    private function guardEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('CatalogFacultySeeder may only run locally or in testing.');
        }
    }
}
