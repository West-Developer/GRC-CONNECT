<?php

namespace Tests\Feature\Api\V1;

use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class RevisionSeatConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }

    public function test_two_simultaneous_revisions_cannot_take_the_same_last_seat(): void
    {
        $connection = config('database.default');
        if (! in_array($connection, ['mysql', 'mariadb'], true)) {
            self::markTestSkipped('This locking test requires isolated MariaDB/MySQL.');
        }
        $term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => 'semester_ongoing']);
        $program = Program::create(['code' => 'BSCS', 'name' => 'BSCS', 'college' => 'ccs', 'status' => 'active']);
        $curriculum = Curriculum::create(['program_id' => $program->id, 'name' => 'CS', 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $sections = [];
        foreach (['HELD', 'LAST'] as $code) {
            $subject = Subject::create(['code' => $code, 'title' => $code, 'units' => 3, 'status' => 'active']);
            CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_id' => $subject->id, 'year_level' => 1, 'semester' => '1st', 'is_required' => true]);
            $sections[] = Section::create(['academic_term_id' => $term->id, 'subject_id' => $subject->id, 'section_code' => 'A', 'capacity' => $code === 'LAST' ? 1 : 40, 'enrolled_count' => $code === 'LAST' ? 0 : 2, 'status' => 'published']);
        }
        $chair = User::create(['name' => 'Chair', 'email' => 'race.chair@grc.test', 'password' => 'test-only-password', 'role' => 'program_chair', 'college' => 'ccs', 'status' => 'active']);
        $enrollments = [];
        foreach ([1, 2] as $index) {
            $user = User::create(['name' => 'Race student', 'email' => "race{$index}@grc.test", 'password' => 'test-only-password', 'role' => 'student', 'status' => 'active']);
            $student = StudentProfile::create(['user_id' => $user->id, 'student_number' => "RACE-{$index}", 'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'year_level' => 1, 'admission_status' => 'admitted', 'academic_standing' => 'good']);
            $enrollment = Enrollment::create(['student_id' => $student->id, 'academic_term_id' => $term->id, 'status' => 'pending_program_head_approval', 'total_units' => 3]);
            EnrollmentSubject::create(['enrollment_id' => $enrollment->id, 'section_id' => $sections[0]->id, 'status' => 'selected']);
            $enrollments[] = $enrollment;
        }
        $barrier = tempnam(sys_get_temp_dir(), 'grc-barrier-');
        unlink($barrier);
        $readyFiles = [$barrier.'-1', $barrier.'-2'];
        $processes = [];
        $db = config("database.connections.{$connection}");
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => $connection, 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $db['database'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync'];
        try {
            foreach ($enrollments as $index => $enrollment) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/revision-seat-worker.php'), (string) $enrollment->id, (string) $sections[1]->id, (string) $chair->id, $readyFiles[$index], $barrier], base_path(), $environment, timeout: 30);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 20;
            while (! is_file($readyFiles[0]) || ! is_file($readyFiles[1])) {
                if (microtime(true) >= $deadline) {
                    self::fail('Workers did not reach the barrier.');
                }
                usleep(20000);
                clearstatcache();
            }
            file_put_contents($barrier, 'go');
            $results = [];
            foreach ($processes as $process) {
                self::assertSame(0, $process->wait(), $process->getErrorOutput());
                $results[] = trim($process->getOutput());
            }
            sort($results);
            self::assertSame(['accepted', 'unavailable'], $results);
            self::assertSame(1, $sections[1]->refresh()->enrolled_count);
            self::assertSame(1, $sections[0]->refresh()->enrolled_count);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ([$barrier, ...$readyFiles] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
