<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CurriculumCatalogMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_subjects_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('subjects'));
        $this->assertTrue(Schema::hasColumns('subjects', [
            'id', 'code', 'college', 'title', 'units', 'status', 'created_at', 'updated_at',
        ]));
    }

    public function test_subjects_code_is_unique_per_college(): void
    {
        DB::table('subjects')->insert([
            'code' => 'CS101', 'college' => 'ccs', 'title' => 'Intro to Programming', 'units' => 3,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('subjects')->insert([
            'code' => 'CS101', 'college' => 'ccs', 'title' => 'Duplicate', 'units' => 3,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_same_code_is_allowed_in_a_different_college(): void
    {
        DB::table('subjects')->insert([
            'code' => 'ETHICS', 'college' => 'ccs', 'title' => 'Ethics', 'units' => 3,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('subjects')->insert([
            'code' => 'ETHICS', 'college' => 'coe', 'title' => 'Ethics', 'units' => 3,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(2, DB::table('subjects')->where('code', 'ETHICS')->count());
    }

    public function test_curricula_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('curricula'));
        $this->assertTrue(Schema::hasColumns('curricula', [
            'id', 'program_id', 'name', 'effective_school_year', 'status',
            'created_at', 'updated_at',
        ]));
    }

    public function test_a_program_with_a_curriculum_cannot_be_deleted(): void
    {
        $programId = DB::table('programs')->insertGetId([
            'code' => 'BSIT', 'name' => 'BS IT', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'program_id' => $programId, 'name' => 'BSIT 2026 Curriculum',
            'effective_school_year' => '2026-2027', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('programs')->where('id', $programId)->delete();
    }

    public function test_curriculum_subjects_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('curriculum_subjects'));
        $this->assertTrue(Schema::hasColumns('curriculum_subjects', [
            'id', 'curriculum_id', 'subject_id', 'year_level', 'semester',
            'is_required', 'created_at', 'updated_at',
        ]));
    }

    public function test_a_subject_cannot_appear_twice_in_the_same_curriculum(): void
    {
        [$curriculumId, $subjectId] = $this->makeCurriculumAndSubject();

        DB::table('curriculum_subjects')->insert([
            'curriculum_id' => $curriculumId, 'subject_id' => $subjectId,
            'year_level' => 1, 'semester' => '1st', 'is_required' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('curriculum_subjects')->insert([
            'curriculum_id' => $curriculumId, 'subject_id' => $subjectId,
            'year_level' => 2, 'semester' => '2nd', 'is_required' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_deleting_a_curriculum_cascades_to_its_subject_placements(): void
    {
        [$curriculumId, $subjectId] = $this->makeCurriculumAndSubject();

        $placementId = DB::table('curriculum_subjects')->insertGetId([
            'curriculum_id' => $curriculumId, 'subject_id' => $subjectId,
            'year_level' => 1, 'semester' => '1st', 'is_required' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('curricula')->where('id', $curriculumId)->delete();

        $this->assertDatabaseMissing('curriculum_subjects', ['id' => $placementId]);
    }

    public function test_a_subject_placed_in_a_curriculum_cannot_be_deleted(): void
    {
        [$curriculumId, $subjectId] = $this->makeCurriculumAndSubject();

        DB::table('curriculum_subjects')->insert([
            'curriculum_id' => $curriculumId, 'subject_id' => $subjectId,
            'year_level' => 1, 'semester' => '1st', 'is_required' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('subjects')->where('id', $subjectId)->delete();
    }

    public function test_subject_prerequisites_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('subject_prerequisites'));
        $this->assertTrue(Schema::hasColumns('subject_prerequisites', [
            'id', 'curriculum_subject_id', 'prerequisite_subject_id',
            'minimum_grade', 'created_at', 'updated_at',
        ]));
    }

    public function test_the_same_prerequisite_mapping_cannot_be_duplicated(): void
    {
        [$curriculumId, $subjectId] = $this->makeCurriculumAndSubject();

        $placementId = DB::table('curriculum_subjects')->insertGetId([
            'curriculum_id' => $curriculumId, 'subject_id' => $subjectId,
            'year_level' => 2, 'semester' => '1st', 'is_required' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $prerequisiteSubjectId = DB::table('subjects')->insertGetId([
            'code' => 'CS100', 'title' => 'Prerequisite Subject', 'units' => 3,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('subject_prerequisites')->insert([
            'curriculum_subject_id' => $placementId,
            'prerequisite_subject_id' => $prerequisiteSubjectId,
            'minimum_grade' => '75', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('subject_prerequisites')->insert([
            'curriculum_subject_id' => $placementId,
            'prerequisite_subject_id' => $prerequisiteSubjectId,
            'minimum_grade' => '80', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_migrations_are_fully_reversible(): void
    {
        $this->artisan('migrate:rollback')->assertExitCode(0);

        $this->assertFalse(Schema::hasTable('subject_prerequisites'));
        $this->assertFalse(Schema::hasTable('curriculum_subjects'));
        $this->assertFalse(Schema::hasTable('curricula'));
        $this->assertFalse(Schema::hasTable('subjects'));

        $this->artisan('migrate')->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('subject_prerequisites'));
        $this->assertTrue(Schema::hasTable('curriculum_subjects'));
        $this->assertTrue(Schema::hasTable('curricula'));
        $this->assertTrue(Schema::hasTable('subjects'));
    }

    /**
     * @return array{0: int, 1: int} [curriculumId, subjectId]
     */
    private function makeCurriculumAndSubject(): array
    {
        $programId = DB::table('programs')->insertGetId([
            'code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $curriculumId = DB::table('curricula')->insertGetId([
            'program_id' => $programId, 'name' => 'BSCS 2026 Curriculum',
            'effective_school_year' => '2026-2027', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $subjectId = DB::table('subjects')->insertGetId([
            'code' => 'CS102', 'title' => 'Data Structures', 'units' => 3,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$curriculumId, $subjectId];
    }
}
