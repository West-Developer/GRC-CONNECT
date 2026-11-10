<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stakeholder Doc 14 S21: every room's use this term in one query, so the
 * Rooms screen can show Empty or N classes before a room is opened.
 */
final class RoomOccupancySummaryEndpointTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function token(UserRole $role): string
    {
        $user = User::create([
            'name' => 'Test '.$role->value.' '.++$this->seq,
            'email' => $role->value.'.'.$this->seq.'.rooms@grc.test',
            'password' => 'correct-horse-battery-staple',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);

        return $user->createToken('room-summary-test')->plainTextToken;
    }

    private function term(string $semester = '1st'): AcademicTerm
    {
        return AcademicTerm::create(['school_year' => '2026-2027', 'semester' => $semester, 'status' => AcademicTermStatus::SemesterOngoing]);
    }

    private function section(AcademicTerm $term, ?string $room, ?string $days): Section
    {
        $subject = Subject::create(['code' => 'RM'.++$this->seq, 'title' => 'Room test', 'units' => 3, 'status' => SubjectStatus::Active]);

        return Section::create([
            'academic_term_id' => $term->id,
            'subject_id' => $subject->id,
            'section_code' => 'A',
            'room' => $room,
            'schedule_days' => $days,
            'starts_at_time' => $days === null ? null : '08:00:00',
            'ends_at_time' => $days === null ? null : '10:00:00',
            'capacity' => 40,
            'status' => SectionStatus::Published,
        ]);
    }

    public function test_it_counts_classes_and_lists_the_days_used_per_room(): void
    {
        $term = $this->term();
        $other = $this->term('2nd');
        $this->section($term, 'LAB 1', 'MW');
        $this->section($term, 'LAB 1', 'F');
        $this->section($term, '3A', 'TTh');
        // Not counted: another term, no room, no schedule, blank room.
        $this->section($other, 'LAB 1', 'MON');
        $this->section($term, null, 'MON');
        $this->section($term, 'LAB 2', null);
        $this->section($term, '', 'MON');

        $response = $this->withToken($this->token(UserRole::ProgramChair))
            ->getJson("/api/v1/room-occupancy-summary?academic_term_id={$term->id}");

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        self::assertSame([
            ['room' => '3A', 'classes_count' => 1, 'days' => [2, 4]],
            ['room' => 'LAB 1', 'classes_count' => 2, 'days' => [1, 3, 5]],
        ], $response->json('data'));
    }

    public function test_a_room_with_no_class_is_simply_absent_so_the_screen_shows_it_as_empty(): void
    {
        $term = $this->term();

        $this->withToken($this->token(UserRole::RegistrarHead))
            ->getJson("/api/v1/room-occupancy-summary?academic_term_id={$term->id}")
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_it_runs_one_query_for_the_sections_however_many_rooms_there_are(): void
    {
        $term = $this->term();
        foreach (range(1, 12) as $index) {
            $this->section($term, 'ROOM '.$index, 'MWF');
        }
        $token = $this->token(UserRole::ProgramChair);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($token)->getJson("/api/v1/room-occupancy-summary?academic_term_id={$term->id}")->assertOk();
        $sectionQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from `sections`'));

        self::assertCount(1, $sectionQueries);
    }

    public function test_the_term_is_required_and_must_exist(): void
    {
        $token = $this->token(UserRole::ProgramChair);

        $this->withToken($token)->getJson('/api/v1/room-occupancy-summary')->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/room-occupancy-summary?academic_term_id=99999')->assertUnprocessable();
    }

    public function test_only_program_heads_and_the_registrar_head_may_read_it(): void
    {
        $term = $this->term();

        foreach ([UserRole::Student, UserRole::Faculty, UserRole::Dean, UserRole::RegistrarStaff] as $role) {
            $this->withToken($this->token($role))
                ->getJson("/api/v1/room-occupancy-summary?academic_term_id={$term->id}")
                ->assertForbidden();
            $this->flushHeaders();
            $this->app['auth']->forgetGuards();
        }
    }
}
