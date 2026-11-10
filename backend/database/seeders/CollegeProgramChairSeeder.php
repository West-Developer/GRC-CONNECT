<?php

namespace Database\Seeders;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds one additional Program Chair identity per supported college, on top
 * of (never replacing) RoleUserSeeder's single generic `chair.seed@grc.test`.
 * RoleUserSeeder's own contract — exactly one identity per PRD role — stays
 * unchanged; this is a separate, additive seeder for the four-college
 * rollout, following the same shared-password/environment-guard/transaction
 * shape every other seeder in this project uses.
 */
final class CollegeProgramChairSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        $this->guardEnvironment();

        DB::transaction(function (): void {
            foreach (CollegeCode::cases() as $college) {
                User::updateOrCreate(
                    ['email' => "chair.{$college->value}@grc.test"],
                    [
                        'name' => "Seed Program Chair — {$college->label()}",
                        'password' => self::PASSWORD,
                        'role' => UserRole::ProgramChair,
                        'college' => $college,
                        'status' => UserStatus::Active,
                    ],
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
                'CollegeProgramChairSeeder may only run in the local or testing environment. '
                .'Refusing to seed synthetic credentials into "'.app()->environment().'".',
            );
        }
    }
}
