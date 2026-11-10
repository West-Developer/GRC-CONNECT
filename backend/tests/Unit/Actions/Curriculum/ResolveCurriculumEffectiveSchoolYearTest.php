<?php

namespace Tests\Unit\Actions\Curriculum;

use App\Actions\Curriculum\ResolveCurriculumEffectiveSchoolYear;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ResolveCurriculumEffectiveSchoolYearTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_the_current_term_from_the_current_slot_before_newer_terms(): void
    {
        $currentTerm = AcademicTerm::create([
            'school_year' => '2025-2026',
            'semester' => '2nd',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        AcademicTerm::create([
            'school_year' => '2026-2027',
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        DB::table('academic_term_current_slots')->where('id', 1)->update([
            'academic_term_id' => $currentTerm->id,
        ]);

        self::assertSame('2025-2026', app(ResolveCurriculumEffectiveSchoolYear::class)->execute());
    }

    public function test_it_falls_back_to_the_first_term_in_academic_term_api_list_order(): void
    {
        AcademicTerm::create([
            'school_year' => '2025-2026',
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        AcademicTerm::create([
            'school_year' => '2026-2027',
            'semester' => '2nd',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        AcademicTerm::create([
            'school_year' => '2026-2027',
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);

        self::assertSame('2026-2027', app(ResolveCurriculumEffectiveSchoolYear::class)->execute());
    }

    public function test_it_requires_a_current_or_latest_academic_term(): void
    {
        try {
            app(ResolveCurriculumEffectiveSchoolYear::class)->execute();
            self::fail('Expected resolver to require an academic term.');
        } catch (ValidationException $exception) {
            self::assertSame([
                'academic_term' => ['A current or latest academic term is required before creating a curriculum.'],
            ], $exception->errors());
        }
    }
}
