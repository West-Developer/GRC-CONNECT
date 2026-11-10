<?php

namespace Tests\Feature\Database;

use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\FacultyAvailability;
use App\Models\FacultySubjectPreference;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class FacultyInputMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_faculty_availabilities_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('faculty_availabilities'));
        $this->assertTrue(Schema::hasColumns('faculty_availabilities', [
            'id', 'professor_id', 'day_of_week',
            'starts_at_time', 'ends_at_time', 'created_at', 'updated_at',
        ]));
        // Availability is term-independent (2026_08_10_000001).
        $this->assertFalse(Schema::hasColumn('faculty_availabilities', 'academic_term_id'));
    }

    public function test_the_same_professor_cannot_declare_the_same_slot_twice(): void
    {
        $professor = $this->makeProfessor();
        $term = $this->makeTerm();

        FacultyAvailability::create([
            'professor_id' => $professor->id,
            'academic_term_id' => $term->id,
            'day_of_week' => 1,
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '09:00:00',
        ]);

        $this->expectException(QueryException::class);

        FacultyAvailability::create([
            'professor_id' => $professor->id,
            'academic_term_id' => $term->id,
            'day_of_week' => 1,
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '10:00:00',
        ]);
    }

    public function test_deleting_the_professor_cascades_to_their_availability(): void
    {
        $professor = $this->makeProfessor();
        $term = $this->makeTerm();

        $availability = FacultyAvailability::create([
            'professor_id' => $professor->id,
            'academic_term_id' => $term->id,
            'day_of_week' => 1,
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '09:00:00',
        ]);

        $professor->delete();

        $this->assertDatabaseMissing('faculty_availabilities', ['id' => $availability->id]);
    }

    public function test_faculty_subject_preferences_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('faculty_subject_preferences'));
        $this->assertTrue(Schema::hasColumns('faculty_subject_preferences', [
            'id', 'professor_id', 'academic_term_id', 'subject_id', 'rank',
            'created_at', 'updated_at',
        ]));
    }

    public function test_a_professor_cannot_rank_the_same_subject_twice_in_one_term(): void
    {
        $professor = $this->makeProfessor();
        $term = $this->makeTerm();
        $subject = $this->makeSubject('CS101');

        FacultySubjectPreference::create([
            'professor_id' => $professor->id,
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'rank' => 1,
        ]);

        $this->expectException(QueryException::class);

        FacultySubjectPreference::create([
            'professor_id' => $professor->id,
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'rank' => 2,
        ]);
    }

    public function test_a_professor_cannot_reuse_a_rank_position_in_one_term(): void
    {
        $professor = $this->makeProfessor();
        $term = $this->makeTerm();

        FacultySubjectPreference::create([
            'professor_id' => $professor->id,
            'academic_term_id' => $term->id,
            'subject_id' => $this->makeSubject('CS101')->id,
            'rank' => 1,
        ]);

        $this->expectException(QueryException::class);

        FacultySubjectPreference::create([
            'professor_id' => $professor->id,
            'academic_term_id' => $term->id,
            'subject_id' => $this->makeSubject('CS102')->id,
            'rank' => 1,
        ]);
    }

    public function test_a_subject_referenced_by_a_preference_cannot_be_deleted(): void
    {
        $subject = $this->makeSubject('CS101');

        FacultySubjectPreference::create([
            'professor_id' => $this->makeProfessor()->id,
            'academic_term_id' => $this->makeTerm()->id,
            'subject_id' => $subject->id,
            'rank' => 1,
        ]);

        $this->expectException(QueryException::class);

        $subject->delete();
    }

    private function makeProfessor(): User
    {
        return User::create([
            'name' => 'Test Faculty',
            'email' => 'faculty.'.uniqid().'@grc.test',
            'password' => 'irrelevant-password',
            'role' => UserRole::Faculty,
            'status' => UserStatus::Active,
        ]);
    }

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2026-2027',
            'semester' => '1st',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
    }

    private function makeSubject(string $code): Subject
    {
        return Subject::create([
            'code' => $code,
            'title' => 'Test Subject '.$code,
            'units' => 3,
            'status' => SubjectStatus::Active,
        ]);
    }
}
