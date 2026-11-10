<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AcademicTermArchiveMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_academic_terms_have_reversible_archive_metadata(): void
    {
        self::assertTrue(Schema::hasColumns('academic_terms', ['closed_at', 'archived_at']));
    }

    public function test_singleton_current_term_slot_exists_and_starts_empty(): void
    {
        self::assertTrue(Schema::hasTable('academic_term_current_slots'));
        self::assertSame(['id', 'academic_term_id', 'created_at', 'updated_at'], Schema::getColumnListing('academic_term_current_slots'));
        self::assertSame(1, DB::table('academic_term_current_slots')->count());
        self::assertNull(DB::table('academic_term_current_slots')->value('academic_term_id'));
    }
}
