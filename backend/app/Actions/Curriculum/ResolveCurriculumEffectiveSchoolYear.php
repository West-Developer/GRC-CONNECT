<?php

namespace App\Actions\Curriculum;

use App\Models\AcademicTerm;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ResolveCurriculumEffectiveSchoolYear
{
    public function execute(): string
    {
        $currentTermId = DB::table('academic_term_current_slots')
            ->where('id', 1)
            ->value('academic_term_id');

        if ($currentTermId !== null) {
            $currentTerm = AcademicTerm::query()->find($currentTermId);

            if ($currentTerm !== null) {
                return $currentTerm->school_year;
            }
        }

        $latestTerm = AcademicTerm::query()
            ->orderByDesc('school_year')
            ->orderBy('semester')
            ->first();

        if ($latestTerm !== null) {
            return $latestTerm->school_year;
        }

        throw ValidationException::withMessages([
            'academic_term' => 'A current or latest academic term is required before creating a curriculum.',
        ]);
    }
}
