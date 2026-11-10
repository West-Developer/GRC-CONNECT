<?php

namespace Tests\Feature\Console;

use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\EnrollmentRevision;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AuditEnrollmentRevisionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_detects_inconsistencies_without_changing_records_or_disclosing_notes(): void
    {
        $user = User::create(['name' => 'Private name', 'email' => 'private.audit@grc.test', 'password' => 'secret-password', 'role' => 'student', 'status' => 'active']);
        $program = Program::create(['code' => 'BSCS', 'name' => 'BSCS', 'status' => 'active']);
        $curriculum = Curriculum::create(['program_id' => $program->id, 'name' => 'Curriculum', 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $student = StudentProfile::create(['user_id' => $user->id, 'student_number' => 'PRIVATE-NUMBER', 'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'year_level' => 1, 'admission_status' => 'admitted', 'academic_standing' => 'good']);
        $term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => 'semester_ongoing']);
        $enrollment = Enrollment::create(['student_id' => $student->id, 'academic_term_id' => $term->id, 'status' => 'pending_registrar_approval']);
        $base = ['enrollment_id' => $enrollment->id, 'note' => 'PRIVATE-NOTE', 'added_subjects' => [], 'removed_subjects' => [], 'units_before' => 3, 'units_after' => 3, 'status' => 'pending'];
        $old = EnrollmentRevision::create([...$base, 'proposed_by' => $user->id]);
        $unknown = EnrollmentRevision::create([...$base, 'proposed_by' => null, 'responded_at' => now()]);
        $before = EnrollmentRevision::query()->get()->toArray();

        self::assertSame(0, Artisan::call('enrollments:audit-revisions'));
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2, $report['pending_checked']);
        self::assertContains('newer_revision_exists', $report['findings'][0]['issues']);
        self::assertContains('enrollment_not_waiting_for_proposal', $report['findings'][0]['issues']);
        self::assertContains('unknown_author', $report['findings'][1]['issues']);
        self::assertContains('pending_with_response', $report['findings'][1]['issues']);
        self::assertSame([$old->id, $unknown->id], array_column($report['findings'], 'revision_id'));
        self::assertStringNotContainsString('PRIVATE', $output);
        self::assertStringNotContainsString('Private name', $output);
        self::assertSame($before, EnrollmentRevision::query()->get()->toArray());
    }
}
