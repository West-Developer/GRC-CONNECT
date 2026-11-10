<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\UserRole;
use PHPUnit\Framework\TestCase;

final class UserRoleTest extends TestCase
{
    public function test_role_values_match_the_role_catalog(): void
    {
        self::assertSame([
            'student',
            'admission_staff',
            'faculty',
            'program_chair',
            'dean',
            'executive_director',
            'registrar_head',
            'registrar_staff',
            'accounting_staff',
            'it_admin',
            'queue_kiosk',
            'super_admin',
        ], array_column(UserRole::cases(), 'value'));
    }

    public function test_the_role_catalog_includes_it_admin(): void
    {
        $this->assertContains('it_admin', array_column(UserRole::cases(), 'value'));
        $this->assertSame('IT Control', UserRole::ItAdmin->label());
        $this->assertFalse(UserRole::ItAdmin->isLearnerScoped());
        $this->assertCount(12, UserRole::cases());
    }

    public function test_the_role_catalog_includes_super_admin(): void
    {
        $this->assertContains('super_admin', array_column(UserRole::cases(), 'value'));
        $this->assertSame('Super Admin', UserRole::SuperAdmin->label());
        $this->assertFalse(UserRole::SuperAdmin->isLearnerScoped());
        $this->assertCount(12, UserRole::cases());
    }

    public function test_seedable_cases_exclude_super_admin(): void
    {
        $this->assertNotContains(UserRole::SuperAdmin, UserRole::seedableCases());
        $this->assertContains(UserRole::SuperAdmin, UserRole::humanCases());
    }

    public function test_super_admin_switchable_cases(): void
    {
        $this->assertCount(8, UserRole::superAdminSwitchableCases());
        $this->assertNotContains(UserRole::Faculty, UserRole::superAdminSwitchableCases());
        $this->assertNotContains(UserRole::Student, UserRole::superAdminSwitchableCases());
    }

    public function test_super_admin_invitable_cases_adds_admission_staff(): void
    {
        $this->assertCount(9, UserRole::superAdminInvitableCases());
        $this->assertContains(UserRole::AdmissionStaff, UserRole::superAdminInvitableCases());
    }

    public function test_college_required_when_acting(): void
    {
        $this->assertTrue(UserRole::collegeRequiredWhenActing(UserRole::ProgramChair));
        $this->assertTrue(UserRole::collegeRequiredWhenActing(UserRole::Dean));
        $this->assertFalse(UserRole::collegeRequiredWhenActing(UserRole::RegistrarHead));
        $this->assertFalse(UserRole::collegeRequiredWhenActing(UserRole::AdmissionStaff));
    }

    public function test_queue_kiosk_is_a_device_role_outside_the_human_role_catalog(): void
    {
        self::assertSame('queue_kiosk', UserRole::QueueKiosk->value);
        self::assertSame('Queue Kiosk', UserRole::QueueKiosk->label());
        self::assertTrue(UserRole::QueueKiosk->isDevice());
        self::assertFalse(UserRole::QueueKiosk->isLearnerScoped());
        self::assertCount(12, UserRole::cases());
        self::assertCount(11, UserRole::humanCases());
        self::assertNotContains(UserRole::QueueKiosk, UserRole::humanCases());
    }

    public function test_role_labels_are_stable_and_human_readable(): void
    {
        self::assertSame('Program Head', UserRole::ProgramChair->label());
        self::assertSame('Accounting Staff', UserRole::AccountingStaff->label());
    }

    public function test_exactly_three_roles_are_learner_scoped(): void
    {
        self::assertSame(
            ['student', 'faculty', 'accounting_staff'],
            array_column(
                array_filter(UserRole::cases(), fn (UserRole $role): bool => $role->isLearnerScoped()),
                'value',
            ),
        );
    }

    public function test_planning_roles_are_not_learner_scoped(): void
    {
        self::assertFalse(UserRole::ProgramChair->isLearnerScoped());
        self::assertFalse(UserRole::Dean->isLearnerScoped());
        self::assertFalse(UserRole::ExecutiveDirector->isLearnerScoped());
        self::assertFalse(UserRole::RegistrarHead->isLearnerScoped());
        self::assertFalse(UserRole::RegistrarStaff->isLearnerScoped());
        self::assertFalse(UserRole::AdmissionStaff->isLearnerScoped());
    }
}
