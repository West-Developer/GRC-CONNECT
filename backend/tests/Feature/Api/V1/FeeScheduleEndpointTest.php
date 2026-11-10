<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\FeeSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FeeScheduleEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function tokenFor(UserRole $role, string $email): string
    {
        User::create([
            'name' => 'Test '.$role->value,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->json('data.token');
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/fee-schedules')->assertUnauthorized();
    }

    public function test_student_cannot_view_or_update_fee_schedules(): void
    {
        $token = $this->tokenFor(UserRole::Student, 'student.fee@grc.test');
        $this->withToken($token)->getJson('/api/v1/fee-schedules')->assertForbidden();
        $this->withToken($token)->putJson('/api/v1/fee-schedules', [
            'tuition_rate_per_unit' => '250.00',
            'miscellaneous_fees' => [],
        ])->assertForbidden();
    }

    public function test_accounting_staff_can_view_and_update_fee_schedules(): void
    {
        $token = $this->tokenFor(UserRole::AccountingStaff, 'acct.staff@grc.test');

        FeeSchedule::create([
            'category' => 'tuition',
            'label' => 'Tuition Rate Per Unit',
            'amount' => '200.00',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        FeeSchedule::create([
            'category' => 'miscellaneous',
            'label' => 'Registration',
            'amount' => '200.00',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/fee-schedules');
        $response->assertOk();
        $response->assertJsonCount(2, 'data');

        $updateResponse = $this->withToken($token)->putJson('/api/v1/fee-schedules', [
            'tuition_rate_per_unit' => '220.00',
            'miscellaneous_fees' => [
                ['label' => 'Registration', 'amount' => '250.00', 'is_active' => true],
                ['label' => 'Medical and Dental', 'amount' => '350.00', 'is_active' => true],
            ],
        ]);

        $updateResponse->assertOk();

        $this->assertDatabaseHas('fee_schedules', [
            'category' => 'tuition',
            'amount' => '220.00',
        ]);

        $this->assertDatabaseHas('fee_schedules', [
            'category' => 'miscellaneous',
            'label' => 'Medical and Dental',
            'amount' => '350.00',
        ]);
    }

    public function test_a_miscellaneous_fee_can_be_scoped_to_one_semester(): void
    {
        $token = $this->tokenFor(UserRole::AccountingStaff, 'acct.staff.semester@grc.test');

        $this->withToken($token)->putJson('/api/v1/fee-schedules', [
            'tuition_rate_per_unit' => '200.00',
            'miscellaneous_fees' => [
                ['label' => 'Registration', 'amount' => '200.00', 'is_active' => true],
                ['label' => 'Graduation Fee', 'amount' => '500.00', 'semester' => '2nd', 'is_active' => true],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('fee_schedules', ['label' => 'Registration', 'semester' => null]);
        $this->assertDatabaseHas('fee_schedules', ['label' => 'Graduation Fee', 'semester' => '2nd']);

        $response = $this->withToken($token)->getJson('/api/v1/fee-schedules')->assertOk();
        $graduationFee = collect($response->json('data'))->firstWhere('label', 'Graduation Fee');
        self::assertSame('2nd', $graduationFee['semester']);
    }

    public function test_an_invalid_semester_is_rejected(): void
    {
        $token = $this->tokenFor(UserRole::AccountingStaff, 'acct.staff.badsemester@grc.test');

        $response = $this->withToken($token)->putJson('/api/v1/fee-schedules', [
            'tuition_rate_per_unit' => '200.00',
            'miscellaneous_fees' => [
                ['label' => 'Registration', 'amount' => '200.00', 'semester' => 'summer', 'is_active' => true],
            ],
        ]);

        $response->assertUnprocessable();
        self::assertArrayHasKey('miscellaneous_fees.0.semester', $response->json('error.errors'));
    }

    public function test_registrar_head_can_view_fee_schedules_but_cannot_update_them(): void
    {
        $token = $this->tokenFor(UserRole::RegistrarHead, 'reg.head@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/fee-schedules');
        $response->assertOk();

        $updateResponse = $this->withToken($token)->putJson('/api/v1/fee-schedules', [
            'tuition_rate_per_unit' => '220.00',
            'miscellaneous_fees' => [],
        ]);

        $updateResponse->assertForbidden();
    }
}
