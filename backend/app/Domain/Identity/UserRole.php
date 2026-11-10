<?php

namespace App\Domain\Identity;

enum UserRole: string
{
    case Student = 'student';
    case AdmissionStaff = 'admission_staff';
    case Faculty = 'faculty';
    case ProgramChair = 'program_chair';
    case Dean = 'dean';
    case ExecutiveDirector = 'executive_director';
    case RegistrarHead = 'registrar_head';
    case RegistrarStaff = 'registrar_staff';
    case AccountingStaff = 'accounting_staff';
    case ItAdmin = 'it_admin';
    case QueueKiosk = 'queue_kiosk';
    case SuperAdmin = 'super_admin';

    public function label(): string
    {
        return match ($this) {
            self::Student => 'Student',
            self::AdmissionStaff => 'Admission Staff',
            self::Faculty => 'Professor / Faculty',
            self::ProgramChair => 'Program Head',
            self::Dean => 'Dean',
            self::ExecutiveDirector => 'Executive Director',
            self::RegistrarHead => 'Registrar Head',
            self::RegistrarStaff => 'Registrar Staff',
            self::AccountingStaff => 'Accounting Staff',
            self::ItAdmin => 'IT Control',
            self::QueueKiosk => 'Queue Kiosk',
            self::SuperAdmin => 'Super Admin',
        };
    }

    /**
     * Learner-scoped roles see only learner-visible organization records
     * (e.g. active programs, non-planning terms). Every other role plans
     * that data and sees the full catalog regardless of visibility status.
     * See Program::scopeVisibleTo() and AcademicTerm::scopeVisibleTo().
     */
    public function isLearnerScoped(): bool
    {
        return match ($this) {
            self::Student, self::Faculty, self::AccountingStaff => true,
            self::AdmissionStaff,
            self::ProgramChair,
            self::Dean,
            self::ExecutiveDirector,
            self::RegistrarHead,
            self::RegistrarStaff,
            self::ItAdmin,
            self::QueueKiosk,
            self::SuperAdmin => false,
        };
    }

    public function isDevice(): bool
    {
        return $this === self::QueueKiosk;
    }

    /**
     * @return list<self>
     */
    public static function humanCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $role): bool => ! $role->isDevice(),
        ));
    }

    /**
     * Roles populated with standard seed identities during local development
     * and automated testing. Excludes SuperAdmin, which is only provisioned
     * via CLI command.
     *
     * @return list<self>
     */
    public static function seedableCases(): array
    {
        return array_values(array_filter(
            self::humanCases(),
            static fn (self $role): bool => $role !== self::SuperAdmin,
        ));
    }

    /**
     * The 8 offices a Super Admin can switch into and act as.
     *
     * @return list<self>
     */
    public static function superAdminSwitchableCases(): array
    {
        return [
            self::AdmissionStaff,
            self::ProgramChair,
            self::Dean,
            self::ExecutiveDirector,
            self::RegistrarHead,
            self::RegistrarStaff,
            self::AccountingStaff,
            self::ItAdmin,
        ];
    }

    /**
     * Every role a Super Admin may create an account for through Accounts & Access:
     * the 8 Registrar-invitable roles plus Admission Staff.
     *
     * @return list<self>
     */
    public static function superAdminInvitableCases(): array
    {
        return [
            self::AdmissionStaff,
            ...self::registrarInvitableCases(),
        ];
    }

    /**
     * Determines whether a college is required when acting as the given role.
     */
    public static function collegeRequiredWhenActing(self $role): bool
    {
        return in_array($role, [self::ProgramChair, self::Dean], true);
    }

    /**
     * Every role a Registrar Head may create an account for through the
     * "Invite Staff" flow: every staff/leadership role except Student
     * (Admission's own "Create a student account" already owns that, with
     * its required program/curriculum/entry-year fields Faculty and other
     * staff roles have no equivalent of) and AdmissionStaff (kept out at
     * the user's explicit request, alongside QueueKiosk — a device account,
     * not a human one).
     *
     * @return list<self>
     */
    public static function registrarInvitableCases(): array
    {
        return [
            self::Faculty,
            self::ProgramChair,
            self::Dean,
            self::ExecutiveDirector,
            self::RegistrarHead,
            self::RegistrarStaff,
            self::AccountingStaff,
            self::ItAdmin,
        ];
    }
}
