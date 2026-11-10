<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\AcademicGrade;
use Illuminate\Support\Facades\DB;

$programs = Program::where('status', 'active')->orderBy('id')->get();
$defaultHashedPassword = \Illuminate\Support\Facades\Hash::make('password');

$cohortList = [];

foreach ($programs as $prog) {
    $years = $prog->code === 'TCP' ? [1] : [1, 2, 3, 4];
    foreach ($years as $year) {
        // 1. Pick 5 Regular Students
        $regulars = StudentProfile::with('user')
            ->where('program_id', $prog->id)
            ->where('year_level', $year)
            ->where(function ($q) {
                $q->where('enrollment_category', 'regular')
                  ->orWhereNull('enrollment_category');
            })
            ->whereDoesntHave('grades', function ($q) {
                $q->whereIn('mark', ['5.00', 'DRP', 'INC', 'NC']);
            })
            ->take(5)
            ->get();

        if ($regulars->count() < 5) {
            $neededReg = 5 - $regulars->count();
            $moreRegs = StudentProfile::with('user')
                ->where('program_id', $prog->id)
                ->where('year_level', $year)
                ->whereNotIn('id', $regulars->pluck('id'))
                ->take($neededReg)
                ->get();
            foreach ($moreRegs as $mr) {
                $mr->update(['enrollment_category' => 'regular']);
            }
            $regulars = $regulars->merge($moreRegs);
        }

        // 2. Pick 5 Irregular Students (first those with actual failed/dropped grades or irregular category)
        $irregulars = StudentProfile::with('user')
            ->where('program_id', $prog->id)
            ->where('year_level', $year)
            ->where(function ($q) {
                $q->where('enrollment_category', 'irregular')
                  ->orWhereHas('grades', function ($g) {
                      $g->whereIn('mark', ['5.00', 'DRP', 'INC', 'NC']);
                  });
            })
            ->whereNotIn('id', $regulars->pluck('id'))
            ->take(5)
            ->get();

        // If fewer than 5 irregulars exist in this cell (e.g. Year 1 fresh intake),
        // pick remaining students and mark them as irregular category so irregular flows can be tested
        if ($irregulars->count() < 5) {
            $needed = 5 - $irregulars->count();
            $supplementary = StudentProfile::with('user')
                ->where('program_id', $prog->id)
                ->where('year_level', $year)
                ->whereNotIn('id', $regulars->pluck('id')->merge($irregulars->pluck('id')))
                ->take($needed)
                ->get();

            foreach ($supplementary as $sup) {
                $sup->update(['enrollment_category' => 'irregular']);
            }
            $irregulars = $irregulars->merge($supplementary);
        }

        // Ensure user accounts are active and have password
        foreach ($regulars->merge($irregulars) as $profile) {
            if ($profile->user) {
                $profile->user->update([
                    'status' => \App\Domain\Identity\UserStatus::Active,
                    'password' => $defaultHashedPassword,
                ]);
            }
        }

        foreach ($regulars as $reg) {
            $cohortList[] = [
                'student_profile_id' => $reg->id,
                'user_id' => $reg->user_id,
                'student_number' => $reg->student_number,
                'email' => $reg->user->email,
                'password' => 'password',
                'program_id' => $prog->id,
                'program_code' => $prog->code,
                'program_name' => $prog->name,
                'year_level' => $year,
                'category' => 'regular',
            ];
        }

        foreach ($irregulars as $irreg) {
            $cohortList[] = [
                'student_profile_id' => $irreg->id,
                'user_id' => $irreg->user_id,
                'student_number' => $irreg->student_number,
                'email' => $irreg->user->email,
                'password' => 'password',
                'program_id' => $prog->id,
                'program_code' => $prog->code,
                'program_name' => $prog->name,
                'year_level' => $year,
                'category' => 'irregular',
            ];
        }
    }
}

$outputPath = __DIR__ . '/../../frontend/scripts/student_matrix_cohort.json';
file_put_contents($outputPath, json_encode($cohortList, JSON_PRETTY_PRINT));
echo "Successfully exported " . count($cohortList) . " students across all programs and year levels to {$outputPath}." . PHP_EOL;
