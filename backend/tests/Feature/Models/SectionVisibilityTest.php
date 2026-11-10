<?php

namespace Tests\Feature\Models;

use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use App\Policies\SectionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SectionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(UserRole $role): User
    {
        return User::create([
            'name' => 'Test '.$role->value,
            'email' => $role->value.'@grc.test',
            'password' => 'irrelevant-password',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
    }

    private function makeSubject(string $code): Subject
    {
        return Subject::create(['code' => $code, 'title' => 'Test', 'units' => 3, 'status' => SubjectStatus::Active]);
    }

    public function test_learner_scoped_role_sees_only_published_and_closed_sections(): void
    {
        $term = $this->makeTerm();
        Section::create(['academic_term_id' => $term->id, 'subject_id' => $this->makeSubject('CS101')->id, 'section_code' => 'A', 'capacity' => 40, 'status' => SectionStatus::Published]);
        Section::create(['academic_term_id' => $term->id, 'subject_id' => $this->makeSubject('CS102')->id, 'section_code' => 'A', 'capacity' => 40, 'status' => SectionStatus::Planned]);

        $student = $this->makeUser(UserRole::Student);
        $visible = Section::query()->visibleTo($student)->pluck('section_code')->all();

        self::assertSame(['A'], $visible);
        self::assertSame(1, Section::query()->visibleTo($student)->count());
    }

    public function test_planning_role_sees_every_section_regardless_of_status(): void
    {
        $term = $this->makeTerm();
        Section::create(['academic_term_id' => $term->id, 'subject_id' => $this->makeSubject('CS101')->id, 'section_code' => 'A', 'capacity' => 40, 'status' => SectionStatus::Published]);
        Section::create(['academic_term_id' => $term->id, 'subject_id' => $this->makeSubject('CS102')->id, 'section_code' => 'A', 'capacity' => 40, 'status' => SectionStatus::Planned]);

        $chair = $this->makeUser(UserRole::ProgramChair);

        self::assertSame(2, Section::query()->visibleTo($chair)->count());
    }

    public function test_executive_director_cannot_query_or_directly_view_non_published_sections(): void
    {
        $term = $this->makeTerm();
        $published = Section::create(['academic_term_id' => $term->id, 'subject_id' => $this->makeSubject('EXEC-PUBLISHED')->id, 'section_code' => 'PUBLISHED', 'capacity' => 40, 'status' => SectionStatus::Published]);
        $planned = Section::create(['academic_term_id' => $term->id, 'subject_id' => $this->makeSubject('EXEC-PLANNED')->id, 'section_code' => 'PLANNED', 'capacity' => 40, 'status' => SectionStatus::Planned]);
        $closed = Section::create(['academic_term_id' => $term->id, 'subject_id' => $this->makeSubject('EXEC-CLOSED')->id, 'section_code' => 'CLOSED', 'capacity' => 40, 'status' => SectionStatus::Closed]);
        $cancelled = Section::create(['academic_term_id' => $term->id, 'subject_id' => $this->makeSubject('EXEC-CANCELLED')->id, 'section_code' => 'CANCELLED', 'capacity' => 40, 'status' => SectionStatus::Cancelled]);
        $executive = $this->makeUser(UserRole::ExecutiveDirector);

        self::assertSame([$published->id], Section::query()->visibleTo($executive)->pluck('id')->all());
        $policy = app(SectionPolicy::class);
        self::assertTrue($policy->view($executive, $published));
        self::assertFalse($policy->view($executive, $planned));
        self::assertFalse($policy->view($executive, $closed));
        self::assertFalse($policy->view($executive, $cancelled));
    }
}
