<?php

namespace Tests\Feature\Database;

use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use Database\Seeders\AcademicTermSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AcademicTermSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_clean_seed_creates_nineteen_terms_with_1st_semester_2026_2027_current(): void
    {
        $this->seed(AcademicTermSeeder::class);

        $this->assertSame(19, AcademicTerm::count());
        $this->assertSame(18, AcademicTerm::where('status', AcademicTermStatus::Archived)->count());

        $current = AcademicTerm::where('school_year', '2026-2027')->where('semester', '1st')->sole();
        $this->assertSame(AcademicTermStatus::SemesterClosed, $current->status);

        $slot = DB::table('academic_term_current_slots')->where('id', 1)->first();
        $this->assertSame($current->id, $slot->academic_term_id);

        $this->assertSame(0, AcademicTerm::where('status', AcademicTermStatus::SemesterOngoing)->count());
        $this->assertFalse(AcademicTerm::where('school_year', '2026-2027')->where('semester', '2nd')->exists());
    }

    public function test_reseeding_updates_in_place_without_duplicates(): void
    {
        $this->seed(AcademicTermSeeder::class);
        $ids = AcademicTerm::orderBy('id')->pluck('id')->all();

        $this->seed(AcademicTermSeeder::class);

        $this->assertSame(19, AcademicTerm::count());
        $this->assertSame($ids, AcademicTerm::orderBy('id')->pluck('id')->all());
    }
}
