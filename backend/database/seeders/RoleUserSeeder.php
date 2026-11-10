<?php

namespace Database\Seeders;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * Seeds exactly one synthetic development identity per supported human role.
 *
 * These are database fixtures for local development and automated tests. They
 * are not production accounts. Every identity deliberately shares the
 * documented password `password` so each role is easy to exercise locally.
 */
final class RoleUserSeeder extends Seeder
{
    private const PASSWORD = 'password';

    /**
     * Deterministic display name and email per role. Emails use the reserved
     * `.test` TLD (RFC 2606) so they can never resolve to a real mailbox.
     *
     * @var array<string, array{name: string, email: string}>
     */
    private const IDENTITIES = [
        'student' => ['name' => 'Seed Student', 'email' => 'student.seed@grc.test'],
        'admission_staff' => ['name' => 'Seed Admission Staff', 'email' => 'admission.seed@grc.test'],
        'faculty' => ['name' => 'Seed Faculty', 'email' => 'faculty.seed@grc.test'],
        'program_chair' => ['name' => 'Seed Program Chair', 'email' => 'chair.seed@grc.test'],
        'dean' => ['name' => 'Seed Dean', 'email' => 'dean.seed@grc.test'],
        'executive_director' => ['name' => 'Seed Executive Director', 'email' => 'executive.seed@grc.test'],
        'registrar_head' => ['name' => 'Seed Registrar Head', 'email' => 'registrar-head.seed@grc.test'],
        'registrar_staff' => ['name' => 'Seed Registrar Staff', 'email' => 'registrar-staff.seed@grc.test'],
        'accounting_staff' => ['name' => 'Seed Accounting Staff', 'email' => 'accounting.seed@grc.test'],
        'it_admin' => ['name' => 'IT Control', 'email' => 'it.control@grc.test'],
    ];

    public function run(): void
    {
        $this->guardEnvironment();

        DB::transaction(function (): void {
            foreach (UserRole::seedableCases() as $role) {
                $identity = self::IDENTITIES[$role->value]
                    ?? throw new LogicException("Missing development identity for {$role->value}.");

                $attributes = [
                    'name' => $identity['name'],
                    'password' => self::PASSWORD,
                    'role' => $role,
                    'status' => UserStatus::Active,
                ];

                // The local curriculum approval journey uses the CCS BSIT
                // program. A Dean must carry an explicit college scope for
                // the curriculum index and approval policy to reveal those
                // records; the Executive Director intentionally remains
                // institution-wide without a college assignment.
                if ($role === UserRole::Dean) {
                    $attributes['college'] = CollegeCode::Ccs;
                }

                User::updateOrCreate(
                    ['email' => $identity['email']],
                    $attributes,
                );
            }
        });
    }

    /**
     * Synthetic credentials must never reach a production-like environment.
     */
    private function guardEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'RoleUserSeeder may only run in the local or testing environment. '
                .'Refusing to seed synthetic credentials into "'.app()->environment().'".',
            );
        }
    }
}
