<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsFreshmen;
use Tests\TestCase;

/**
 * Registrar Staff and the Registrar Head get a read-only list of every student, whatever the
 * student's type: it replaced the freshmen-only list, which showed historical records as freshmen.
 */
final class RegistrarStudentsEndpointTest extends TestCase
{
    use BuildsFreshmen;
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_registrar_staff_and_the_registrar_head_see_students_of_every_type(): void
    {
        $this->freshmanProfile('2026-10-00001', StudentType::Freshman);
        $this->freshmanProfile('HIST-2016-10806', StudentType::Freshman, 'irregular')->update(['year_level' => 4]);
        $this->freshmanProfile('2026-10-00003', StudentType::Transferee);
        $this->freshmanProfile('2020-00004', StudentType::ExistingStudent);

        foreach ([UserRole::RegistrarStaff, UserRole::RegistrarHead] as $role) {
            $token = $this->tokenFor($role, $role->value.'@grc.test');
            $this->app['auth']->forgetGuards();

            $response = $this->withToken($token)->getJson('/api/v1/students')->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonCount(4, 'data');

            $byNumber = collect($response->json('data'))->keyBy('student_number');
            self::assertSame('registrar_student', $byNumber['HIST-2016-10806']['type']);
            self::assertSame('BSIT', $byNumber['HIST-2016-10806']['program_code']);
            self::assertSame(4, $byNumber['HIST-2016-10806']['year_level']);
            self::assertSame('irregular', $byNumber['HIST-2016-10806']['enrollment_category']);
            self::assertSame('good', $byNumber['HIST-2016-10806']['academic_standing']);
            self::assertSame('Fresh Student 2020-00004', $byNumber['2020-00004']['name']);
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_the_list_can_be_searched_by_number_and_by_name_and_is_paginated_in_descending_number_order(): void
    {
        $this->freshmanProfile('2026-10-00002');
        $this->freshmanProfile('2026-10-00001');
        $this->freshmanProfile('2026-10-00003');
        $token = $this->tokenFor(UserRole::RegistrarStaff, 'registrar.staff@grc.test');

        $this->withToken($token)->getJson('/api/v1/students?search=2026-10-00001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_number', '2026-10-00001');
        $this->withToken($token)->getJson('/api/v1/students?search=Fresh Student 2026-10-00003')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_number', '2026-10-00003');

        $page = $this->withToken($token)->getJson('/api/v1/students?per_page=2')->assertOk();
        self::assertSame(['2026-10-00003', '2026-10-00002'], collect($page->json('data'))->pluck('student_number')->all());
        self::assertSame(3, $page->json('meta.total'));

        $nextPage = $this->withToken($token)->getJson('/api/v1/students?per_page=2&page=2')->assertOk();
        self::assertSame(['2026-10-00001'], collect($nextPage->json('data'))->pluck('student_number')->all());
        self::assertSame(2, $nextPage->json('meta.current_page'));
    }

    public function test_only_the_registrar_may_read_the_list(): void
    {
        $this->freshmanProfile('2026-10-00001');

        $this->getJson('/api/v1/students')->assertUnauthorized();

        foreach ([UserRole::AccountingStaff, UserRole::AdmissionStaff, UserRole::ProgramChair, UserRole::Student] as $role) {
            $token = $this->tokenFor($role, $role->value.'@grc.test');
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson('/api/v1/students')->assertForbidden();
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_the_page_size_is_capped(): void
    {
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar.head@grc.test');

        $this->withToken($token)->getJson('/api/v1/students?per_page=101')->assertUnprocessable();
    }

    private function tokenFor(UserRole $role, string $email): string
    {
        User::query()->where('email', $email)->first() ?? User::create([
            'name' => 'Test '.$role->value, 'email' => $email, 'password' => self::PASSWORD,
            'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => self::PASSWORD])->json('data.token');
    }
}
