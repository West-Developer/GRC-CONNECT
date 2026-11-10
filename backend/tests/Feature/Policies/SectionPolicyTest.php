<?php

namespace Tests\Feature\Policies;

use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use App\Policies\SectionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SectionPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Program Chairs are college-scoped (ADR 0018), and `SectionPolicy`
     * denies write access to one without a college, so the role alone is
     * not enough to build a chair who can act.
     */
    private function makeUser(UserRole $role): User
    {
        return User::create([
            'name' => 'Test '.$role->value,
            'email' => $role->value.'@grc.test',
            'password' => 'irrelevant-password',
            'role' => $role,
            'college' => $role === UserRole::ProgramChair ? CollegeCode::Ccs : null,
            'status' => UserStatus::Active,
        ]);
    }

    private function makePlannedSection(): Section
    {
        $term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $subject = Subject::create(['code' => 'CS101', 'title' => 'Test', 'units' => 3, 'status' => SubjectStatus::Active]);

        return Section::create(['academic_term_id' => $term->id, 'subject_id' => $subject->id, 'section_code' => 'A', 'capacity' => 40, 'status' => SectionStatus::Planned]);
    }

    public function test_every_role_may_view_any(): void
    {
        $policy = new SectionPolicy;

        self::assertTrue($policy->viewAny($this->makeUser(UserRole::Student)));
        self::assertTrue($policy->viewAny($this->makeUser(UserRole::ProgramChair)));
    }

    public function test_a_learner_scoped_role_cannot_view_a_planned_section_by_direct_id(): void
    {
        $policy = new SectionPolicy;
        $section = $this->makePlannedSection();

        self::assertFalse($policy->view($this->makeUser(UserRole::Student), $section));
    }

    public function test_a_planning_role_can_view_a_planned_section_by_direct_id(): void
    {
        $policy = new SectionPolicy;
        $section = $this->makePlannedSection();

        self::assertTrue($policy->view($this->makeUser(UserRole::ProgramChair), $section));
    }

    public function test_a_faculty_member_cannot_view_another_faculty_members_published_section_by_id(): void
    {
        $policy = new SectionPolicy;
        $faculty = $this->makeUser(UserRole::Faculty);
        $otherFaculty = User::create([
            'name' => 'Other Faculty',
            'email' => 'other-faculty@grc.test',
            'password' => 'irrelevant-password',
            'role' => UserRole::Faculty,
            'status' => UserStatus::Active,
        ]);
        $term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterOngoing]);
        $subject = Subject::create(['code' => 'CS102', 'title' => 'Other Test', 'units' => 3, 'status' => SubjectStatus::Active]);
        $ownSection = Section::create(['academic_term_id' => $term->id, 'subject_id' => $subject->id, 'section_code' => 'OWN', 'professor_id' => $faculty->id, 'capacity' => 40, 'status' => SectionStatus::Published]);
        $otherSection = Section::create(['academic_term_id' => $term->id, 'subject_id' => $subject->id, 'section_code' => 'OTHER', 'professor_id' => $otherFaculty->id, 'capacity' => 40, 'status' => SectionStatus::Published]);

        self::assertTrue($policy->view($faculty, $ownSection));
        self::assertFalse($policy->view($faculty, $otherSection));
    }

    public function test_only_a_program_chair_may_create_or_update(): void
    {
        $policy = new SectionPolicy;
        $section = $this->makePlannedSection();
        $chair = $this->makeUser(UserRole::ProgramChair);

        self::assertTrue($policy->create($chair));
        self::assertTrue($policy->update($chair, $section));
        self::assertFalse($policy->create($this->makeUser(UserRole::Dean)));
        self::assertFalse($policy->update($this->makeUser(UserRole::Faculty), $section));
    }
}
